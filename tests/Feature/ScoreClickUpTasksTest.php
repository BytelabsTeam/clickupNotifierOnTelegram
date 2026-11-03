<?php

namespace Tests\Feature;

use App\Services\TaskScoreSyncService;
use Tests\TestCase;

class ScoreClickUpTasksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'clickup.cron_token' => 'secret-cron-token',
        ]);
    }

    public function test_cron_endpoint_runs_score_sync_with_valid_token(): void
    {
        $this->mock(TaskScoreSyncService::class, function ($mock): void {
            $mock->shouldReceive('syncCurrentMonth')->once()->andReturn([
                'scored' => 3,
                'skipped_code' => 1,
                'skipped' => [
                    ['task' => 'abc123', 'reason' => 'timeout'],
                ],
            ]);
        });

        $this->get('/cron/clickup-score-tasks?token=secret-cron-token')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'scored' => 3,
                'skipped_code' => 1,
            ]);
    }

    public function test_cron_endpoint_rejects_invalid_token(): void
    {
        $this->get('/cron/clickup-score-tasks?token=wrong')
            ->assertForbidden();
    }
}
