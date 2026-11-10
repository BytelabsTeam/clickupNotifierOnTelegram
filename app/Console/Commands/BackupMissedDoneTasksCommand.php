<?php

namespace App\Console\Commands;

use App\Services\BackupMissedDoneTasks;
use Illuminate\Console\Command;

class BackupMissedDoneTasksCommand extends Command
{
    protected $signature = 'clickup:backup-missed-tasks';

    protected $description = 'Backfill unscored ClickUp tasks and GitHub commits from the last week, one day/batch at a time';

    public function handle(BackupMissedDoneTasks $backup): int
    {
        try {
            $result = $backup->run();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $clickup = $result['clickup'];
        $github = $result['github'];

        $this->info(sprintf(
            'ClickUp day: %s | registered: %d | notified: %d | remaining days/tasks: %d/%d',
            $clickup['day'] ?? '—',
            $clickup['registered'],
            $clickup['notified'],
            $clickup['remaining_days'],
            $clickup['remaining_tasks']
        ));

        $this->info(sprintf(
            'GitHub day: %s | registered: %d | notified: %d | remaining days/commits: %d/%d',
            $github['day'] ?? '—',
            $github['registered'],
            $github['notified'],
            $github['remaining_days'],
            $github['remaining_commits']
        ));

        return self::SUCCESS;
    }
}
