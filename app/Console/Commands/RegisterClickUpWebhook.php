<?php

namespace App\Console\Commands;

use App\Services\ClickUpClient;
use Illuminate\Console\Command;

class RegisterClickUpWebhook extends Command
{
    protected $signature = 'clickup:register-webhook';

    protected $description = 'Register a ClickUp webhook for taskStatusUpdated events';

    public function handle(ClickUpClient $clickUpClient): int
    {
        $response = $clickUpClient->registerWebhook();
        $webhook = $response['webhook'] ?? $response;
        $secret = $webhook['secret'] ?? null;
        $webhookId = $webhook['id'] ?? ($response['id'] ?? null);

        $this->info('ClickUp webhook registered successfully.');

        if ($webhookId !== null) {
            $this->line("Webhook ID: {$webhookId}");
        }

        if (is_string($secret) && $secret !== '') {
            $this->newLine();
            $this->warn('Add this value to your .env file:');
            $this->line("CLICKUP_WEBHOOK_SECRET={$secret}");
        } else {
            $this->warn('No webhook secret was returned. Check the API response manually.');
        }

        return self::SUCCESS;
    }
}
