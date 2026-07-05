<?php

namespace App\Console\Commands;

use App\Services\PollClickUpDoneTasks as PollClickUpDoneTasksService;
use Illuminate\Console\Command;

class PollClickUpDoneTasks extends Command
{
    protected $signature = 'clickup:poll
                            {--loop : Keep polling continuously}
                            {--interval= : Seconds between polls when using --loop}';

    protected $description = 'Poll ClickUp for recently completed tasks and notify Telegram';

    public function handle(PollClickUpDoneTasksService $poller): int
    {
        $interval = (int) ($this->option('interval') ?: config('clickup.poll_interval_seconds', 10));

        if ($interval < 1) {
            $this->error('Interval must be at least 1 second.');

            return self::FAILURE;
        }

        do {
            try {
                $count = $poller->poll();
                $this->line(sprintf('[%s] Notified %d task(s).', now()->toDateTimeString(), $count));
            } catch (\Throwable $exception) {
                $this->error(sprintf('[%s] Poll failed: %s', now()->toDateTimeString(), $exception->getMessage()));
            }

            if (! $this->option('loop')) {
                break;
            }

            sleep($interval);
        } while (true);

        return self::SUCCESS;
    }
}
