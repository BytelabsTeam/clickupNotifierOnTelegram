<?php

namespace Tests\Feature;

use App\Services\NotifyTomorrowTasks;
use Mockery\MockInterface;
use Tests\TestCase;

class TomorrowTasksCronTest extends TestCase
{
    public function test_it_runs_notifier_when_token_is_valid(): void
    {
        config(['clickup.cron_token' => 'secret-cron-token']);

        $this->mock(NotifyTomorrowTasks::class, function (MockInterface $mock): void {
            $mock->shouldReceive('notify')->once()->andReturn([
                'sent' => true,
                'people' => 2,
                'tasks' => 3,
            ]);
        });

        $this->get('/cron/tomorrow-tasks?token=secret-cron-token')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'sent' => true,
                'people' => 2,
                'tasks' => 3,
            ]);
    }

    public function test_it_rejects_invalid_token(): void
    {
        config(['clickup.cron_token' => 'secret-cron-token']);

        $this->get('/cron/tomorrow-tasks?token=wrong-token')->assertForbidden();
    }
}
