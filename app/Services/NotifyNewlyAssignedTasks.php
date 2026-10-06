<?php

namespace App\Services;

use App\Support\TaskDoneDetector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class NotifyNewlyAssignedTasks
{
    private const LOOKBACK_HOURS = 1;

    private const NOTIFIED_CACHE_TTL_DAYS = 30;

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
     *     creator: string,
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

        return [
            'name' => trim((string) ($fullTask['name'] ?? $task['name'] ?? $taskId)),
            'creator' => $this->creatorName($fullTask, $task),
            'media' => $this->clickUpClient->extractMediaAttachments($fullTask['attachments'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $fullTask
     * @param  array<string, mixed>  $task
     */
    private function creatorName(array $fullTask, array $task): string
    {
        $creator = is_array($fullTask['creator'] ?? null) ? $fullTask['creator'] : null;
        $creator ??= is_array($task['creator'] ?? null) ? $task['creator'] : null;

        if (! is_array($creator)) {
            return $this->userNameResolver->resolve(null, null);
        }

        return $this->userNameResolver->resolve(
            isset($creator['email']) ? (string) $creator['email'] : null,
            isset($creator['username']) ? (string) $creator['username'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $assignee
     * @param  array{
     *     name: string,
     *     creator: string,
     *     media: list<array{url: string, type: string}>
     * }  $details
     */
    private function formatMessage(array $assignee, array $details): string
    {
        $email = isset($assignee['email']) ? (string) $assignee['email'] : null;
        $username = isset($assignee['username']) ? (string) $assignee['username'] : null;
        $displayName = $this->userNameResolver->resolve($email, $username);
        $mention = $this->telegramUsernameResolver->formatMention($displayName, $email);

        return implode("\n", [
            $details['name'],
            $mention,
            '',
            '📝'.$details['creator'],
        ]);
    }
}
