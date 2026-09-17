<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GitHubClient
{
    private const MAX_COMMITS_PER_REPO = 40;

    private const DETAIL_POOL_SIZE = 10;

    public function __construct(private readonly LocScoreFilter $locScoreFilter)
    {
    }

    /**
     * @return list<array{owner: string, name: string}>
     */
    public function repos(): array
    {
        $repos = [];

        foreach ($this->configuredRepos() as $repo) {
            $repos[$this->repoKey($repo)] = $repo;
        }

        $org = config('github.org');

        if (is_string($org) && trim($org) !== '') {
            foreach ($this->discoverOrgRepos(trim($org)) as $repo) {
                $repos[$this->repoKey($repo)] = $repo;
            }
        }

        return array_values($repos);
    }

    /**
     * @return list<array{owner: string, name: string}>
     */
    private function configuredRepos(): array
    {
        $repos = [];

        foreach (config('github.repos', []) as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $parsed = $this->parseRepo($raw);

            if ($parsed !== null) {
                $repos[] = $parsed;
            }
        }

        return $repos;
    }

    /**
     * @param  array{owner: string, name: string}  $repo
     */
    private function repoKey(array $repo): string
    {
        return mb_strtolower($repo['owner'].'/'.$repo['name']);
    }

    /**
     * @return list<array{owner: string, name: string}>
     */
    private function discoverOrgRepos(string $org): array
    {
        $token = config('github.token');

        if (! is_string($token) || $token === '') {
            return [];
        }

        /** @var list<array{owner: string, name: string}> $repos */
        $repos = Cache::remember(
            'github:org-repos:v1:'.mb_strtolower($org),
            now()->addMinutes(30),
            fn (): array => $this->fetchOrgRepos($token, $org)
        );

        return $repos;
    }

    /**
     * @return list<array{owner: string, name: string}>
     */
    private function fetchOrgRepos(string $token, string $org): array
    {
        $raw = [];

        try {
            $raw = $this->paginateJsonList($token, "https://api.github.com/orgs/{$org}/repos", [
                'type' => 'all',
                'sort' => 'updated',
            ]);
        } catch (Throwable $exception) {
            Log::warning("GitHub org repo list failed for {$org}: ".$exception->getMessage());
        }

        if ($raw === []) {
            try {
                $userRepos = $this->paginateJsonList($token, 'https://api.github.com/user/repos', [
                    'affiliation' => 'owner,collaborator,organization_member',
                    'sort' => 'updated',
                ]);

                $raw = array_values(array_filter(
                    $userRepos,
                    static function (array $repo) use ($org): bool {
                        $owner = is_array($repo['owner'] ?? null) ? (string) ($repo['owner']['login'] ?? '') : '';

                        return strcasecmp($owner, $org) === 0;
                    }
                ));
            } catch (Throwable $exception) {
                Log::warning('GitHub user repo list failed: '.$exception->getMessage());
            }
        }

        $repos = [];

        foreach ($raw as $item) {
            $mapped = $this->mapApiRepo($item);

            if ($mapped !== null) {
                $repos[$this->repoKey($mapped)] = $mapped;
            }
        }

        $repos = array_values($repos);

        Log::info('GitHub discovered org repos', [
            'org' => $org,
            'count' => count($repos),
            'repos' => array_map(
                static fn (array $repo): string => $repo['owner'].'/'.$repo['name'],
                $repos
            ),
        ]);

        return $repos;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{owner: string, name: string}|null
     */
    private function mapApiRepo(array $item): ?array
    {
        if (($item['archived'] ?? false) || ($item['disabled'] ?? false) || ($item['fork'] ?? false)) {
            return null;
        }

        $fullName = (string) ($item['full_name'] ?? '');

        if ($fullName !== '') {
            return $this->parseRepo($fullName);
        }

        $owner = is_array($item['owner'] ?? null) ? (string) ($item['owner']['login'] ?? '') : '';
        $name = (string) ($item['name'] ?? '');

        if ($owner === '' || $name === '') {
            return null;
        }

        return [
            'owner' => $owner,
            'name' => $name,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function paginateJsonList(string $token, string $url, array $query): array
    {
        $items = [];

        for ($page = 1; $page <= 5; $page++) {
            $response = $this->github($token)->get($url, [
                ...$query,
                'per_page' => 100,
                'page' => $page,
            ]);

            $response->throw();

            $batch = $response->json();

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            if (count($batch) < 100) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array{
     *     commits: list<array{login: ?string, email: ?string, name: ?string, additions: int, committed_at: string}>,
     *     skipped: list<string>
     * }
     */
    public function commitAdditions(Carbon $from, Carbon $to): array
    {
        $token = $this->requireToken();
        $commits = [];
        $skipped = [];
        $repos = $this->repos();

        Log::info('GitHub scoring started', [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'repos' => array_map(
                static fn (array $repo): string => $repo['owner'].'/'.$repo['name'],
                $repos
            ),
        ]);

        foreach ($repos as $repo) {
            $slug = $repo['owner'].'/'.$repo['name'];

            try {
                $summaries = $this->listCommits($token, $repo['owner'], $repo['name'], $from, $to);
                $shas = $this->scoreableShas($summaries);

                Log::info("GitHub scoring {$slug}", [
                    'listed' => count($summaries),
                    'details' => count($shas),
                ]);

                if ($shas === []) {
                    continue;
                }

                $details = $this->commitDetails($token, $repo['owner'], $repo['name'], $shas);

                if ($details === []) {
                    Log::warning("GitHub commit details empty for {$slug}");
                    $skipped[] = $slug;

                    continue;
                }

                foreach ($details as $detail) {
                    $score = $this->scoreFiles($detail['files'] ?? []);

                    if ($score <= 0) {
                        continue;
                    }

                    $commit = is_array($detail['commit'] ?? null) ? $detail['commit'] : [];
                    $author = is_array($detail['author'] ?? null) ? $detail['author'] : [];
                    $gitAuthor = is_array($commit['author'] ?? null) ? $commit['author'] : [];

                    $commits[] = [
                        'login' => isset($author['login']) ? (string) $author['login'] : null,
                        'email' => isset($gitAuthor['email']) ? (string) $gitAuthor['email'] : null,
                        'name' => isset($gitAuthor['name']) ? (string) $gitAuthor['name'] : null,
                        'additions' => $score,
                        'committed_at' => (string) ($gitAuthor['date'] ?? ''),
                    ];
                }
            } catch (Throwable $exception) {
                Log::warning("GitHub scoring failed for {$slug}: ".$exception->getMessage(), [
                    'exception' => $exception::class,
                ]);
                $skipped[] = $slug;
            }
        }

        Log::info('GitHub scoring finished', [
            'commits' => count($commits),
            'skipped' => $skipped,
        ]);

        return [
            'commits' => $commits,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $summaries
     * @return list<string>
     */
    private function scoreableShas(array $summaries): array
    {
        $shas = [];

        foreach ($summaries as $summary) {
            if (count($summary['parents'] ?? []) > 1) {
                continue;
            }

            $sha = (string) ($summary['sha'] ?? '');

            if ($sha === '') {
                continue;
            }

            $shas[] = $sha;

            if (count($shas) >= self::MAX_COMMITS_PER_REPO) {
                break;
            }
        }

        return $shas;
    }

    /**
     * @param  list<mixed>  $files
     */
    private function scoreFiles(array $files): int
    {
        $score = 0;

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $path = (string) ($file['filename'] ?? '');

            if ($path === '' || ! $this->locScoreFilter->counts($path)) {
                continue;
            }

            $score += max(0, (int) ($file['additions'] ?? 0));
            $score += max(0, (int) ($file['deletions'] ?? 0)) * 2;
        }

        return $score;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listCommits(string $token, string $owner, string $name, Carbon $from, Carbon $to): array
    {
        $commits = [];

        for ($page = 1; $page <= 2; $page++) {
            $response = $this->github($token)->get("https://api.github.com/repos/{$owner}/{$name}/commits", [
                'since' => $from->toIso8601String(),
                'until' => $to->toIso8601String(),
                'per_page' => 100,
                'page' => $page,
            ]);

            $response->throw();

            $batch = $response->json();

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $commit) {
                if (is_array($commit)) {
                    $commits[] = $commit;
                }
            }

            if (count($batch) < 100 || count($commits) >= self::MAX_COMMITS_PER_REPO * 2) {
                break;
            }
        }

        return $commits;
    }

    /**
     * @param  list<string>  $shas
     * @return list<array<string, mixed>>
     */
    private function commitDetails(string $token, string $owner, string $name, array $shas): array
    {
        $details = [];

        foreach (array_chunk($shas, self::DETAIL_POOL_SIZE) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $token, $owner, $name) {
                $requests = [];

                foreach ($chunk as $sha) {
                    $requests[$sha] = $this->github($token, $pool->as($sha))
                        ->get("https://api.github.com/repos/{$owner}/{$name}/commits/{$sha}");
                }

                return $requests;
            });

            foreach ($responses as $sha => $response) {
                if ($response instanceof Throwable) {
                    Log::warning("GitHub commit {$owner}/{$name}@{$sha} failed: ".$response->getMessage());

                    continue;
                }

                if (! $response->successful()) {
                    Log::warning("GitHub commit {$owner}/{$name}@{$sha} HTTP ".$response->status());

                    continue;
                }

                $json = $response->json();

                if (is_array($json)) {
                    $details[] = $json;
                }
            }
        }

        return $details;
    }

    private function github(string $token, ?PendingRequest $request = null): PendingRequest
    {
        return ($request ?? Http::withToken($token))
            ->withToken($token)
            ->acceptJson()
            ->timeout(8)
            ->connectTimeout(5)
            ->withOptions([
                'force_ip_resolve' => 'v4',
            ])
            ->withHeaders([
                'User-Agent' => 'clickup-telegram-notifier',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);
    }

    /**
     * @return array{owner: string, name: string}|null
     */
    public function parseRepo(string $value): ?array
    {
        $value = trim($value);
        $value = (string) preg_replace('#^git@github\.com:#', '', $value);
        $value = (string) preg_replace('#^https?://(www\.)?github\.com/#', '', $value);
        $value = (string) preg_replace('#\.git$#', '', $value);
        $value = trim($value, '/');

        if (preg_match('#^([^/]+)/([^/]+)$#', $value, $matches) !== 1) {
            return null;
        }

        return [
            'owner' => $matches[1],
            'name' => $matches[2],
        ];
    }

    public function requireToken(): string
    {
        $token = config('github.token');

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('GITHUB_TOKEN is not configured.');
        }

        return $token;
    }
}
