<?php

namespace App\Jobs;

use App\Services\TelegramBotHandler;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs after the webhook HTTP response is sent so Telegram does not retry
 * while the zip download and overlay are still in progress.
 */
class FinishSelfDeploy
{
    use Dispatchable;

    public function __construct(
        public string|int $chatId,
        public ?int $progressMessageId,
    ) {
    }

    public function handle(TelegramBotHandler $handler): void
    {
        $handler->completeDeploy($this->chatId, $this->progressMessageId);
    }
}
