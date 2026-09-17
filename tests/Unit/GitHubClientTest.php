<?php

namespace Tests\Unit;

use App\Services\GitHubClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubClientTest extends TestCase
{
    public function test_it_parses_repo_urls_and_slugs(): void
    {
        $client = app(GitHubClient::class);

        $this->assertSame(
            ['owner' => 'acme', 'name' => 'app'],
            $client->parseRepo('acme/app')
        );
        $this->assertSame(
            ['owner' => 'acme', 'name' => 'app'],
            $client->parseRepo('https://github.com/acme/app')
        );
        $this->assertSame(
            ['owner' => 'acme', 'name' => 'app'],
            $client->parseRepo('https://github.com/acme/app.git')
        );
        $this->assertSame(
            ['owner' => 'acme', 'name' => 'app'],
            $client->parseRepo('git@github.com:acme/app.git')
        );
        $this->assertNull($client->parseRepo('not-a-repo'));
    }

    public function test_it_skips_inaccessible_repos_and_counts_only_scored_files(): void
    {
        config([
            'github.token' => 'ghp_test_token',
            'github.org' => '',
            'github.repos' => ['BytelabsTeam/MegaShop', 'acme/app'],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'BytelabsTeam/MegaShop')) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            if (preg_match('#/repos/acme/app/commits/abc$#', $url) === 1) {
                return Http::response([
                    'sha' => 'abc',
                    'parents' => [['sha' => 'parent']],
                    'author' => ['login' => 'dev'],
                    'commit' => [
                        'author' => [
                            'email' => 'dev@example.com',
                            'name' => 'Dev',
                            'date' => '2026-09-16T08:00:00Z',
                        ],
                    ],
                    'files' => [
                        ['filename' => 'app/Services/Foo.php', 'additions' => 12, 'deletions' => 3],
                        ['filename' => 'resources/css/app.css', 'additions' => 400, 'deletions' => 200],
                        ['filename' => 'vendor/laravel/framework/src/Support/Str.php', 'additions' => 800, 'deletions' => 50],
                        ['filename' => 'config/app.php', 'additions' => 20, 'deletions' => 10],
                    ],
                ], 200);
            }

            if (str_contains($url, '/repos/acme/app/commits')) {
                return Http::response([
                    [
                        'sha' => 'abc',
                        'parents' => [['sha' => 'parent']],
                        'author' => ['login' => 'dev'],
                        'commit' => [
                            'author' => [
                                'email' => 'dev@example.com',
                                'name' => 'Dev',
                                'date' => '2026-09-16T08:00:00Z',
                            ],
                        ],
                    ],
                ], 200);
            }

            if (preg_match('#/repos/acme/app$#', (string) parse_url($url, PHP_URL_PATH)) === 1) {
                return Http::response(['default_branch' => 'main'], 200);
            }

            return Http::response(['message' => 'Not Found'], 404);
        });

        $result = app(GitHubClient::class)->commitAdditions(
            Carbon::parse('2026-09-16 00:00:00'),
            Carbon::parse('2026-09-16 23:59:59')
        );

        $this->assertSame(['BytelabsTeam/MegaShop'], $result['skipped']);
        $this->assertCount(1, $result['commits']);
        $this->assertSame(18, $result['commits'][0]['additions']);
    }

    public function test_it_skips_repos_when_commit_details_fail(): void
    {
        config([
            'github.token' => 'ghp_test_token',
            'github.org' => '',
            'github.repos' => ['acme/app'],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (preg_match('#/repos/acme/app/commits/[a-z0-9]+$#', $url) === 1) {
                return Http::response(['message' => 'Server Error'], 500);
            }

            if (str_contains($url, '/repos/acme/app/commits')) {
                return Http::response([
                    [
                        'sha' => 'abc',
                        'parents' => [['sha' => 'parent']],
                    ],
                ], 200);
            }

            if (preg_match('#/repos/acme/app$#', (string) parse_url($url, PHP_URL_PATH)) === 1) {
                return Http::response(['default_branch' => 'main'], 200);
            }

            return Http::response(['message' => 'Not Found'], 404);
        });

        $result = app(GitHubClient::class)->commitAdditions(
            Carbon::parse('2026-09-16 00:00:00'),
            Carbon::parse('2026-09-16 23:59:59')
        );

        $this->assertSame(['acme/app'], $result['skipped']);
        $this->assertSame([], $result['commits']);
    }

    public function test_it_discovers_all_org_repos_and_skips_forks_and_archived(): void
    {
        config([
            'github.token' => 'ghp_test_token',
            'github.org' => 'BytelabsTeam',
            'github.repos' => [],
        ]);

        Http::fake([
            'api.github.com/orgs/BytelabsTeam/repos*' => Http::response([
                [
                    'full_name' => 'BytelabsTeam/MegaShop',
                    'archived' => false,
                    'disabled' => false,
                    'fork' => false,
                ],
                [
                    'full_name' => 'BytelabsTeam/old-app',
                    'archived' => true,
                    'disabled' => false,
                    'fork' => false,
                ],
                [
                    'full_name' => 'BytelabsTeam/forked',
                    'archived' => false,
                    'disabled' => false,
                    'fork' => true,
                ],
                [
                    'full_name' => 'BytelabsTeam/AdsPlatform',
                    'archived' => false,
                    'disabled' => false,
                    'fork' => false,
                ],
            ], 200),
        ]);

        $this->assertSame(
            [
                ['owner' => 'BytelabsTeam', 'name' => 'MegaShop'],
                ['owner' => 'BytelabsTeam', 'name' => 'AdsPlatform'],
            ],
            app(GitHubClient::class)->repos()
        );
    }

    public function test_it_falls_back_to_user_repos_when_org_list_fails(): void
    {
        config([
            'github.token' => 'ghp_test_token',
            'github.org' => 'BytelabsTeam',
            'github.repos' => ['acme/extra'],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/orgs/BytelabsTeam/repos')) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            if (str_contains($url, '/user/repos')) {
                return Http::response([
                    [
                        'full_name' => 'BytelabsTeam/juli',
                        'owner' => ['login' => 'BytelabsTeam'],
                        'archived' => false,
                        'disabled' => false,
                        'fork' => false,
                    ],
                    [
                        'full_name' => 'someone/else',
                        'owner' => ['login' => 'someone'],
                        'archived' => false,
                        'disabled' => false,
                        'fork' => false,
                    ],
                ], 200);
            }

            return Http::response(['message' => 'Not Found'], 404);
        });

        $this->assertSame(
            [
                ['owner' => 'acme', 'name' => 'extra'],
                ['owner' => 'BytelabsTeam', 'name' => 'juli'],
            ],
            app(GitHubClient::class)->repos()
        );
    }
}
