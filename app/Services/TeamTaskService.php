<?php

namespace App\Services;

use App\Models\TeamTask;
use App\Support\JalaliCalendar;
use Carbon\Carbon;

class TeamTaskService
{
    public function __construct(private readonly TaskLlmScorer $scorer)
    {
    }

    /** @param array<string, mixed> $actor */
    public function create(array $actor, string $title, string $description = '', ?string $dueDate = null): TeamTask
    {
        $id = (int) ($actor['id'] ?? 0);

        if ($id <= 0 || trim($title) === '') {
            throw new \InvalidArgumentException('عنوان تسک و شناسه تلگرام معتبر لازم است.');
        }

        $dueAt = $dueDate !== null && trim($dueDate) !== ''
            ? Carbon::parse($dueDate, JalaliCalendar::timezone())->endOfDay()
            : null;

        return TeamTask::query()->create([
            'title' => mb_substr(trim($title), 0, 500),
            'description' => $description !== '' ? mb_substr(trim($description), 0, 5000) : null,
            'status' => 'todo',
            'priority' => 'normal',
            'assignee_telegram_id' => $id,
            'assignee_username' => $this->username($actor),
            'assignee_name' => $this->displayName($actor),
            'created_by_telegram_id' => $id,
            'due_at' => $dueAt,
        ]);
    }

    /** @param array<string, mixed> $actor */
    public function listFor(array $actor, bool $admin, string $filter = 'open'): string
    {
        $query = TeamTask::query()->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'todo' THEN 1 ELSE 2 END")
            ->orderBy('due_at')->orderByDesc('created_at');

        if (! $admin) {
            $query->where('assignee_telegram_id', (int) ($actor['id'] ?? 0));
        }

        match ($filter) {
            'today' => $query->whereDate('due_at', Carbon::today(JalaliCalendar::timezone()))->where('status', '!=', 'done'),
            'overdue' => $query->whereNotNull('due_at')->where('due_at', '<', now())->where('status', '!=', 'done'),
            'all' => null,
            default => $query->where('status', '!=', 'done'),
        };

        $tasks = $query->limit(50)->get();

        if ($tasks->isEmpty()) {
            return 'تسکی برای نمایش نیست.';
        }

        $lines = [$admin ? 'تسک‌های تیم:' : 'تسک‌های من:'];

        foreach ($tasks as $task) {
            $status = match ($task->status) {
                'in_progress' => 'در حال انجام',
                'done' => 'انجام‌شده',
                default => 'برای انجام',
            };
            $due = $task->due_at ? ' | مهلت '.$task->due_at->timezone(JalaliCalendar::timezone())->format('Y-m-d') : '';
            $owner = $admin ? ' | '.($task->assignee_name ?: '@'.$task->assignee_username) : '';
            $lines[] = "#{$task->id} [{$status}] {$task->title}{$owner}{$due}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $actor */
    public function setStatus(array $actor, bool $admin, int $taskId, string $status): string
    {
        if (! in_array($status, ['todo', 'in_progress', 'done'], true)) {
            throw new \InvalidArgumentException('وضعیت معتبر نیست. از todo، doing یا done استفاده کن.');
        }

        $task = $this->findAccessible($actor, $admin, $taskId);

        if ($task === null) {
            return 'تسک پیدا نشد یا اجازه تغییرش را نداری.';
        }

        $task->status = $status;

        if ($status === 'done' && $task->completed_at === null) {
            $task->completed_at = now(JalaliCalendar::timezone());
        } elseif ($status !== 'done') {
            $task->completed_at = null;
            $task->is_code = false;
            $task->score = 0;
            $task->score_reason = null;
            $task->scored_at = null;
        }

        if ($status === 'done') {
            $result = $this->scorer->score($task->title, (string) $task->description);

            if (in_array($result['reason'], ['parse_failed', 'invalid_score'], true)) {
                throw new \RuntimeException('پاسخ امتیازدهی قابل استفاده نبود؛ وضعیت تسک ذخیره نشد، دوباره تلاش کن.');
            }

            $task->is_code = $result['is_code'];
            $task->score = $result['score'];
            $task->score_reason = $result['reason'];
            $task->scored_at = now();
        }

        $task->save();

        return $status === 'done'
            ? "✅ تسک #{$task->id} انجام‌شده ثبت شد. امتیاز: ".number_format((int) $task->score).($task->is_code ? ' (کدی؛ امتیاز GitHub)' : '')
            : "وضعیت تسک #{$task->id} به ".($status === 'todo' ? 'برای انجام' : 'در حال انجام')." تغییر کرد.";
    }

    /** @param array<string, mixed> $actor */
    public function assign(int $taskId, array $member): string
    {
        $task = TeamTask::query()->find($taskId);

        if ($task === null) {
            return 'تسک پیدا نشد.';
        }

        $telegramId = (int) ($member['id'] ?? 0);

        if ($telegramId <= 0) {
            throw new \InvalidArgumentException('عضو باید حداقل یک‌بار به ربات پیام داده باشد.');
        }

        $task->update([
            'assignee_telegram_id' => $telegramId,
            'assignee_username' => $this->username($member),
            'assignee_name' => $this->displayName($member),
        ]);

        return "تسک #{$task->id} به ".$this->displayName($member).' واگذار شد.';
    }

    /** @param array<string, mixed> $actor */
    private function findAccessible(array $actor, bool $admin, int $id): ?TeamTask
    {
        return TeamTask::query()->when(! $admin, fn ($query) => $query->where('assignee_telegram_id', (int) ($actor['id'] ?? 0)))
            ->find($id);
    }

    /** @param array<string, mixed> $user */
    private function username(array $user): ?string
    {
        $username = trim((string) ($user['username'] ?? ''));

        return $username !== '' ? mb_substr($username, 0, 255) : null;
    }

    /** @param array<string, mixed> $user */
    private function displayName(array $user): string
    {
        return mb_substr(trim(implode(' ', array_filter([
            (string) ($user['first_name'] ?? ''),
            (string) ($user['last_name'] ?? ''),
        ]))) ?: ($this->username($user) ? '@'.$this->username($user) : 'عضو تیم'), 0, 255);
    }
}
