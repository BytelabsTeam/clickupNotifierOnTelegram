<?php

namespace Tests\Feature;

use App\Services\NotifyDailyHighlight;
use Tests\TestCase;

class DailyHighlightCronTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.cron_token' => 'secret-cron-token']);
    }

    public function test_cron_endpoint_runs_with_valid_token(): void
    {
        $this->mock(NotifyDailyHighlight::class, function ($mock): void {
            $mock->shouldReceive('notify')->once()->andReturn([
                'sent' => true,
                'skipped' => false,
                'text' => '🔥',
                'work_date' => '2026-11-03',
            ]);
        });

        $this->get('/cron/daily-highlight?token=secret-cron-token')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'sent' => true,
                'work_date' => '2026-11-03',
            ]);
    }

    public function test_cron_endpoint_rejects_invalid_token(): void
    {
        $this->get('/cron/daily-highlight?token=wrong')->assertForbidden();
    }
}
