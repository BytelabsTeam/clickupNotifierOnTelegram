<?php

namespace App\Services;

use App\Models\TaskScore;
use App\Support\TaskDoneDetector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskScoreSyncService
{
    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly TaskDoneDetector $taskDoneDetector,
        private readonly TaskLlmScorer $taskLlmScorer,
        private readonly ScoreIdentityResolver $scoreIdentityResolver,
    ) {
    }

    /**
     * Score done non-code tasks for the current month that are not stored yet.
     *
     * @return array{scored: int, skipped_code: int, skipped: list<array{task: string, reason: string}>}
     */
    public function syncCurrentMonth(?Carbon $now = null): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now ??= Carbon::now($timezone);
        $from = $now->copy()->timezone($timezone)->startOfMonth();
        $to = $now->copy()->timezone($timezone)->endOfMonth();
        $month = $from->format('Y-m');

        try {
            app(IranRouterClient::class)->requireApiKey();
            $this->clickUpClient->requireConfigured();
        } catch (\RuntimeException $exception) {
            Log::warning('Task score sync skipped: '.$exception->getMessage());

            return ['scored' => 0, 'skipped_code' => 0, 'skipped' => []];
        }

        $lock = Cache::lock('clickup:task-score-sync:'.$month, 300);

        try {
            if (! $lock->block(180)) {
                return ['scored' => 0, 'skipped_code' => 0, 'skipped' => []];
            }
        } catch (Throwable $exception) {
            Log::warning('Task score sync lock failed: '.$exception->getMessage());
        }

        try {
            return $this->syncLocked($from, $to, $month, $timezone);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Score a single done task immediately (poll/webhook). Idempotent by task_id.
     *
     * @param  array<string, mixed>  $task
     */
    public function scoreTaskIfNeeded(array $task, ?string $email = null, ?string $username = null): ?TaskScore
    {
        $taskId = (string) ($task['id'] ?? '');

        if ($taskId === '') {
            return null;
        }

        if (TaskScore::query()->where('task_id', $taskId)->exists()) {
            return TaskScore::query()->where('task_id', $taskId)->first();
        }

        if (! $this->taskDoneDetector->isApiTaskDone($task)) {
            return null;
        }

        try {
            app(IranRouterClient::class)->requireApiKey();
        } catch (\RuntimeException) {
            return null;
        }

        return $this->evaluateAndStore($task, $email, $username);
    }

    /**
     * @return array{scored: int, skipped_code: int, skipped: list<array{task: string, reason: string}>}
     */
    private function syncLocked(Carbon $from, Carbon $to, string $month, string $timezone): array
    {
        $tasks = $this->clickUpClient->getClosedTasksDoneBetween(
            $from->getTimestampMs(),
            $to->copy()->addSecond()->getTimestampMs()
        );

        $existing = TaskScore::query()
            ->where('month', $month)
            ->pluck('task_id')
            ->flip()
            ->all();

        $scored = 0;
        $skippedCode = 0;
        $skipped = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $taskId = (string) ($task['id'] ?? '');

            if ($taskId === '' || isset($existing[$taskId])) {
                continue;
            }

            if (! $this->taskDoneDetector->isApiTaskDone($task)) {
                continue;
            }

            try {
                $row = $this->evaluateAndStore($task);

                if ($row === null) {
                    continue;
                }

                $existing[$taskId] = true;

                if ($row->is_code) {
                    $skippedCode++;
                } elseif ($row->score > 0) {
                    $scored++;
                }
            } catch (Throwable $exception) {
                Log::warning('Task score failed for '.$taskId.': '.$exception->getMessage());
                $skipped[] = [
                    'task' => $taskId,
                    'reason' => mb_substr($exception->getMessage(), 0, 120),
                ];
            }
        }

        Log::info('Task score sync finished', [
            'month' => $month,
            'scored' => $scored,
            'skipped_code' => $skippedCode,
            'skipped' => count($skipped),
        ]);

        return [
            'scored' => $scored,
            'skipped_code' => $skippedCode,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function evaluateAndStore(array $task, ?string $email = null, ?string $username = null): ?TaskScore
    {
        $taskId = (string) ($task['id'] ?? '');

        if ($taskId === '') {
            return null;
        }

        if ($email === null || $username === null) {
            [$resolvedEmail, $resolvedUsername] = $this->resolveTaskUser($task);
            $email ??= $resolvedEmail;
            $username ??= $resolvedUsername;
        }

        $taskName = trim((string) ($task['name'] ?? $taskId));
        $description = $this->plainDescription($task);
        $projectContext = $this->clickUpClient->resolveProjectTag($task);

        $completedAt = $this->resolveCompletedAt($task);
        $timezone = (string) config('app.timezone', 'UTC');
        $month = ($completedAt ?? now($timezone))->copy()->timezone($timezone)->format('Y-m');

        $result = $this->taskLlmScorer->score($taskName, $description, $projectContext);

        $canonicalEmail = $email !== null && $email !== ''
            ? $this->scoreIdentityResolver->canonicalizeEmail($email)
            : null;

        return TaskScore::query()->updateOrCreate(
            ['task_id' => $taskId],
            [
                'email' => $canonicalEmail,
                'username' => $username,
                'name' => $username,
                'task_name' => mb_substr($taskName, 0, 500),
                'task_description' => $description !== '' ? mb_substr($description, 0, 5000) : null,
                'is_code' => $result['is_code'],
                'score' => $result['score'],
                'reason' => $result['reason'] !== '' ? $result['reason'] : null,
                'completed_at' => $completedAt,
                'month' => $month,
            ]
        );
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
