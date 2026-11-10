<?php

namespace App\Console\Commands;

use App\Services\BackupMissedDoneTasks;
use Illuminate\Console\Command;

class BackupMissedDoneTasksCommand extends Command
{
    protected $signature = 'clickup:backup-missed-tasks';

    protected $description = 'Backfill unscored done ClickUp tasks from the last week, one day/batch at a time';

    public function handle(BackupMissedDoneTasks $backup): int
    {
        try {
            $result = $backup->run();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Day: %s | fetched: %d | queued: %d | registered: %d | notified: %d | remaining days: %d | remaining tasks: %d',
            $result['day'] ?? '—',
            $result['fetched'],
            $result['queued'],
            $result['registered'],
            $result['notified'],
            $result['remaining_days'],
            $result['remaining_tasks']
        ));

        return self::SUCCESS;
    }
}
