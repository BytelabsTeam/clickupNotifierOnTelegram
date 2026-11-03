<?php

namespace App\Services;

use App\Models\TaskScore;
use App\Support\JalaliCalendar;
use App\Support\TaskDoneDetector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ScoreOverrideService
{
    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly TaskDoneDetector $taskDoneDetector,
        private readonly ScoreIdentityResolver $scoreIdentityResolver,
        private readonly TelegramUsernameResolver $telegramUsernameResolver,
    ) {
    }

    public function fixScore(string $taskId, int $score, ?string $reason, string $adminUsername): string
    {
        $this->assertAdmin($adminUsername);

        $score = max(0, min(5000, $score));
        $row = $this->upsertTaskScore($taskId, [
            'is_code' => false,
            'score' => $score,
            'reason' => $this->overrideReason($reason, $adminUsername, "fixscore={$score}"),
        ]);

        Log::info('Task score overridden', [
            'task_id' => $taskId,
            'score' => $score,
            'admin' => $adminUsername,
        ]);

        return "✅ امتیاز تسک `{$taskId}` روی ".number_format($score)." تنظیم شد"
            ."\nعنوان: {$row->task_name}"
            ."\nوضعیت: غیرکدی (manual override)";
    }

    public function uncode(string $taskId, ?int $score, ?string $reason, string $adminUsername): string
    {
        $this->assertAdmin($adminUsername);

        $existing = TaskScore::query()->where('task_id', $taskId)->first();
        $finalScore = $score;

        if ($finalScore === null) {
            $finalScore = $existing !== null ? (int) $existing->score : null;
        }

        if ($finalScore === null) {
            return "برای `{$taskId}` امتیاز فعلی نیست. بزن:\n/uncode {$taskId} 1200 دلیل";
        }

        $finalScore = max(0, min(5000, $finalScore));

        $row = $this->upsertTaskScore($taskId, [
            'is_code' => false,
            'score' => $finalScore,
            'reason' => $this->overrideReason($reason, $adminUsername, 'uncode'),
        ]);

        return "✅ تسک `{$taskId}` غیرکدی شد — امتیاز ".number_format($finalScore)
            ."\nعنوان: {$row->task_name}";
    }

    public function markCode(string $taskId, string $adminUsername): string
    {
        $this->assertAdmin($adminUsername);

        $row = $this->upsertTaskScore($taskId, [
            'is_code' => true,
            'score' => 0,
            'reason' => $this->overrideReason(null, $adminUsername, 'markcode'),
        ]);

        return "✅ تسک `{$taskId}` کدی علامت خورد (امتیاز ۰ — پوشش GitHub)"
            ."\nعنوان: {$row->task_name}";
    }

    /**
     * @param  array{is_code: bool, score: int, reason: string}  $overrides
     */
    private function upsertTaskScore(string $taskId, array $overrides): TaskScore
    {
        $taskId = trim($taskId);

        if ($taskId === '') {
            throw new \InvalidArgumentException('task_id خالی است.');
        }

        $existing = TaskScore::query()->where('task_id', $taskId)->first();
        $task = null;

        try {
            $task = $this->clickUpClient->getTask($taskId);
        } catch (\Throwable $exception) {
            if ($existing === null) {
                throw new \RuntimeException(
                    "تسک `{$taskId}` در دیتابیس نیست و از ClickUp هم خوانده نشد: ".$exception->getMessage()
                );
            }
        }

        $email = $existing?->email;
        $username = $existing?->username;
        $taskName = $existing?->task_name ?? $taskId;
        $description = $existing?->task_description;
        $completedAt = $existing?->completed_at;

        if (is_array($task) && $task !== []) {
            $taskName = trim((string) ($task['name'] ?? $taskName));
            $description = $this->plainDescription($task) ?: $description;
            [$resolvedEmail, $resolvedUsername] = $this->resolveTaskUser($task);
            $email ??= $resolvedEmail;
            $username ??= $resolvedUsername;
            $completedAt ??= $this->resolveCompletedAt($task);

            if (! $this->taskDoneDetector->isApiTaskDone($task) && $existing === null) {
                // Still allow admin override for open tasks if they insist.
            }
        }

        $canonicalEmail = $email !== null && $email !== ''
            ? $this->scoreIdentityResolver->canonicalizeEmail($email)
            : null;

        $completedAt ??= now(JalaliCalendar::timezone());

        return TaskScore::query()->updateOrCreate(
            ['task_id' => $taskId],
            [
                'email' => $canonicalEmail,
                'username' => $username,
                'name' => $username,
                'task_name' => mb_substr($taskName, 0, 500),
                'task_description' => $description !== null && $description !== ''
                    ? mb_substr($description, 0, 5000)
                    : null,
                'is_code' => $overrides['is_code'],
                'score' => $overrides['score'],
                'reason' => mb_substr($overrides['reason'], 0, 500),
                'completed_at' => $completedAt,
                'month' => JalaliCalendar::monthKey(
                    $completedAt instanceof Carbon
                        ? $completedAt
                        : Carbon::parse((string) $completedAt)
                ),
            ]
        );
    }

    private function assertAdmin(string $adminUsername): void
    {
        if (! $this->telegramUsernameResolver->isAdmin($adminUsername)) {
            throw new \RuntimeException('فقط ادمین می‌تواند امتیاز را دستی تغییر دهد.');
        }
    }

    private function overrideReason(?string $reason, string $adminUsername, string $action): string
    {
        $base = trim((string) $reason);
        $meta = "[override:@{$adminUsername}/{$action}]";

        if ($base === '') {
            return $meta;
        }

        return mb_substr("{$base} {$meta}", 0, 500);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveTaskUser(array $task): array
    {
        $assignees = $task['assignees'] ?? [];

        if (is_array($assignees) && isset($assignees[0]) && is_array($assignees[0])) {
            return [
                isset($assignees[0]['email']) ? (string) $assignees[0]['email'] : null,
                isset($assignees[0]['username']) ? (string) $assignees[0]['username'] : null,
            ];
        }

        $creator = $task['creator'] ?? null;

        if (is_array($creator)) {
            return [
                isset($creator['email']) ? (string) $creator['email'] : null,
                isset($creator['username']) ? (string) $creator['username'] : null,
            ];
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function plainDescription(array $task): string
    {
        $text = (string) ($task['description'] ?? $task['text_content'] ?? '');
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function resolveCompletedAt(array $task): ?Carbon
    {
        foreach (['date_done', 'date_closed', 'date_updated'] as $field) {
            $value = $task[$field] ?? null;

            if (is_numeric($value) && (int) $value > 0) {
                return Carbon::createFromTimestampMs((int) $value);
            }
        }

        return null;
    }
}
