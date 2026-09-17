<?php

namespace App\Console\Commands;

use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

class RegisterTelegramWebhook extends Command
{
    protected $signature = 'telegram:register-webhook';

    protected $description = 'Register the Telegram bot webhook and command menu';

    public function handle(TelegramNotifier $telegram): int
    {
        $result = $telegram->registerBotWebhook();

        $this->info('Telegram webhook registered successfully.');
        $this->line('URL: '.$result['url']);

        return self::SUCCESS;
    }
}
