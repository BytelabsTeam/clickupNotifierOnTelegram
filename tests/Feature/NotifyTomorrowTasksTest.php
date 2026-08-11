<?php

namespace Tests\Feature;

use App\Services\NotifyTomorrowTasks;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotifyTomorrowTasksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Tehran'));

        config([
            'app.timezone' => 'Asia/Tehran',
            'clickup.api_token' => 'pk_test_token',
            'clickup.team_id' => '12345678901',
            'clickup.done_statuses' => ['complete', 'done', 'تکمیل'],
            'clickup.user_names' => [
                'aref@example.com' => 'عارف',
                'hasan@example.com' => 'حسن',
            ],
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => '-1001234567890',
            'telegram.usernames' => [
                'aref@example.com' => 'aref_tg',
                'hasan@example.com' => 'hasan_tg',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sends_tomorrows_tasks_grouped_by_assignee_and_sorted_by_priority(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, 'api.clickup.com/api/v2/team/12345678901/task')) {
                $page = (int) ($request['page'] ?? 0);

                if ($page > 0) {
                    return Http::response(['tasks' => []], 200);
                }

                return Http::response([
                    'tasks' => [
                        [
                            'id' => 'task_low',
                            'name' => 'کار با اولویت پایین',
                            'due_date' => '1786665600000',
                            'priority' => [
                                'id' => '4',
                                'priority' => 'low',
                                'orderindex' => '4',
                            ],
                            'status' => ['status' => 'to do', 'type' => 'open'],
                            'assignees' => [
                                ['email' => 'aref@example.com', 'username' => 'Aref'],
                            ],
                            'space' => ['id' => '100'],
                            'folder' => ['id' => '1', 'name' => 'Telegramclient', 'hidden' => false],
                        ],
                        [
                            'id' => 'task_urgent',
                            'name' => 'نصب افزونه ایکس',
                            'due_date' => '1786665600000',
                            'priority' => [
                                'id' => '1',
                                'priority' => 'urgent',
                                'orderindex' => '1',
                            ],
                            'status' => ['status' => 'to do', 'type' => 'open'],
                            'assignees' => [
                                ['email' => 'aref@example.com', 'username' => 'Aref'],
                            ],
                            'space' => ['id' => '100'],
                            'folder' => ['id' => '1', 'name' => 'Telegramclient', 'hidden' => false],
                        ],
                        [
                            'id' => 'task_hasan',
                            'name' => 'تغییر env ها',
                            'due_date' => '1786665600000',
                            'priority' => [
                                'id' => '2',
                                'priority' => 'high',
                                'orderindex' => '2',
                            ],
                            'status' => ['status' => 'in progress', 'type' => 'custom'],
                            'assignees' => [
                                ['email' => 'hasan@example.com', 'username' => 'Hasan'],
                            ],
                            'space' => ['id' => '200'],
                            'folder' => ['hidden' => true],
                        ],
                        [
                            'id' => 'task_done',
                            'name' => 'تسک تمام‌شده',
                            'due_date' => '1786665600000',
                            'priority' => null,
                            'status' => ['status' => 'complete', 'type' => 'closed'],
                            'assignees' => [
                                ['email' => 'aref@example.com', 'username' => 'Aref'],
                            ],
                            'space' => ['id' => '100'],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, 'api.clickup.com/api/v2/space/100')) {
                return Http::response(['id' => '100', 'name' => 'minishop'], 200);
            }

            if (str_contains($url, 'api.clickup.com/api/v2/space/200')) {
                return Http::response(['id' => '200', 'name' => 'backend'], 200);
            }

            if (str_contains($url, 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        $result = app(NotifyTomorrowTasks::class)->notify();

        $this->assertSame([
            'sent' => true,
            'people' => 2,
            'tasks' => 3,
        ], $result);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.clickup.com/api/v2/team/12345678901/task')) {
                return false;
            }

            $data = $request->data();
            $tomorrow = Carbon::now()->addDay();

            return (string) ($data['due_date_gt'] ?? '') === (string) ($tomorrow->copy()->startOfDay()->getTimestampMs() - 1)
                && (string) ($data['due_date_lt'] ?? '') === (string) ($tomorrow->copy()->endOfDay()->getTimestampMs() + 1)
                && (string) ($data['include_closed'] ?? '') === 'false';
        });

        $expected = implode("\n", [
            'تسک های فردا:',
            '',
            'عارف (@aref_tg):',
            '- نصب افزونه ایکس (#minishop_Telegramclient)',
            '- کار با اولویت پایین (#minishop_Telegramclient)',
            '',
            'حسن (@hasan_tg):',
            '- تغییر env ها (#backend)',
        ]);

        Http::assertSent(function ($request) use ($expected) {
            return str_contains($request->url(), 'api.telegram.org')
                && $request['text'] === $expected;
        });
    }

    public function test_it_does_not_send_when_there_are_no_open_tasks(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/team/12345678901/task*' => Http::response(['tasks' => []], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $result = app(NotifyTomorrowTasks::class)->notify();

        $this->assertSame(['sent' => false, 'people' => 0, 'tasks' => 0], $result);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }
}
