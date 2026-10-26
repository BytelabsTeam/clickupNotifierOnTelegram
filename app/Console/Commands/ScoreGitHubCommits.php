<?php

namespace App\Console\Commands;

use App\Services\CommitScoreSyncService;
use Illuminate\Console\Command;

class ScoreGitHubCommits extends Command
{
    protected $signature = 'github:score-commits';

    protected $description = 'Score new current-month GitHub commits with IranRouter LLM and store results';

    public function handle(CommitScoreSyncService $sync): int
    {
        try {
            $result = $sync->syncCurrentMonth();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scored %d new commit(s). Skipped %d repo/commit error(s).',
            $result['scored'],
            count($result['skipped'])
        ));

        foreach ($result['skipped'] as $item) {
            $repo = (string) ($item['repo'] ?? '');
            $reason = (string) ($item['reason'] ?? '');
            $this->line($reason !== '' ? " - {$repo}: {$reason}" : " - {$repo}");
        }

        return self::SUCCESS;
    }
}
