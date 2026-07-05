<?php

namespace App\Services;

use App\Support\TaskDoneDetector;
use Illuminate\Support\Facades\Cache;

class PollClickUpDoneTasks
{
    private const LAST_CHECK_CACHE_KEY = 'clickup_poll:last_check_ms';

    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly TaskDoneDetector $taskDoneDetector,
        private readonly UserNameResolver $userNameResolver,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    public function poll(): int
    {
        $sinceMs = $this->resolveSinceTimestampMs();
        $tasks = $this->clickUpClient->getTasksUpdatedSince($sinceMs);
        $notifiedCount = 0;

        foreach ($tasks as $task) {
            if (! is_array($task) || ! $this->taskDoneDetector->isApiTaskDone($task)) {
                continue;
            }

            $taskId = (string) ($task['id'] ?? '');

            if ($taskId === '') {
                continue;
            }

            $updatedAt = (string) ($task['date_updated'] ?? 'unknown');
            $cacheKey = "clickup_poll:notified:{$taskId}:{$updatedAt}";

            if (! Cache::add($cacheKey, true, now()->addDays(30))) {
                continue;
            }

            [$email, $username] = $this->resolveTaskUser($task);
            $displayName = $this->userNameResolver->resolve($email, $username);
            $fullTask = $this->clickUpClient->getTask($taskId);
            $taskName = (string) ($fullTask['name'] ?? $task['name'] ?? $taskId);
            $projectTag = $this->clickUpClient->resolveProjectTag($fullTask);
            $message = $this->telegramNotifier->formatMessage($displayName, $taskName, $projectTag);
            $media = $this->clickUpClient->extractMediaAttachments($fullTask['attachments'] ?? []);

            $this->telegramNotifier->send($message, $media);
            $notifiedCount++;
        }

        Cache::put(self::LAST_CHECK_CACHE_KEY, $this->currentTimestampMs(), now()->addDays(30));

        return $notifiedCount;
    }

    private function resolveSinceTimestampMs(): int
    {
        $lastCheck = Cache::get(self::LAST_CHECK_CACHE_KEY);

        if (is_numeric($lastCheck)) {
            return max(0, (int) $lastCheck - 30_000);
        }

        $lookbackMinutes = (int) config('clickup.poll_lookback_minutes', 30);

        return max(0, $this->currentTimestampMs() - ($lookbackMinutes * 60 * 1000));
    }

    /**
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

    private function currentTimestampMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
