<?php

namespace Tests\Feature;

use App\Models\TaskScore;
use App\Services\PollClickUpDoneTasks;
use App\Services\TaskScoreSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class PollClickUpDoneTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'clickup.api_token' => 'pk_test_token',
            'clickup.team_id' => '90121842245',
            'clickup.done_statuses' => ['complete', 'done', 'تکمیل'],
            'clickup.user_names' => [
                'arefmohaamd332@gmail.com' => 'عارف',
            ],
            'clickup.poll_lookback_minutes' => 30,
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => '-1001234567890',
            'telegram.message_template' => '{name} تسک "{task}" رو انجام داد ✅',
        ]);
    }

    public function test_it_notifies_telegram_for_newly_done_tasks(): void
    {
        $this->fakeDoneTaskHttp();

        $this->mock(TaskScoreSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scoreTaskIfNeeded')->once()->andReturn(null);
        });

        $count = app(PollClickUpDoneTasks::class)->poll();

        $this->assertSame(1, $count);
        $this->assertSame(1, $this->telegramRequestCount());
        $this->assertTrue(
            collect(Http::recorded())->contains(function (array $pair): bool {
                return str_contains($pair[0]->url(), 'api.telegram.org')
                    && ($pair[0]['text'] ?? null) === 'عارف تسک "رفع باگ لاگین" رو انجام داد ✅';
            })
        );
    }

    public function test_it_does_not_send_duplicate_notifications(): void
    {
        $this->fakeDoneTaskHttp();

        $this->mock(TaskScoreSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scoreTaskIfNeeded')
                ->once()
                ->andReturnUsing(function () {
                    return TaskScore::query()->create([
                        'task_id' => 'task_1',
                        'task_name' => 'رفع باگ لاگین',
                        'is_code' => false,
                        'score' => 100,
                        'month' => '1404-08',
                    ]);
                });
        });

        $poller = app(PollClickUpDoneTasks::class);

        $this->assertSame(1, $poller->poll());
        $this->assertSame(0, $poller->poll());
        $this->assertSame(1, $this->telegramRequestCount());
    }

    public function test_it_retries_scoring_after_notification_when_score_missing(): void
    {
        $this->fakeDoneTaskHttp();

        $calls = 0;

        $this->mock(TaskScoreSyncService::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('scoreTaskIfNeeded')
                ->twice()
                ->andReturnUsing(function () use (&$calls) {
                    $calls++;

                    if ($calls === 1) {
                        return null;
                    }

                    return TaskScore::query()->create([
                        'task_id' => 'task_1',
                        'task_name' => 'رفع باگ لاگین',
                        'is_code' => false,
                        'score' => 800,
                        'month' => '1404-08',
                    ]);
                });
        });

        $poller = app(PollClickUpDoneTasks::class);

        $this->assertSame(1, $poller->poll());
        $this->assertSame(0, $poller->poll());
        $this->assertSame(2, $calls);
        $this->assertSame(1, $this->telegramRequestCount());
        $this->assertDatabaseHas('task_scores', [
            'task_id' => 'task_1',
            'score' => 800,
        ]);
    }

    public function test_it_ignores_tasks_that_are_not_done(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/team/90121842245/task*' => Http::response([
                'tasks' => [
                    [
                        'id' => 'task_2',
                        'name' => 'در حال انجام',
                        'date_updated' => '1700000000001',
                        'status' => [
                            'status' => 'in progress',
                            'type' => 'custom',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $count = app(PollClickUpDoneTasks::class)->poll();

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->telegramRequestCount());
    }

    private function fakeDoneTaskHttp(): void
    {
        $taskPayload = [
            'id' => 'task_1',
            'name' => 'رفع باگ لاگین',
            'date_updated' => '1700000000000',
            'status' => [
                'status' => 'complete',
                'type' => 'closed',
            ],
            'assignees' => [
                [
                    'email' => 'arefmohaamd332@gmail.com',
                    'username' => 'Aref',
                ],
            ],
            'attachments' => [],
        ];

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($taskPayload) {
            $url = $request->url();

            if (str_contains($url, 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            if (str_contains($url, '/api/v2/task/task_1')) {
                return Http::response($taskPayload, 200);
            }

            if (str_contains($url, '/api/v2/team/90121842245/task')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 0);

                return Http::response([
                    'tasks' => $page === 0 ? [$taskPayload] : [],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });
    }

    private function telegramRequestCount(): int
    {
        return collect(Http::recorded())
            ->filter(static fn (array $pair): bool => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->count();
    }
}
