<?php

namespace Tests\Feature;

use App\Services\PollClickUpDoneTasks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollClickUpDoneTasksTest extends TestCase
{
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
        Http::fake([
            'api.clickup.com/api/v2/team/90121842245/task*' => Http::response([
                'tasks' => [
                    [
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
                    ],
                ],
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $count = app(PollClickUpDoneTasks::class)->poll();

        $this->assertSame(1, $count);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && $request['text'] === 'عارف تسک "رفع باگ لاگین" رو انجام داد ✅';
        });
    }

    public function test_it_does_not_send_duplicate_notifications(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/team/90121842245/task*' => Http::response([
                'tasks' => [
                    [
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
                    ],
                ],
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $poller = app(PollClickUpDoneTasks::class);

        $this->assertSame(1, $poller->poll());
        $this->assertSame(0, $poller->poll());

        Http::assertSentCount(2);
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
        Http::assertNothingSent();
    }
}
