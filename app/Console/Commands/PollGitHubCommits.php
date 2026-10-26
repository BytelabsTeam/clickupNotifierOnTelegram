<?php

namespace App\Console\Commands;

use App\Services\PollGitHubCommits as PollGitHubCommitsService;
use Illuminate\Console\Command;

class PollGitHubCommits extends Command
{
    protected $signature = 'github:poll-commits
                            {--loop : Keep polling continuously}
                            {--interval= : Seconds between polls when using --loop}';

    protected $description = 'Poll GitHub for new commits, translate messages to Persian, and notify Telegram';

    public function handle(PollGitHubCommitsService $poller): int
    {
        $interval = (int) ($this->option('interval') ?: config('github.commit_poll_interval_seconds', 60));

        if ($interval < 1) {
            $this->error('Interval must be at least 1 second.');

            return self::FAILURE;
        }

        do {
            try {
                $count = $poller->poll();
                $this->line(sprintf('[%s] Notified %d commit(s).', now()->toDateTimeString(), $count));
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
