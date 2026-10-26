<?php

namespace App\Console\Commands;

use App\Services\TaskScoreSyncService;
use Illuminate\Console\Command;

class ScoreClickUpTasks extends Command
{
    protected $signature = 'clickup:score-tasks';

    protected $description = 'Score new current-month non-code ClickUp done tasks with IranRouter LLM';

    public function handle(TaskScoreSyncService $sync): int
    {
        try {
            $result = $sync->syncCurrentMonth();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scored %d non-code task(s). Skipped %d code task(s). Errors: %d.',
            $result['scored'],
            $result['skipped_code'],
            count($result['skipped'])
        ));

        return self::SUCCESS;
    }
}
