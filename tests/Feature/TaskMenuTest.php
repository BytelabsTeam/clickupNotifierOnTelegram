<?php

namespace Tests\Feature;

use App\Models\CommitScore;
use App\Models\TaskScore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskMenuTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'telegram-secret-token';

    private const GROUP_CHAT_ID = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'UTC'));
        Cache::flush();

        config([
            'app.timezone' => 'UTC',
            'clickup.api_token' => 'pk_test_token',
            'clickup.team_id' => '90121842245',
            'clickup.list_id' => '901200123456',
            'clickup.done_statuses' => ['complete', 'done', 'تکمیل'],
            'clickup.user_names' => [
                'arefmohaamd332@gmail.com' => 'عارف',
                'ali@example.com' => 'علی',
            ],
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => self::GROUP_CHAT_ID,
            'telegram.webhook_secret' => self::WEBHOOK_SECRET,
            'telegram.usernames' => [
                'arefmohaamd332@gmail.com' => 'aref_telegram',
                'ali@example.com' => 'ali_telegram',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_menu_command_sends_inline_keyboard(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200),
        ]);

        $this->postSignedWebhook($this->commandUpdate('/menu'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);
            $buttons = collect($markup['inline_keyboard'] ?? [])->flatten(1);

            return str_contains((string) $request['text'], 'مدیریت تسک‌های ClickUp')
                && $buttons->contains(fn (array $btn): bool => ($btn['callback_data'] ?? '') === 'tm:list:my:0')
                && $buttons->contains(fn (array $btn): bool => ($btn['callback_data'] ?? '') === 'tm:scores:day')
                && $buttons->contains(fn (array $btn): bool => ($btn['callback_data'] ?? '') === 'tm:scores:week')
                && $buttons->contains(fn (array $btn): bool => ($btn['callback_data'] ?? '') === 'tm:scores:month')
                && $buttons->contains(fn (array $btn): bool => ($btn['callback_data'] ?? '') === 'comp:home')
                && $buttons->contains(fn (array $btn): bool => ($btn['callback_data'] ?? '') === 'tm:new');
        });
    }

    public function test_scores_day_button_lists_commits_and_tasks_with_points(): void
    {
        $this->seedScoredItems();
        $this->fakeClickUpAndTelegram();

        $this->postSignedWebhook($this->callbackUpdate('tm:scores:day', 'cb-scores-1', 9201))
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'editMessageText')) {
                return false;
            }

            $text = (string) $request['text'];
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);
            $buttons = collect($markup['inline_keyboard'] ?? [])->flatten(1);

            return str_contains($text, 'امتیازهای امروز')
                && str_contains($text, 'کامیت‌ها')
                && str_contains($text, 'رفع باگ لاگین')
                && str_contains($text, '800')
                && str_contains($text, 'تسک‌ها')
                && str_contains($text, 'مستندات API')
                && str_contains($text, '1,200')
                && str_contains($text, 'جمع کل')
                && $buttons->contains(fn (array $btn): bool => ($btn['callback_data'] ?? '') === 'tm:scores:week')
                && $buttons->contains(fn (array $btn): bool => ($btn['callback_data'] ?? '') === 'tm:home');
        });
    }

    public function test_scoresweek_command_lists_weekly_items(): void
    {
        $this->seedScoredItems();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200),
        ]);

        $this->postSignedWebhook($this->commandUpdate('/scoresweek'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) $request['text'];

            return str_contains($text, 'امتیازهای این هفته')
                && str_contains($text, 'فیچر وسط هفته')
                && str_contains($text, '400');
        });
    }

    public function test_my_tasks_callback_lists_task_buttons(): void
    {
        $this->fakeClickUpAndTelegram();

        $this->postSignedWebhook($this->callbackUpdate('tm:list:my:0', 'cb-menu-1', 9101))
            ->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'answerCallbackQuery')
                && $request['callback_query_id'] === 'cb-menu-1';
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'editMessageText')) {
                return false;
            }

            $text = (string) $request['text'];
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);
            $firstButton = $markup['inline_keyboard'][0][0] ?? [];

            return str_contains($text, 'تسک‌های من')
                && str_contains($text, 'رفع باگ لاگین')
                && str_starts_with((string) ($firstButton['callback_data'] ?? ''), 'tm:task:')
                && str_contains((string) ($firstButton['text'] ?? ''), 'رفع باگ لاگین');
        });
    }

    public function test_status_change_via_buttons(): void
    {
        $this->fakeClickUpAndTelegram();

        $ref = substr(hash('sha256', 'task_today'), 0, 8);
        Cache::put('telegram:tm:task:'.$ref, 'task_today', now()->addHour());
        Cache::put('telegram:tm:statuses:'.$ref, ['to do', 'in progress', 'complete'], now()->addHour());

        $this->postSignedWebhook($this->callbackUpdate('tm:set:'.$ref.':2', 'cb-status-1', 9102))
            ->assertOk();

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), 'api.clickup.com/api/v2/task/task_today')
                && ($request['status'] ?? null) === 'complete';
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'answerCallbackQuery')
                && str_contains((string) ($request['text'] ?? ''), 'وضعیت → complete');
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'editMessageText')) {
                return false;
            }

            return str_contains((string) $request['text'], 'رفع باگ لاگین')
                && str_contains((string) $request['text'], 'وضعیت: complete');
        });
    }

    public function test_create_task_flow_from_newtask_command(): void
    {
        $this->fakeClickUpAndTelegram();

        $this->postSignedWebhook($this->commandUpdate('/newtask'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);

            return str_contains((string) $request['text'], 'عنوان تسک جدید')
                && ($markup['inline_keyboard'][0][0]['callback_data'] ?? null) === 'tm:cancel';
        });

        $this->postSignedWebhook($this->commandUpdate('تسک تستی از تلگرام', [
            'from' => [
                'id' => 111,
                'username' => 'aref_telegram',
            ],
        ]))->assertOk();

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'api.clickup.com/api/v2/list/901200123456/task')
                && ($request['name'] ?? null) === 'تسک تستی از تلگرام'
                && ($request['assignees'][0] ?? null) === 101;
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) $request['text'];
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);

            return str_contains($text, 'تسک ساخته شد')
                && str_contains($text, 'تسک تستی از تلگرام')
                && collect($markup['inline_keyboard'] ?? [])->flatten(1)
                    ->contains(fn (array $btn): bool => ($btn['callback_data'] ?? '') === 'tm:home');
        });
    }

    public function test_menu_callback_allowed_in_private_chat_for_mapped_user(): void
    {
        $this->fakeClickUpAndTelegram();

        $this->postSignedWebhook($this->callbackUpdate(
            'tm:home',
            'cb-private-1',
            9103,
            chatId: 555001,
            chatType: 'private',
        ))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && (string) $request['chat_id'] === '555001'
                && str_contains((string) $request['text'], 'مدیریت تسک‌های ClickUp');
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandUpdate(string $text, array $overrides = [], ?int $updateId = null): array
    {
        static $nextUpdateId = 2000;

        return [
            'update_id' => $updateId ?? $nextUpdateId++,
            'message' => array_replace_recursive([
                'message_id' => 10,
                'from' => [
                    'id' => 111,
                    'username' => 'aref_telegram',
                ],
                'chat' => [
                    'id' => self::GROUP_CHAT_ID,
                    'type' => 'supergroup',
                ],
                'text' => $text,
            ], $overrides),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackUpdate(
        string $data,
        string $callbackId,
        int $updateId,
        string|int $chatId = self::GROUP_CHAT_ID,
        string $chatType = 'supergroup',
    ): array {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => $callbackId,
                'from' => [
                    'id' => 111,
                    'username' => 'aref_telegram',
                    'first_name' => 'Aref',
                ],
                'message' => [
                    'message_id' => 88,
                    'chat' => [
                        'id' => $chatId,
                        'type' => $chatType,
                    ],
                    'text' => 'منو',
                ],
                'data' => $data,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedWebhook(array $payload)
    {
        return $this->call(
            'POST',
            '/api/webhooks/telegram',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::WEBHOOK_SECRET,
            ],
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }

    private function seedScoredItems(): void
    {
        CommitScore::query()->create([
            'sha' => 'today-aref',
            'repo' => 'acme/app',
            'login' => 'arefdev',
            'email' => 'arefmohaamd332@gmail.com',
            'name' => 'Aref',
            'message' => "رفع باگ لاگین\nmore detail",
            'score' => 800,
            'committed_at' => '2026-09-16 08:00:00',
            'month' => '1405-06',
        ]);

        CommitScore::query()->create([
            'sha' => 'week-aref',
            'repo' => 'acme/app',
            'login' => 'arefdev',
            'email' => 'arefmohaamd332@gmail.com',
            'name' => 'Aref',
            'message' => 'فیچر وسط هفته',
            'score' => 400,
            'committed_at' => '2026-09-14 12:00:00',
            'month' => '1405-06',
        ]);

        TaskScore::query()->create([
            'task_id' => 'task_docs',
            'email' => 'arefmohaamd332@gmail.com',
            'username' => 'Aref',
            'name' => 'Aref',
            'task_name' => 'مستندات API',
            'task_description' => null,
            'is_code' => false,
            'score' => 1200,
            'reason' => 'useful docs',
            'completed_at' => '2026-09-16 09:00:00',
            'month' => '1405-06',
        ]);

        TaskScore::query()->create([
            'task_id' => 'task_code_zero',
            'email' => 'arefmohaamd332@gmail.com',
            'username' => 'Aref',
            'name' => 'Aref',
            'task_name' => 'تسک کدی',
            'task_description' => null,
            'is_code' => true,
            'score' => 0,
            'reason' => 'code task',
            'completed_at' => '2026-09-16 09:30:00',
            'month' => '1405-06',
        ]);
    }

    private function fakeClickUpAndTelegram(): void
    {
        $openTasks = [
            [
                'id' => 'task_today',
                'name' => 'رفع باگ لاگین',
                'url' => 'https://app.clickup.com/t/task_today',
                'due_date' => (string) Carbon::now('UTC')->getTimestampMs(),
                'status' => [
                    'status' => 'in progress',
                    'type' => 'custom',
                ],
                'list' => ['id' => '901200123456'],
                'assignees' => [
                    [
                        'id' => 101,
                        'email' => 'arefmohaamd332@gmail.com',
                        'username' => 'Aref',
                    ],
                ],
            ],
            [
                'id' => 'task_ali',
                'name' => 'تسک علی',
                'due_date' => (string) Carbon::now('UTC')->getTimestampMs(),
                'status' => [
                    'status' => 'to do',
                    'type' => 'open',
                ],
                'list' => ['id' => '901200123456'],
                'assignees' => [
                    [
                        'id' => 202,
                        'email' => 'ali@example.com',
                        'username' => 'Ali',
                    ],
                ],
            ],
        ];

        Http::fake(function ($request) use ($openTasks) {
            $url = $request->url();

            if (str_contains($url, 'api.telegram.org')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200);
            }

            if (str_contains($url, 'api.clickup.com/api/v2/team') && $request->method() === 'GET' && ! str_contains($url, '/task')) {
                return Http::response([
                    'teams' => [
                        [
                            'id' => '90121842245',
                            'members' => [
                                [
                                    'user' => [
                                        'id' => 101,
                                        'email' => 'arefmohaamd332@gmail.com',
                                        'username' => 'Aref',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, 'api.clickup.com/api/v2/list/901200123456/task') && $request->method() === 'POST') {
                return Http::response([
                    'id' => 'task_created',
                    'name' => $request['name'] ?? 'created',
                    'url' => 'https://app.clickup.com/t/task_created',
                    'status' => ['status' => 'to do'],
                    'list' => ['id' => '901200123456'],
                    'assignees' => [],
                ], 200);
            }

            if (str_contains($url, 'api.clickup.com/api/v2/task/task_today') && $request->method() === 'PUT') {
                return Http::response([
                    'id' => 'task_today',
                    'name' => 'رفع باگ لاگین',
                    'url' => 'https://app.clickup.com/t/task_today',
                    'due_date' => (string) Carbon::now('UTC')->getTimestampMs(),
                    'status' => [
                        'status' => $request['status'] ?? 'complete',
                        'type' => 'closed',
                    ],
                    'list' => ['id' => '901200123456'],
                    'assignees' => [
                        [
                            'id' => 101,
                            'email' => 'arefmohaamd332@gmail.com',
                            'username' => 'Aref',
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, 'api.clickup.com/api/v2/task/task_today')) {
                return Http::response($openTasks[0], 200);
            }

            if (str_contains($url, 'api.clickup.com/api/v2/list/901200123456') && $request->method() === 'GET') {
                return Http::response([
                    'id' => '901200123456',
                    'statuses' => [
                        ['status' => 'to do', 'type' => 'open'],
                        ['status' => 'in progress', 'type' => 'custom'],
                        ['status' => 'complete', 'type' => 'closed'],
                    ],
                ], 200);
            }

            if (str_contains($url, 'api.clickup.com/api/v2/team/90121842245/task')) {
                $page = (int) ($request['page'] ?? 0);

                return Http::response([
                    'tasks' => $page > 0 ? [] : $openTasks,
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });
    }
}
