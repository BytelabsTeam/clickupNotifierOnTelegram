<?php

namespace Tests\Unit;

use App\Models\CommitScore;
use App\Models\ProjectScoringDoc;
use App\Services\CommitLlmScorer;
use App\Services\CommitScoreSyncService;
use App\Services\GitHubClient;
use App\Services\ProjectScoringDocService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CommitScoreSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'UTC'));

        config([
            'app.timezone' => 'UTC',
            'github.token' => 'ghp_test_token',
            'github.org' => '',
            'github.repos' => ['acme/app'],
            'iranrouter.api_key' => 'ir-test',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_scores_only_new_commits_from_lookback_window(): void
    {
        config(['github.score_lookback_hours' => 6]);

        CommitScore::query()->create([
            'sha' => 'already',
            'repo' => 'acme/app',
            'login' => 'dev',
            'email' => 'dev@example.com',
            'name' => 'Dev',
            'message' => 'old',
            'score' => 100,
            'committed_at' => '2026-09-16 08:00:00',
            'month' => '1405-06',
        ]);

        ProjectScoringDoc::query()->create([
            'repo' => 'acme/app',
            'content' => 'Baseline',
            'generated_at' => now(),
        ]);

        $gitHub = Mockery::mock(GitHubClient::class);
        $gitHub->shouldReceive('requireToken')->andReturn('ghp_test_token');
        $gitHub->shouldReceive('repos')->andReturn([['owner' => 'acme', 'name' => 'app']]);
        $gitHub->shouldReceive('scoreableCommits')
            ->once()
            ->withArgs(function ($from, $to, $excludeKeys = []): bool {
                return $from->equalTo(Carbon::parse('2026-09-16 04:00:00', 'UTC'))
                    && $to->equalTo(Carbon::parse('2026-09-16 10:00:00', 'UTC'))
                    && isset($excludeKeys['acme/app|already']);
            })
            ->andReturn([
                'commits' => [
                    [
                        'sha' => 'newsha',
                        'repo' => 'acme/app',
                        'login' => 'dev',
                        'email' => 'dev@example.com',
                        'name' => 'Dev',
                        'message' => 'feat: new',
                        'committed_at' => '2026-09-16T09:00:00Z',
                        'files' => [
                            ['filename' => 'a.php', 'additions' => 10, 'deletions' => 1],
                        ],
                    ],
                ],
                'skipped' => [],
            ]);

        $scorer = Mockery::mock(CommitLlmScorer::class);
        $scorer->shouldReceive('score')
            ->once()
            ->with('acme/app', 'Baseline', 'feat: new', Mockery::type('array'))
            ->andReturn(['score' => 900, 'reason' => 'nice']);

        $docs = Mockery::mock(ProjectScoringDocService::class);
        $docs->shouldReceive('ensure')
            ->once()
            ->with('acme/app')
            ->andReturn(ProjectScoringDoc::query()->where('repo', 'acme/app')->first());

        $sync = new CommitScoreSyncService($gitHub, $docs, $scorer);
        $result = $sync->syncCurrentMonth();

        $this->assertSame(1, $result['scored']);
        $this->assertDatabaseHas('commit_scores', [
            'sha' => 'newsha',
            'repo' => 'acme/app',
            'score' => 900,
            'month' => '1405-06',
        ]);
        $this->assertSame(1, CommitScore::query()->where('sha', 'already')->count());
    }
}
