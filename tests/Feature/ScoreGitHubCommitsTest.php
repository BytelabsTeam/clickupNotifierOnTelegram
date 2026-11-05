<?php

namespace Tests\Feature;

use App\Services\CommitScoreSyncService;
use Tests\TestCase;

class ScoreGitHubCommitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.cron_token' => 'secret-cron-token',
        ]);
    }

    public function test_cron_endpoint_runs_score_sync_with_valid_token(): void
    {
        $this->mock(\App\Services\NotifyScoredCommits::class, function ($mock): void {
            $mock->shouldReceive('notify')->once()->andReturn(2);
        });

        $this->mock(CommitScoreSyncService::class, function ($mock): void {
            $mock->shouldReceive('syncCurrentMonth')->once()->andReturn([
                'scored' => 2,
                'skipped' => [
                    ['repo' => 'acme/app', 'reason' => 'timeout'],
                ],
            ]);
        });

        $this->get('/cron/github-score-commits?token=secret-cron-token')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'scored' => 2,
            ]);
    }

    public function test_cron_endpoint_rejects_invalid_token(): void
    {
        $this->get('/cron/github-score-commits?token=wrong')
            ->assertForbidden();
    }
}
