<?php

namespace App\Jobs;

use App\Services\ClickUpClient;
use App\Services\TaskQualityGateService;
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
        TaskQualityGateService $taskQualityGateService,
    ): void {
        $cacheKey = 'clickup_webhook:'.$this->idempotencyKey;

        if (! Cache::add($cacheKey, true, now()->addDays(7))) {
            return;
        }

        $task = $clickUpClient->getTask($this->taskId);
        $taskQualityGateService->notifyOrGate($task, $this->email, $this->username);
    }
}
