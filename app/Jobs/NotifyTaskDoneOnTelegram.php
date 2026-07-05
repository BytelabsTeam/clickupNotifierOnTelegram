<?php

namespace App\Jobs;

use App\Services\ClickUpClient;
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
    ): void {
        $cacheKey = 'clickup_webhook:'.$this->idempotencyKey;

        if (! Cache::add($cacheKey, true, now()->addDays(7))) {
            return;
        }

        $displayName = $userNameResolver->resolve($this->email, $this->username);
        $task = $clickUpClient->getTask($this->taskId);
        $taskName = (string) ($task['name'] ?? $this->taskId);
        $message = $telegramNotifier->formatMessage($displayName, $taskName);
        $media = $clickUpClient->extractMediaAttachments($task['attachments'] ?? []);

        $telegramNotifier->send($message, $media);
    }
}
