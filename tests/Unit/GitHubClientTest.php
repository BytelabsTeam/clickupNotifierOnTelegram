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

    public function test_it_skips_inaccessible_repos_and_keeps_the_rest(): void
    {
        config([
            'github.token' => 'ghp_test_token',
            'github.repos' => ['BytelabsTeam/MegaShop', 'acme/app'],
        ]);

        Http::fake([
            'api.github.com/graphql' => Http::sequence()
                ->push([
                    'data' => ['repository' => null],
                    'errors' => [
                        [
                            'type' => 'NOT_FOUND',
                            'message' => "Could not resolve to a Repository with the name 'BytelabsTeam/MegaShop'.",
                        ],
                    ],
                ], 200)
                ->push([
                    'data' => [
                        'repository' => [
                            'defaultBranchRef' => [
                                'target' => [
                                    'history' => [
                                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                                        'nodes' => [
                                            [
                                                'oid' => 'abc',
                                                'additions' => 12,
                                                'deletions' => 0,
                                                'committedDate' => '2026-09-16T08:00:00Z',
                                                'parents' => ['totalCount' => 1],
                                                'author' => [
                                                    'email' => 'dev@example.com',
                                                    'name' => 'Dev',
                                                    'user' => ['login' => 'dev'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $result = app(GitHubClient::class)->commitAdditions(
            Carbon::parse('2026-09-16 00:00:00'),
            Carbon::parse('2026-09-16 23:59:59')
        );

        $this->assertSame(['BytelabsTeam/MegaShop'], $result['skipped']);
        $this->assertCount(1, $result['commits']);
        $this->assertSame(12, $result['commits'][0]['additions']);
    }
}
