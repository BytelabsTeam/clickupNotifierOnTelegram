<?php

namespace App\Services;

use App\Models\CommitScore;
use Carbon\Carbon;
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
     * Score only current-month commits that are not stored yet.
     *
     * @return array{scored: int, skipped: list<array{repo: string, reason: string}>}
     */
    public function syncCurrentMonth(?Carbon $now = null): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now ??= Carbon::now($timezone);
        $from = $now->copy()->timezone($timezone)->startOfMonth();
        $to = $now->copy()->timezone($timezone)->endOfMonth();
        $month = $from->format('Y-m');

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

        $result = $this->gitHubClient->scoreableCommits($from, $to);
        $commits = $result['commits'] ?? [];
        /** @var list<array{repo: string, reason: string}> $skipped */
        $skipped = $result['skipped'] ?? [];

        if ($commits === []) {
            return [
                'scored' => 0,
                'skipped' => $skipped,
            ];
        }

        $existingKeys = CommitScore::query()
            ->where('month', $month)
            ->get(['sha', 'repo'])
            ->mapWithKeys(static fn (CommitScore $row): array => [
                mb_strtolower($row->repo).'|'.$row->sha => true,
            ])
            ->all();

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
                    ? $committedAt->copy()->timezone($timezone)->format('Y-m')
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
            'month' => $month,
            'scored' => $scored,
            'skipped' => count($skipped),
        ]);

        return [
            'scored' => $scored,
            'skipped' => $skipped,
        ];
    }
}
