<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubClient
{
    private const HISTORY_QUERY = <<<'GQL'
query RepoHistory($owner: String!, $name: String!, $since: GitTimestamp, $until: GitTimestamp, $cursor: String) {
  repository(owner: $owner, name: $name) {
    defaultBranchRef {
      target {
        ... on Commit {
          history(first: 100, since: $since, until: $until, after: $cursor) {
            pageInfo {
              hasNextPage
              endCursor
            }
            nodes {
              oid
              additions
              deletions
              committedDate
              parents {
                totalCount
              }
              author {
                email
                name
                user {
                  login
                }
              }
            }
          }
        }
      }
    }
  }
}
GQL;

    /**
     * @return list<array{owner: string, name: string}>
     */
    public function repos(): array
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

        foreach ($this->repos() as $repo) {
            $slug = $repo['owner'].'/'.$repo['name'];
            $cursor = null;
            $page = 0;

            try {
                do {
                    $response = Http::withToken($token)
                        ->acceptJson()
                        ->withHeaders(['User-Agent' => 'clickup-telegram-notifier'])
                        ->post('https://api.github.com/graphql', [
                            'query' => self::HISTORY_QUERY,
                            'variables' => [
                                'owner' => $repo['owner'],
                                'name' => $repo['name'],
                                'since' => $from->toIso8601String(),
                                'until' => $to->toIso8601String(),
                                'cursor' => $cursor,
                            ],
                        ]);

                    $response->throw();

                    $payload = $response->json();

                    if (! is_array($payload)) {
                        break;
                    }

                    if ($this->isInaccessibleRepo($payload)) {
                        Log::warning("GitHub repo is not accessible with the current token: {$slug}");
                        $skipped[] = $slug;

                        continue 2;
                    }

                    if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
                        $message = $payload['errors'][0]['message'] ?? 'GitHub GraphQL error';

                        throw new \RuntimeException(
                            "GitHub ({$slug}): ".(is_string($message) ? $message : 'GraphQL error')
                        );
                    }

                    $history = $payload['data']['repository']['defaultBranchRef']['target']['history'] ?? null;

                    if (! is_array($history)) {
                        break;
                    }

                    foreach ($history['nodes'] ?? [] as $node) {
                        if (! is_array($node)) {
                            continue;
                        }

                        $parents = (int) (($node['parents']['totalCount'] ?? 1));

                        if ($parents > 1) {
                            continue;
                        }

                        $additions = (int) ($node['additions'] ?? 0);

                        if ($additions <= 0) {
                            continue;
                        }

                        $author = is_array($node['author'] ?? null) ? $node['author'] : [];
                        $user = is_array($author['user'] ?? null) ? $author['user'] : [];

                        $commits[] = [
                            'login' => isset($user['login']) ? (string) $user['login'] : null,
                            'email' => isset($author['email']) ? (string) $author['email'] : null,
                            'name' => isset($author['name']) ? (string) $author['name'] : null,
                            'additions' => $additions,
                            'committed_at' => (string) ($node['committedDate'] ?? ''),
                        ];
                    }

                    $hasNext = (bool) ($history['pageInfo']['hasNextPage'] ?? false);
                    $cursor = $history['pageInfo']['endCursor'] ?? null;
                    $page++;
                } while ($hasNext && is_string($cursor) && $cursor !== '' && $page < 10);
            } catch (\Illuminate\Http\Client\RequestException $exception) {
                Log::warning("GitHub request failed for {$slug}: ".$exception->getMessage());
                $skipped[] = $slug;
            }
        }

        return [
            'commits' => $commits,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isInaccessibleRepo(array $payload): bool
    {
        $repository = $payload['data']['repository'] ?? null;

        if ($repository === null) {
            return true;
        }

        foreach ($payload['errors'] ?? [] as $error) {
            if (! is_array($error)) {
                continue;
            }

            $type = (string) ($error['type'] ?? '');
            $message = (string) ($error['message'] ?? '');

            if ($type === 'NOT_FOUND' || str_contains($message, 'Could not resolve to a Repository')) {
                return true;
            }
        }

        return false;
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
