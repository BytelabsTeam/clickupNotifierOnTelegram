<?php

namespace App\Services;

use App\Models\TeamTask;
use App\Support\JalaliCalendar;

/**
 * Inline-button menu for a member's own non-code tasks.
 * Every action is scoped to the requesting member's telegram id.
 */
class TaskMenuService
{
    public const CALLBACK_PREFIX = 'task:';

    public function __construct(private readonly TeamTaskService $tasks)
    {
    }

    /**
     * @param  array{id: int|string, username?: string, first_name?: string}  $actor
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function menuFor(array $actor): array
    {
        $telegramId = (int) ($actor['id'] ?? 0);
        $tasks = TeamTask::query()
            ->where('assignee_telegram_id', $telegramId)
            ->where('status', '!=', 'done')
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 ELSE 1 END")
            ->orderBy('due_at')
            ->limit(15)
            ->get();

        if ($tasks->isEmpty()) {
            return [
                'text' => 'تسک بازی برای تو نیست. ✅',
                'keyboard' => [],
            ];
        }

        $lines = ['📋 تسک‌های باز تو:', ''];
        $keyboard = [];

        foreach ($tasks as $task) {
            $status = $task->status === 'in_progress' ? '🔄' : '⏳';
            $due = $task->due_at ? ' | مهلت '.$task->due_at->timezone(JalaliCalendar::timezone())->format('Y-m-d') : '';
            $lines[] = "{$status} #{$task->id} {$task->title}{$due}";

            $row = [];

            if ($task->status === 'todo') {
                $row[] = ['text' => '▶️ شروع #'.$task->id, 'callback_data' => self::CALLBACK_PREFIX.'start:'.$task->id];
            }

            $row[] = ['text' => '✅ انجام #'.$task->id, 'callback_data' => self::CALLBACK_PREFIX.'done:'.$task->id];
            $keyboard[] = $row;
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Apply a button action to a task owned by the actor.
     *
     * @param  array{id: int|string}  $actor
     * @return array{toast: string, changed: bool}
     */
    public function handleAction(array $actor, string $callbackData): array
    {
        if (preg_match('/^'.preg_quote(self::CALLBACK_PREFIX, '/').'(start|done):(\d+)$/', $callbackData, $matches) !== 1) {
            return ['toast' => 'دکمه نامعتبر است.', 'changed' => false];
        }

        $status = $matches[1] === 'start' ? 'doing' : 'done';

        try {
            $message = $this->tasks->setStatus($actor, false, (int) $matches[2], match ($status) {
                'doing' => 'in_progress',
                default => 'done',
            });

            return ['toast' => $message, 'changed' => true];
        } catch (\Throwable $exception) {
            report($exception);

            return ['toast' => 'انجام نشد: '.mb_substr($exception->getMessage(), 0, 150), 'changed' => false];
        }
    }
}
