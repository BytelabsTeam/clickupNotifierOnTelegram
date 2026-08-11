<?php

namespace App\Console\Commands;

use App\Services\NotifyTomorrowTasks;
use Illuminate\Console\Command;

class NotifyTomorrowTasksCommand extends Command
{
    protected $signature = 'clickup:notify-tomorrow';

    protected $description = 'Send tomorrow\'s ClickUp tasks (by priority) to the Telegram group';

    public function handle(NotifyTomorrowTasks $notifier): int
    {
        $result = $notifier->notify();

        if (! $result['sent']) {
            $this->info('No open tasks due tomorrow.');

            return self::SUCCESS;
        }

        $this->info("Sent tomorrow's digest: {$result['tasks']} task(s) for {$result['people']} person(s).");

        return self::SUCCESS;
    }
}
