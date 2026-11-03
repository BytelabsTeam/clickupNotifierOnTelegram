<?php

namespace App\Jobs;

use App\Models\TaskScore;
use App\Services\ClickUpClient;
use App\Services\TaskScoreSyncService;
use App\Services\TelegramNotifier;
use App\Services\UserNameResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class NotifyTaskDoneOnTelegram implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $taskId,
        public ?string $email,
        public ?string $username,
        public string $idempotencyKey,
    ) {
    }

    public function handle(
        ClickUpClient $clickUpClient,
        UserNameResolver $userNameResolver,
        TelegramNotifier $telegramNotifier,
        TaskScoreSyncService $taskScoreSyncService,
    ): void {
        $notifyCacheKey = 'clickup_webhook:'.$this->idempotencyKey;
        $alreadyNotified = Cache::has($notifyCacheKey);
        $alreadyScored = TaskScore::query()->where('task_id', $this->taskId)->exists();

        if ($alreadyNotified && $alreadyScored) {
            return;
        }

        $task = $clickUpClient->getTask($this->taskId);

        if (! $alreadyScored) {
            try {
                $taskScoreSyncService->scoreTaskIfNeeded($task, $this->email, $this->username);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($alreadyNotified) {
            return;
        }

        if (! Cache::add($notifyCacheKey, true, now()->addDays(7))) {
            return;
        }

        $displayName = $userNameResolver->resolve($this->email, $this->username);
        $taskName = (string) ($task['name'] ?? $this->taskId);
        $projectTag = $clickUpClient->resolveProjectTag($task);
        $message = $telegramNotifier->formatMessage($displayName, $taskName, $projectTag);
        $media = $clickUpClient->extractMediaAttachments($task['attachments'] ?? []);

        $telegramNotifier->send($message, $media);
    }
}
