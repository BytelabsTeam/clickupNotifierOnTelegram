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
            'clickup.team_id' => '12345678901',
            'clickup.done_statuses' => ['complete', 'done', 'تکمیل'],
            'clickup.user_names' => [
                'user@example.com' => 'عارف',
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
            'api.clickup.com/api/v2/team/12345678901/task*' => Http::response([
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
                                'email' => 'user@example.com',
                                'username' => 'Aref',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'api.clickup.com/api/v2/task/task_1' => Http::response([
                'id' => 'task_1',
                'name' => 'رفع باگ لاگین',
                'attachments' => [],
                'space' => ['id' => '7002367'],
                'folder' => [
                    'id' => '6992470',
                    'name' => 'Telegramclient',
                    'hidden' => false,
                ],
            ], 200),
            'api.clickup.com/api/v2/space/7002367' => Http::response([
                'id' => '7002367',
                'name' => 'minishop',
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $count = app(PollClickUpDoneTasks::class)->poll();

        $this->assertSame(1, $count);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && $request['text'] === "عارف تسک \"رفع باگ لاگین\" رو انجام داد ✅\n\n#minishop_Telegramclient";
        });
    }

    public function test_it_does_not_send_duplicate_notifications(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/team/12345678901/task*' => Http::response([
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
                                'email' => 'user@example.com',
                                'username' => 'Aref',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'api.clickup.com/api/v2/task/task_1' => Http::response([
                'id' => 'task_1',
                'name' => 'رفع باگ لاگین',
                'attachments' => [],
                'space' => ['id' => '7002367'],
                'folder' => [
                    'id' => '6992470',
                    'name' => 'Telegramclient',
                    'hidden' => false,
                ],
            ], 200),
            'api.clickup.com/api/v2/space/7002367' => Http::response([
                'id' => '7002367',
                'name' => 'minishop',
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $poller = app(PollClickUpDoneTasks::class);

        $this->assertSame(1, $poller->poll());
        $this->assertSame(0, $poller->poll());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org');
        }, 1);
    }

    public function test_it_sends_task_media_with_caption_when_attachments_exist(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/team/12345678901/task*' => Http::response([
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
                                'email' => 'user@example.com',
                                'username' => 'Aref',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'api.clickup.com/api/v2/task/task_1' => Http::response([
                'id' => 'task_1',
                'name' => 'رفع باگ لاگین',
                'attachments' => [
                    [
                        'url' => 'https://attachments.clickup.com/screenshot.png',
                        'mimetype' => 'image/png',
                        'extension' => 'png',
                        'deleted' => false,
                    ],
                ],
                'space' => ['id' => '7002367'],
                'folder' => [
                    'id' => '6992470',
                    'name' => 'Telegramclient',
                    'hidden' => false,
                ],
            ], 200),
            'api.clickup.com/api/v2/space/7002367' => Http::response([
                'id' => '7002367',
                'name' => 'minishop',
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $count = app(PollClickUpDoneTasks::class)->poll();

        $this->assertSame(1, $count);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123456:telegram-token/sendPhoto'
                && $request['photo'] === 'https://attachments.clickup.com/screenshot.png'
                && $request['caption'] === "عارف تسک \"رفع باگ لاگین\" رو انجام داد ✅\n\n#minishop_Telegramclient";
        });
    }

    public function test_it_ignores_tasks_that_are_not_done(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/team/12345678901/task*' => Http::response([
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
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org');
        });
    }
}
