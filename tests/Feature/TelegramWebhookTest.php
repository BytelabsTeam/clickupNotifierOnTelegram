<?php

namespace Tests\Feature;

use App\Models\CommitScore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'telegram-secret-token';

    private const GROUP_CHAT_ID = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'UTC'));

        config([
            'app.timezone' => 'UTC',
            'clickup.api_token' => 'pk_test_token',
            'clickup.team_id' => '90121842245',
            'clickup.done_statuses' => ['complete', 'done', 'تکمیل'],
            'clickup.user_names' => [
                'arefmohaamd332@gmail.com' => 'عارف',
                'ali@example.com' => 'علی',
            ],
            'clickup.cron_token' => 'secret-cron-token',
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => self::GROUP_CHAT_ID,
            'telegram.webhook_secret' => self::WEBHOOK_SECRET,
            'telegram.usernames' => [
                'arefmohaamd332@gmail.com' => 'aref_telegram',
                'ali@example.com' => 'ali_telegram',
            ],
            'github.token' => 'ghp_test_token',
            'github.repos' => ['acme/app'],
            'github.org' => '',
            'github.user_logins' => [
                'arefmohaamd332@gmail.com' => 'arefdev',
                'ali@example.com' => 'alidev',
            ],
            'iranrouter.api_key' => 'ir-test-key',
            'iranrouter.base_url' => 'https://iranrouter.net/api/v1',
            'iranrouter.model' => 'openai/gpt-6-luna',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_rejects_requests_without_valid_secret(): void
    {
        $response = $this->postJson('/api/webhooks/telegram', $this->commandUpdate('/help'));

        $response->assertUnauthorized();
    }

    public function test_it_replies_with_help_in_the_configured_group(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->postSignedWebhook($this->commandUpdate('/help'));

        $response->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123456:telegram-token/sendMessage'
                && $request['chat_id'] === self::GROUP_CHAT_ID
                && str_contains((string) $request['text'], '/tasks — لیست تسک‌های باز')
                && ! isset($request['message_thread_id']);
        });
    }

    public function test_it_lists_open_tasks_for_slash_and_persian_commands(): void
    {
        $this->fakeClickUpTasks();

        $this->postSignedWebhook($this->commandUpdate('/tasks@ClickUpBot'))
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) $request['text'];

            return str_contains($text, 'تسک‌های باز (4):')
                && str_contains($text, 'عارف (@aref_telegram):')
                && str_contains($text, 'رفع باگ لاگین')
                && str_contains($text, 'امروز')
                && str_contains($text, 'بدون مسئول');
        });

        $this->postSignedWebhook($this->commandUpdate('لیست تسک ها'))
            ->assertOk();
    }

    public function test_it_lists_overdue_and_tomorrow_tasks(): void
    {
        $this->fakeClickUpTasks();

        $this->postSignedWebhook($this->commandUpdate('/overdue'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) $request['text'];

            return str_contains($text, 'تسک‌های عقب‌افتاده (1):')
                && str_contains($text, 'مستندات API');
        });

        $this->postSignedWebhook($this->commandUpdate('/tomorrow'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) $request['text'];

            return str_contains($text, 'تسک‌های امروز، فردا و عقب‌افتاده (4):')
                && str_contains($text, 'رفع باگ لاگین')
                && str_contains($text, 'مستندات API');
        });
    }

    public function test_it_lists_my_tasks_in_private_chat_for_mapped_user(): void
    {
        $this->fakeClickUpTasks();

        $response = $this->postSignedWebhook($this->commandUpdate('/me', [
            'chat' => [
                'id' => 555001,
                'type' => 'private',
            ],
            'from' => [
                'id' => 555001,
                'username' => 'aref_telegram',
            ],
        ]));

        $response->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) $request['text'];

            return (string) $request['chat_id'] === '555001'
                && str_contains($text, 'تسک‌های عارف (@aref_telegram)')
                && str_contains($text, 'رفع باگ لاگین')
                && str_contains($text, 'تسک مشترک')
                && ! str_contains($text, 'مستندات API')
                && ! str_contains($text, 'تسک علی')
                && ! str_contains($text, "علی:\n")
                && ! str_contains($text, 'بدون مسئول');
        });
    }

    public function test_it_lists_only_the_requester_tasks_for_me_in_the_group(): void
    {
        $this->fakeClickUpTasks();

        $this->postSignedWebhook($this->commandUpdate('/me'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) $request['text'];

            return str_contains($text, 'تسک‌های عارف (@aref_telegram)')
                && str_contains($text, 'رفع باگ لاگین')
                && ! str_contains($text, 'تسک علی')
                && ! str_contains($text, 'علی (@ali_telegram):');
        });
    }

    public function test_it_ranks_github_llm_scores_for_day_week_and_month(): void
    {
        $this->seedLeaderboardCommitScores();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $this->postSignedWebhook($this->commandUpdate('/topday'))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains((string) $request['text'], 'در حال محاسبه امتیاز');
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'editMessageText')) {
                return false;
            }

            $text = (string) $request['text'];

            return str_contains($text, 'برترین‌های امروز:')
                && str_contains($text, '🥇 عارف (@aref_telegram) — 800 امتیاز')
                && str_contains($text, '🥈 علی (@ali_telegram) — 300 امتیاز')
                && str_contains($text, 'معیار امتیاز دهی:')
                && str_contains($text, 'کامیت GitHub با LLM')
                && str_contains($text, 'تسک Done غیرکدی ClickUp')
                && ! str_contains($text, 'Hamid');
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'api.github.com');
        });

        $this->postSignedWebhook($this->commandUpdate('/topweek'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'editMessageText')) {
                return false;
            }

            $text = (string) $request['text'];

            return str_contains($text, 'برترین‌های این هفته:')
                && str_contains($text, '🥇 عارف (@aref_telegram) — 1,200 امتیاز')
                && str_contains($text, '🥈 علی (@ali_telegram) — 300 امتیاز');
        });

        $this->postSignedWebhook($this->commandUpdate('/topmonth'))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'editMessageText')) {
                return false;
            }

            $text = (string) $request['text'];

            return str_contains($text, 'برترین‌های این ماه:')
                && str_contains($text, '🥇 عارف (@aref_telegram) — 1,200 امتیاز')
                && str_contains($text, '🥈 علی (@ali_telegram) — 400 امتیاز');
        });
    }

    public function test_it_reads_leaderboard_without_iranrouter_key(): void
    {
        config(['iranrouter.api_key' => '']);
        $this->seedLeaderboardCommitScores();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $this->postSignedWebhook($this->commandUpdate('/topday'))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains((string) $request['text'], '🥇 عارف (@aref_telegram) — 800 امتیاز');
        });
    }

    public function test_it_ignores_duplicate_telegram_update_ids(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $payload = $this->commandUpdate('/help', [], 4242);

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk();

        $helpSends = collect(Http::recorded())
            ->filter(function (array $pair): bool {
                [$request] = $pair;

                return str_contains($request->url(), 'sendMessage')
                    && str_contains((string) $request['text'], '/tasks — لیست تسک‌های باز');
            })
            ->count();

        $this->assertSame(1, $helpSends);
    }

    public function test_it_shows_empty_leaderboard_when_github_token_missing_and_no_scores(): void
    {
        config([
            'github.token' => '',
            'clickup.api_token' => '',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $this->postSignedWebhook($this->commandUpdate('/topday'))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains((string) $request['text'], 'نه GitHub و نه ClickUp برای امتیازدهی تنظیم شده');
        });
    }

    public function test_it_shows_empty_leaderboard_from_database_without_github_calls(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $this->postSignedWebhook($this->commandUpdate('/topday'))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains((string) $request['text'], 'در این بازه امتیازی ثبت نشده');
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'api.github.com')
                || str_contains($request->url(), 'api.clickup.com')
                || str_contains($request->url(), 'iranrouter.net');
        });
    }

    public function test_it_ignores_commands_from_unknown_groups(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->postSignedWebhook($this->commandUpdate('/tasks', [
            'chat' => [
                'id' => -100999,
                'type' => 'supergroup',
            ],
        ]))->assertOk();

        Http::assertNothingSent();
    }

    public function test_it_replies_in_the_same_forum_topic(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->postSignedWebhook($this->commandUpdate('/help', [
            'message_thread_id' => 42,
        ]))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && (string) $request['message_thread_id'] === '42';
        });
    }

    public function test_it_registers_webhook_via_cron_url(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $response = $this->get('/telegram/set-webhook?token=secret-cron-token');

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'url' => 'http://localhost/api/webhooks/telegram',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123456:telegram-token/setWebhook'
                && $request['url'] === 'http://localhost/api/webhooks/telegram'
                && $request['secret_token'] === self::WEBHOOK_SECRET;
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'setMyCommands');
        });
    }

    public function test_it_registers_webhook_via_cron_alias(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->get('/cron/telegram-register-webhook?token=secret-cron-token')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_it_registers_webhook_without_env_secret(): void
    {
        config(['telegram.webhook_secret' => '']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->get('/telegram/set-webhook?token=secret-cron-token')
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'setWebhook')
                && is_string($request['secret_token'] ?? null)
                && $request['secret_token'] !== '';
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandUpdate(string $text, array $overrides = [], ?int $updateId = null): array
    {
        static $nextUpdateId = 1000;

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

    private function fakeClickUpTasks(): void
    {
        $openTasks = [
            [
                'id' => 'task_today',
                'name' => 'رفع باگ لاگین',
                'due_date' => (string) Carbon::now('UTC')->getTimestampMs(),
                'status' => [
                    'status' => 'in progress',
                    'type' => 'custom',
                ],
                'assignees' => [
                    [
                        'email' => 'arefmohaamd332@gmail.com',
                        'username' => 'Aref',
                    ],
                    [
                        'email' => 'ali@example.com',
                        'username' => 'Ali',
                    ],
                ],
            ],
            [
                'id' => 'task_shared',
                'name' => 'تسک مشترک',
                'due_date' => (string) Carbon::now('UTC')->addDay()->getTimestampMs(),
                'status' => [
                    'status' => 'to do',
                    'type' => 'open',
                ],
                'assignees' => [
                    [
                        'email' => 'arefmohaamd332@gmail.com',
                        'username' => 'Aref',
                    ],
                    [
                        'email' => 'ali@example.com',
                        'username' => 'Ali',
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
                'assignees' => [
                    [
                        'email' => 'ali@example.com',
                        'username' => 'Ali',
                    ],
                ],
            ],
            [
                'id' => 'task_overdue',
                'name' => 'مستندات API',
                'due_date' => (string) Carbon::yesterday('UTC')->getTimestampMs(),
                'status' => [
                    'status' => 'to do',
                    'type' => 'open',
                ],
                'assignees' => [],
            ],
            [
                'id' => 'task_done_open_query',
                'name' => 'تسک تمام‌شده',
                'due_date' => (string) Carbon::now('UTC')->getTimestampMs(),
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
        ];

        $doneTasks = [
            [
                'id' => 'done_aref_1',
                'name' => 'انجام یک',
                'date_done' => (string) Carbon::now('UTC')->getTimestampMs(),
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
            [
                'id' => 'done_aref_2',
                'name' => 'انجام دو',
                'date_closed' => (string) Carbon::now('UTC')->subHours(2)->getTimestampMs(),
                'status' => [
                    'status' => 'done',
                    'type' => 'closed',
                ],
                'assignees' => [
                    [
                        'email' => 'arefmohaamd332@gmail.com',
                        'username' => 'Aref',
                    ],
                ],
            ],
            [
                'id' => 'done_aref_week',
                'name' => 'انجام وسط هفته',
                'date_done' => (string) Carbon::parse('2026-09-14 12:00:00', 'UTC')->getTimestampMs(),
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
            [
                'id' => 'done_ali_1',
                'name' => 'انجام علی',
                'date_done' => (string) Carbon::now('UTC')->getTimestampMs(),
                'status' => [
                    'status' => 'complete',
                    'type' => 'closed',
                ],
                'assignees' => [
                    [
                        'email' => 'ali@example.com',
                        'username' => 'Ali',
                    ],
                ],
            ],
            [
                'id' => 'done_hamid_old',
                'name' => 'هفته قبل',
                'date_done' => (string) Carbon::parse('2026-09-10 12:00:00', 'UTC')->getTimestampMs(),
                'status' => [
                    'status' => 'complete',
                    'type' => 'closed',
                ],
                'assignees' => [
                    [
                        'email' => 'hamid@example.com',
                        'username' => 'Hamid',
                    ],
                ],
            ],
        ];

        Http::fake(function ($request) use ($openTasks, $doneTasks) {
            if (str_contains($request->url(), 'api.clickup.com')) {
                $page = (int) ($request['page'] ?? 0);

                if ($page > 0) {
                    return Http::response(['tasks' => []], 200);
                }

                $includeClosed = (string) ($request['include_closed'] ?? 'false') === 'true';

                return Http::response([
                    'tasks' => $includeClosed ? $doneTasks : $openTasks,
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });
    }

    private function seedLeaderboardCommitScores(): void
    {
        $rows = [
            ['sha' => 'today-aref', 'login' => 'arefdev', 'email' => 'arefmohaamd332@gmail.com', 'name' => 'Aref', 'score' => 800, 'committed_at' => '2026-09-16 08:00:00', 'month' => '1405-06'],
            ['sha' => 'today-ali', 'login' => 'alidev', 'email' => 'ali@example.com', 'name' => 'Ali', 'score' => 300, 'committed_at' => '2026-09-16 09:00:00', 'month' => '1405-06'],
            ['sha' => 'week-aref', 'login' => 'arefdev', 'email' => 'arefmohaamd332@gmail.com', 'name' => 'Aref', 'score' => 400, 'committed_at' => '2026-09-14 12:00:00', 'month' => '1405-06'],
            ['sha' => 'month-ali', 'login' => 'alidev', 'email' => 'ali@example.com', 'name' => 'Ali', 'score' => 100, 'committed_at' => '2026-09-03 12:00:00', 'month' => '1405-06'],
            ['sha' => 'old-hamid', 'login' => 'hamiddev', 'email' => 'hamid@example.com', 'name' => 'Hamid', 'score' => 5000, 'committed_at' => '2026-08-15 12:00:00', 'month' => '1405-05'],
        ];

        foreach ($rows as $row) {
            CommitScore::query()->create([
                'sha' => $row['sha'],
                'repo' => 'acme/app',
                'login' => $row['login'],
                'email' => $row['email'],
                'name' => $row['name'],
                'message' => 'commit '.$row['sha'],
                'score' => $row['score'],
                'committed_at' => $row['committed_at'],
                'month' => $row['month'],
            ]);
        }
    }
}
