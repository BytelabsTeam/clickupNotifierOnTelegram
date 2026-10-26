<?php

namespace App\Jobs;

use App\Services\TelegramBotHandler;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs after the webhook HTTP response is sent so Telegram does not retry
 * while LLM scoring is still in progress.
 */
class FinishTelegramLeaderboard
{
    use Dispatchable;

    public function __construct(
        public string $command,
        public string $fromUsername,
        public string|int $chatId,
        public ?int $progressId,
        public string|int|null $threadId,
    ) {
    }

    public function handle(TelegramBotHandler $handler): void
    {
        $handler->completeLeaderboard(
            $this->command,
            $this->fromUsername,
            $this->chatId,
            $this->progressId,
            $this->threadId,
        );
    }
}
