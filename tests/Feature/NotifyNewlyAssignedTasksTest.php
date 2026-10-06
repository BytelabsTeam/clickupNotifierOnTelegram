<?php

namespace Tests\Feature;

use App\Services\NotifyNewlyAssignedTasks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotifyNewlyAssignedTasksTest extends TestCase
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
                'arefmohammad332@gmail.com' => 'عارف',
                'hassan.ah1381@gmail.com' => 'حسن',
            ],
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => '-1001234567890',
            'telegram.usernames' => [
                'arefmohammad332@gmail.com' => 'MegaGeek',
            ],
        ]);
    }

    public function test_it_sends_task_title_assignee_mention_and_creator(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            if (preg_match('#/api/v2/task/task_1$#', $url) === 1) {
                return Http::response([
                    'id' => 'task_1',
                    'name' => 'اضافه کردن قابلیت API و ارسال پیام همگانی',
                    'creator' => [
                        'email' => 'hassan.ah1381@gmail.com',
                        'username' => 'hassan',
                    ],
                    'assignees' => [
                        [
                            'id' => 11,
                            'email' => 'arefmohammad332@gmail.com',
                            'username' => 'Aref',
                        ],
                    ],
                    'status' => [
                        'status' => 'to do',
                        'type' => 'open',
                    ],
                ], 200);
            }

            if (str_contains($url, '/team/90121842245/task')) {
                $page = (int) ($request['page'] ?? 0);

                if ($page > 0) {
                    return Http::response(['tasks' => []], 200);
                }

                return Http::response([
                    'tasks' => [
                        [
                            'id' => 'task_1',
                            'name' => 'اضافه کردن قابلیت API و ارسال پیام همگانی',
                            'status' => [
                                'status' => 'to do',
                                'type' => 'open',
                            ],
                            'assignees' => [
                                [
                                    'id' => 11,
                                    'email' => 'arefmohammad332@gmail.com',
                                    'username' => 'Aref',
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['message' => 'Not Found'], 404);
        });

        $result = app(NotifyNewlyAssignedTasks::class)->notify();

        $this->assertTrue($result['sent']);
        $this->assertSame(1, $result['notified']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && $request['text'] === implode("\n", [
                    'اضافه کردن قابلیت API و ارسال پیام همگانی',
                    '@MegaGeek',
                    '',
                    '📝حسن',
                ]);
        });
    }

    public function test_it_does_not_notify_the_same_assignee_twice(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            if (preg_match('#/api/v2/task/#', $url) === 1) {
                return Http::response([
                    'id' => 'task_1',
                    'name' => 'تسک تکراری',
                    'creator' => [
                        'email' => 'hassan.ah1381@gmail.com',
                    ],
                    'assignees' => [
                        [
                            'id' => 11,
                            'email' => 'arefmohammad332@gmail.com',
                        ],
                    ],
                    'status' => [
                        'status' => 'to do',
                        'type' => 'open',
                    ],
                ], 200);
            }

            if (str_contains($url, '/team/90121842245/task')) {
                $page = (int) ($request['page'] ?? 0);

                if ($page > 0) {
                    return Http::response(['tasks' => []], 200);
                }

                return Http::response([
                    'tasks' => [
                        [
                            'id' => 'task_1',
                            'name' => 'تسک تکراری',
                            'status' => [
                                'status' => 'to do',
                                'type' => 'open',
                            ],
                            'assignees' => [
                                [
                                    'id' => 11,
                                    'email' => 'arefmohammad332@gmail.com',
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['message' => 'Not Found'], 404);
        });

        $notifier = app(NotifyNewlyAssignedTasks::class);

        $this->assertSame(1, $notifier->notify()['notified']);
        $this->assertSame(0, $notifier->notify()['notified']);
    }
}
