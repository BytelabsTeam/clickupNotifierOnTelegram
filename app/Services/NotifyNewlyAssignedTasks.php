<?php

namespace App\Services;

use App\Support\TaskDoneDetector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class NotifyNewlyAssignedTasks
{
    private const LOOKBACK_HOURS = 1;

    private const NOTIFIED_CACHE_TTL_DAYS = 30;

    private const DESCRIPTION_MAX_LENGTH = 1500;

    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly TaskDoneDetector $taskDoneDetector,
        private readonly UserNameResolver $userNameResolver,
        private readonly TelegramUsernameResolver $telegramUsernameResolver,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    /**
     * @return array{sent: bool, notified: int}
     */
    public function notify(): array
    {
        $sinceMs = $this->lookbackSinceMs();
        $tasks = $this->clickUpClient->getTasksUpdatedSince($sinceMs);
        $notified = 0;

        foreach ($tasks as $task) {
            if (! is_array($task) || $this->taskDoneDetector->isApiTaskDone($task)) {
                continue;
            }

            $taskId = (string) ($task['id'] ?? '');

            if ($taskId === '') {
                continue;
            }

            $assignees = $task['assignees'] ?? [];

            if (! is_array($assignees) || $assignees === []) {
                continue;
            }

            $details = null;

            foreach ($assignees as $assignee) {
                if (! is_array($assignee)) {
                    continue;
                }

                $assigneeKey = $this->assigneeCacheKey($assignee);

                if ($assigneeKey === '') {
                    continue;
                }

                $cacheKey = "clickup_assigned:notified:{$taskId}:{$assigneeKey}";

                if (! Cache::add($cacheKey, true, now()->addDays(self::NOTIFIED_CACHE_TTL_DAYS))) {
                    continue;
                }

                $details ??= $this->buildTaskDetails($task);
                $message = $this->formatMessage($assignee, $details);
                $this->telegramNotifier->send($message, $details['media']);
                $notified++;
            }
        }

        return [
            'sent' => $notified > 0,
            'notified' => $notified,
        ];
    }

    private function lookbackSinceMs(): int
    {
        return max(0, Carbon::now()->subHours(self::LOOKBACK_HOURS)->getTimestampMs());
    }

    /**
     * @param  array<string, mixed>  $assignee
     */
    private function assigneeCacheKey(array $assignee): string
    {
        $id = isset($assignee['id']) ? (string) $assignee['id'] : '';

        if ($id !== '') {
            return $id;
        }

        $email = isset($assignee['email']) ? (string) $assignee['email'] : '';

        if ($email !== '') {
            return $email;
        }

        return isset($assignee['username']) ? (string) $assignee['username'] : '';
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{
     *     name: string,
     *     status: string,
     *     priority: string,
     *     due_date: string,
     *     tag: string,
     *     url: string,
     *     description: string,
     *     media: list<array{url: string, type: string}>
     * }
     */
    private function buildTaskDetails(array $task): array
    {
        $taskId = (string) ($task['id'] ?? '');
        $fullTask = $taskId !== '' ? $this->clickUpClient->getTask($taskId) : $task;

        if ($fullTask === []) {
            $fullTask = $task;
        }

        $description = trim((string) ($fullTask['text_content'] ?? $fullTask['description'] ?? ''));

        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            $description = mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH).'…';
        }

        return [
            'name' => trim((string) ($fullTask['name'] ?? $task['name'] ?? $taskId)),
            'status' => $this->formatStatus($fullTask),
            'priority' => $this->formatPriority($fullTask),
            'due_date' => $this->formatDueDate($fullTask),
            'tag' => $this->clickUpClient->resolveProjectTag($fullTask),
            'url' => trim((string) ($fullTask['url'] ?? '')),
            'description' => $description,
            'media' => $this->clickUpClient->extractMediaAttachments($fullTask['attachments'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $assignee
     * @param  array{
     *     name: string,
     *     status: string,
     *     priority: string,
     *     due_date: string,
     *     tag: string,
     *     url: string,
     *     description: string,
     *     media: list<array{url: string, type: string}>
     * }  $details
     */
    private function formatMessage(array $assignee, array $details): string
    {
        $email = isset($assignee['email']) ? (string) $assignee['email'] : null;
        $username = isset($assignee['username']) ? (string) $assignee['username'] : null;
        $displayName = $this->userNameResolver->resolve($email, $username);
        $taggedName = $this->telegramUsernameResolver->formatDisplayName($displayName, $email);

        $lines = [
            "تسک جدید به {$taggedName} assign شد:",
            '',
            'عنوان: '.$details['name'],
            'وضعیت: '.$details['status'],
            'اولویت: '.$details['priority'],
            'ددلاین: '.$details['due_date'],
        ];

        if ($details['tag'] !== '') {
            $lines[] = 'پروژه: '.$details['tag'];
        }

        if ($details['url'] !== '') {
            $lines[] = 'لینک: '.$details['url'];
        }

        if ($details['description'] !== '') {
            $lines[] = '';
            $lines[] = 'توضیحات:';
            $lines[] = $details['description'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function formatStatus(array $task): string
    {
        $status = $task['status'] ?? null;

        if (! is_array($status)) {
            return '—';
        }

        $name = trim((string) ($status['status'] ?? ''));

        return $name !== '' ? $name : '—';
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function formatPriority(array $task): string
    {
        $priority = $task['priority'] ?? null;

        if (! is_array($priority)) {
            return '—';
        }

        $label = mb_strtolower(trim((string) ($priority['priority'] ?? '')));

        return match ($label) {
            'urgent' => 'فوری',
            'high' => 'بالا',
            'normal' => 'عادی',
            'low' => 'پایین',
            default => $label !== '' ? $label : '—',
        };
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function formatDueDate(array $task): string
    {
        $dueDate = $task['due_date'] ?? null;

        if (! is_numeric($dueDate)) {
            return '—';
        }

        return Carbon::createFromTimestampMs((int) $dueDate)
            ->timezone(config('app.timezone'))
            ->format('Y-m-d H:i');
    }
}
