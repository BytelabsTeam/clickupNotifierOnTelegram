<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Support\JalaliCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CommitScoreSyncService
{
    public function __construct(
        private readonly GitHubClient $gitHubClient,
        private readonly ProjectScoringDocService $projectScoringDocService,
        private readonly CommitLlmScorer $commitLlmScorer,
    ) {
    }

    /**
     * Score recent commits (default: last 6 hours) that are not stored yet.
     *
     * @return array{scored: int, skipped: list<array{repo: string, reason: string}>}
     */
    public function syncCurrentMonth(?Carbon $now = null): array
    {
        $timezone = JalaliCalendar::timezone();
        $now = ($now ?? Carbon::now($timezone))->copy()->timezone($timezone);
        $lookbackHours = max(1, (int) config('github.score_lookback_hours', 6));
        $from = $now->copy()->subHours($lookbackHours);
        $to = $now->copy();
        $month = JalaliCalendar::monthKey($now);

        $lock = Cache::lock('github:score-sync:recent', 300);

        try {
            // Non-blocking: overlapping cron must not queue another GitHub crawl.
            if (! $lock->get()) {
                Log::info('Commit score sync skipped; another run holds the lock', [
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                ]);

                return [
                    'scored' => 0,
                    'skipped' => [],
                ];
            }
        } catch (Throwable $exception) {
            Log::warning('Commit score sync lock failed: '.$exception->getMessage());
        }

        try {
            return $this->syncRecentLocked($from, $to, $month);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Lock may already have expired.
            }
        }
    }

    /**
     * Score a single commit immediately. Idempotent by sha+repo.
     *
     * @param  array{
     *     sha?: string,
     *     repo?: string,
     *     login?: ?string,
     *     email?: ?string,
     *     name?: ?string,
     *     message?: string,
     *     committed_at?: string,
     *     files?: list<array{filename: string, additions: int, deletions: int}>
     * }  $commit
     */
    public function scoreCommitIfNeeded(array $commit): ?CommitScore
    {
        $repo = (string) ($commit['repo'] ?? '');
        $sha = (string) ($commit['sha'] ?? '');

        if ($repo === '' || $sha === '') {
            return null;
        }

        $existing = CommitScore::query()->where('sha', $sha)->where('repo', $repo)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            app(IranRouterClient::class)->requireApiKey();
        } catch (\RuntimeException) {
            return null;
        }

        $files = is_array($commit['files'] ?? null) ? $commit['files'] : [];

        if ($files === []) {
            $detailed = $this->gitHubClient->scoreableCommit($repo, $sha);

            if ($detailed === null) {
                return null;
            }

            $commit = array_merge($commit, $detailed);
            $files = is_array($commit['files'] ?? null) ? $commit['files'] : [];
        }

        $committedAt = null;

        if (($commit['committed_at'] ?? '') !== '') {
            $committedAt = Carbon::parse((string) $commit['committed_at']);
        }

        $doc = $this->projectScoringDocService->ensure($repo)->content;
        $scoredResult = $this->commitLlmScorer->score(
            $repo,
            $doc,
            (string) ($commit['message'] ?? ''),
            $files,
        );

        $month = $committedAt !== null
            ? JalaliCalendar::monthKey($committedAt)
            : JalaliCalendar::monthKey(now(JalaliCalendar::timezone()));

        $row = CommitScore::query()->updateOrCreate(
            [
                'sha' => $sha,
                'repo' => $repo,
            ],
            [
                'login' => $commit['login'] ?? null,
                'email' => $commit['email'] ?? null,
                'name' => $commit['name'] ?? null,
                'message' => mb_substr((string) ($commit['message'] ?? ''), 0, 5000),
                'score' => $scoredResult['score'],
                'committed_at' => $committedAt,
                'month' => $month,
            ]
        );

        if ((int) $scoredResult['score'] > 0) {
            try {
                app(StreakCompetitionService::class)->maybeAnnounceOvertake(
                    isset($commit['email']) ? (string) $commit['email'] : null,
                    isset($commit['name']) ? (string) $commit['name'] : null,
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $row;
    }

    /**
     * @return array{scored: int, skipped: list<array{repo: string, reason: string}>}
     */
    private function syncRecentLocked(Carbon $from, Carbon $to, string $month): array
    {
        try {
            $this->gitHubClient->requireToken();
            app(IranRouterClient::class)->requireApiKey();
        } catch (\RuntimeException $exception) {
            Log::warning('Commit score sync skipped: '.$exception->getMessage());

            return [
                'scored' => 0,
                'skipped' => [],
            ];
        }

        if ($this->gitHubClient->repos() === []) {
            return [
                'scored' => 0,
                'skipped' => [],
            ];
        }

        // Skip GitHub commit-detail fetches for SHAs already stored in this window.
        $existingKeys = CommitScore::query()
            ->whereNotNull('committed_at')
            ->where('committed_at', '>=', $from->copy()->utc())
            ->get(['sha', 'repo'])
            ->mapWithKeys(static fn (CommitScore $row): array => [
                mb_strtolower($row->repo).'|'.$row->sha => true,
            ])
            ->all();

        $result = $this->gitHubClient->scoreableCommits($from, $to, $existingKeys);
        $commits = $result['commits'] ?? [];
        /** @var list<array{repo: string, reason: string}> $skipped */
        $skipped = $result['skipped'] ?? [];

        if ($commits === []) {
            return [
                'scored' => 0,
                'skipped' => $skipped,
            ];
        }

        $docs = [];
        $scored = 0;

        foreach ($commits as $commit) {
            $repo = (string) ($commit['repo'] ?? '');
            $sha = (string) ($commit['sha'] ?? '');

            if ($repo === '' || $sha === '') {
                continue;
            }

            $key = mb_strtolower($repo).'|'.$sha;

            if (isset($existingKeys[$key])) {
                continue;
            }

            try {
                $committedAt = null;

                if (($commit['committed_at'] ?? '') !== '') {
                    $committedAt = Carbon::parse((string) $commit['committed_at']);
                }

                if ($committedAt !== null && ($committedAt->lt($from) || $committedAt->gt($to))) {
                    continue;
                }

                // Extra safety if a row exists outside the preloaded window.
                if (CommitScore::query()->where('sha', $sha)->where('repo', $repo)->exists()) {
                    $existingKeys[$key] = true;

                    continue;
                }

                if (! isset($docs[$repo])) {
                    $docs[$repo] = $this->projectScoringDocService->ensure($repo)->content;
                }

                $scoredResult = $this->commitLlmScorer->score(
                    $repo,
                    $docs[$repo],
                    (string) ($commit['message'] ?? ''),
                    is_array($commit['files'] ?? null) ? $commit['files'] : [],
                );

                $commitMonth = $committedAt !== null
                    ? JalaliCalendar::monthKey($committedAt)
                    : $month;

                CommitScore::query()->updateOrCreate(
                    [
                        'sha' => $sha,
                        'repo' => $repo,
                    ],
                    [
                        'login' => $commit['login'] ?? null,
                        'email' => $commit['email'] ?? null,
                        'name' => $commit['name'] ?? null,
                        'message' => mb_substr((string) ($commit['message'] ?? ''), 0, 5000),
                        'score' => $scoredResult['score'],
                        'committed_at' => $committedAt,
                        'month' => $commitMonth,
                    ]
                );

                if ((int) $scoredResult['score'] > 0) {
                    try {
                        app(StreakCompetitionService::class)->maybeAnnounceOvertake(
                            isset($commit['email']) ? (string) $commit['email'] : null,
                            isset($commit['name']) ? (string) $commit['name'] : null,
                        );
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }

                $existingKeys[$key] = true;
                $scored++;
            } catch (Throwable $exception) {
                Log::warning("Commit score failed for {$repo}@{$sha}: ".$exception->getMessage(), [
                    'exception' => $exception::class,
                ]);

                $skipped[] = [
                    'repo' => "{$repo}@".mb_substr($sha, 0, 7),
                    'reason' => mb_substr($exception->getMessage(), 0, 120),
                ];
            }
        }

        Log::info('Commit score sync finished', [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'scored' => $scored,
            'skipped' => count($skipped),
        ]);

        return [
            'scored' => $scored,
            'skipped' => $skipped,
        ];
    }
}
