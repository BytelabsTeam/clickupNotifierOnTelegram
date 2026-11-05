<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PollGitHubCommits
{
    private const LAST_CHECK_CACHE_KEY = 'github_commits_poll:last_check';

    public function __construct(private readonly GitHubClient $gitHubClient)
    {
    }

    /**
     * Only checks GitHub for the recent window; notifications are sent by
     * NotifyScoredCommits after the commit has a stored score.
     */
    public function poll(): int
    {
        if ($this->gitHubClient->repos() === []) {
            return 0;
        }

        $this->gitHubClient->requireToken();

        $from = $this->resolveSince();
        $to = Carbon::now('UTC');

        Cache::put(self::LAST_CHECK_CACHE_KEY, $to->toIso8601String(), now()->addDays(30));

        return count($this->gitHubClient->recentCommits($from, $to));
    }

    private function resolveSince(): Carbon
    {
        $lastCheck = Cache::get(self::LAST_CHECK_CACHE_KEY);

        if (is_string($lastCheck) && $lastCheck !== '') {
            return Carbon::parse($lastCheck, 'UTC')->subSeconds(30);
        }

        $lookbackMinutes = (int) config('github.commit_poll_lookback_minutes', 15);

        return Carbon::now('UTC')->subMinutes(max(1, $lookbackMinutes));
    }
}
