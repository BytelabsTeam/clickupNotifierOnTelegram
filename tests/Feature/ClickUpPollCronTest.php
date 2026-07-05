<?php

namespace Tests\Feature;

use App\Services\PollClickUpDoneTasks;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class ClickUpPollCronTest extends TestCase
{
    public function test_it_runs_poll_when_token_is_valid(): void
    {
        config(['clickup.cron_token' => 'secret-cron-token']);

        $this->mock(PollClickUpDoneTasks::class, function (MockInterface $mock): void {
            $mock->shouldReceive('poll')->once()->andReturn(2);
        });

        $response = $this->get('/cron/clickup-poll?token=secret-cron-token');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'notified' => 2,
            ]);
    }

    public function test_it_rejects_invalid_token(): void
    {
        config(['clickup.cron_token' => 'secret-cron-token']);

        $this->get('/cron/clickup-poll?token=wrong-token')->assertForbidden();
    }
}
