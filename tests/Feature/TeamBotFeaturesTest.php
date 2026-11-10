<?php

namespace Tests\Feature;

use App\Models\CommitScore;
use App\Models\WorkStart;
use App\Services\PollClickUpDoneTasks;
use App\Services\StreakCompetitionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamBotFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'telegram-secret-token';

    private const GROUP_CHAT_ID = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'Asia/Tehran'));
        Cache::flush();

        config([
            'app.timezone' => 'Asia/Tehran',
            'clickup.api_token' => 'pk_test_token',
            'clickup.team_id' => '90121842245',
            'clickup.list_id' => '901200123456',
            'clickup.review_status' => 'review',
            'clickup.quality_min_desc' => 20,
            'clickup.done_statuses' => ['complete', 'done', 'تکمیل'],
            'clickup.cron_token' => 'secret-cron-token',
            'clickup.user_names' => [
                'arefmohaamd332@gmail.com' => 'عارف',
            ],
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => self::GROUP_CHAT_ID,
            'telegram.webhook_secret' => self::WEBHOOK_SECRET,
            'telegram.usernames' => [
                'arefmohaamd332@gmail.com' => 'aref_telegram',
            ],
            'telegram.message_template' => '{name} تسک "{task}" رو انجام داد ✅',
            'iranrouter.api_key' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_streak_counts_consecutive_work_starts(): void
    {
        WorkStart::query()->create([
            'work_date' => '2026-09-14',
            'telegram_user_id' => 111,
            'telegram_username' => 'aref_telegram',
            'display_name' => 'عارف',
            'email' => 'arefmohaamd332@gmail.com',
            'started_at' => '2026-09-14 09:00:00',
        ]);
        WorkStart::query()->create([
            'work_date' => '2026-09-15',
            'telegram_user_id' => 111,
            'telegram_username' => 'aref_telegram',
            'display_name' => 'عارف',
            'email' => 'arefmohaamd332@gmail.com',
            'started_at' => '2026-09-15 09:00:00',
        ]);
        WorkStart::query()->create([
            'work_date' => '2026-09-16',
            'telegram_user_id' => 111,
            'telegram_username' => 'aref_telegram',
            'display_name' => 'عارف',
            'email' => 'arefmohaamd332@gmail.com',
            'started_at' => '2026-09-16 09:00:00',
        ]);

        $streak = app(StreakCompetitionService::class)->streakForTelegramUser(111);

        $this->assertSame(3, $streak);

        $view = app(StreakCompetitionService::class)->view();
        $this->assertStringContainsString('استریک', $view['text']);
        $this->assertStringContainsString('عارف', $view['text']);
    }

    public function test_natural_language_task_command_creates_clickup_task(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 10]], 200);
            }

            if (str_contains($request->url(), '/list/901200123456/task') && $request->method() === 'POST') {
                return Http::response([
                    'id' => 'created_1',
                    'name' => $request['name'] ?? 'task',
                    'url' => 'https://app.clickup.com/t/created_1',
                    'status' => ['status' => 'to do'],
                ], 200);
            }

            if (str_contains($request->url(), '/api/v2/team') && $request->method() === 'GET') {
                return Http::response(['teams' => [['id' => '90121842245', 'members' => []]]], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $this->postSignedWebhook([
            'update_id' => 7001,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 111, 'username' => 'aref_telegram'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/task فردا رفع باگ پرداخت',
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/list/901200123456/task')
                && str_contains((string) ($request['name'] ?? ''), 'رفع باگ پرداخت');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains((string) $request['text'], 'تسک از متن ساخته شد');
        });
    }

    public function test_quality_gate_blocks_thin_done_task(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 11]], 200);
            }

            if (str_contains($request->url(), '/team/90121842245/task')) {
                $page = (int) ($request['page'] ?? 0);

                return Http::response([
                    'tasks' => $page > 0 ? [] : [[
                        'id' => 'thin_done',
                        'name' => 'تسک نازک',
                        'date_updated' => '1',
                        'status' => ['status' => 'complete', 'type' => 'closed'],
                        'assignees' => [[
                            'email' => 'arefmohaamd332@gmail.com',
                            'username' => 'Aref',
                        ]],
                    ]],
                ], 200);
            }

            if (str_contains($request->url(), '/task/thin_done')) {
                return Http::response([
                    'id' => 'thin_done',
                    'name' => 'تسک نازک',
                    'description' => 'کوتاه',
                    'status' => ['status' => 'complete', 'type' => 'closed'],
                    'list' => ['id' => '901200123456'],
                    'assignees' => [[
                        'email' => 'arefmohaamd332@gmail.com',
                        'username' => 'Aref',
                    ]],
                    'attachments' => [],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $count = app(PollClickUpDoneTasks::class)->poll();

        $this->assertSame(1, $count);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) $request['text'];
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);
            $buttons = collect($markup['inline_keyboard'] ?? [])->flatten(1);

            return str_contains($text, 'گیت کیفیت Done')
                && str_contains($text, 'تسک نازک')
                && $buttons->contains(fn (array $btn): bool => str_starts_with((string) ($btn['callback_data'] ?? ''), 'qg:review:'))
                && $buttons->contains(fn (array $btn): bool => str_starts_with((string) ($btn['callback_data'] ?? ''), 'qg:force:'));
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains((string) $request['text'], 'رو انجام داد');
        });
    }

    public function test_weekly_digest_cron_sends_summary(): void
    {
        CommitScore::query()->create([
            'sha' => 'abc',
            'repo' => 'acme/app',
            'login' => 'aref',
            'email' => 'arefmohaamd332@gmail.com',
            'name' => 'Aref',
            'message' => 'feat: weekly',
            'score' => 500,
            'committed_at' => '2026-09-15 12:00:00',
            'month' => '1405-06',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->get('/cron/weekly-digest?token=secret-cron-token')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'sent' => true,
                'used_llm' => false,
            ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains((string) $request['text'], 'خلاصه هفتگی تیم')
                && str_contains((string) $request['text'], 'امتیازهای این هفته');
        });
    }

    public function test_assign_accept_callback_comments_on_clickup(): void
    {
        $ref = substr(hash('sha256', 'task_assign'), 0, 8);
        Cache::put('telegram:tm:task:'.$ref, 'task_assign', now()->addHour());

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            if (str_contains($request->url(), '/task/task_assign/comment')) {
                return Http::response(['id' => 'c1'], 200);
            }

            if (str_contains($request->url(), '/task/task_assign')) {
                return Http::response([
                    'id' => 'task_assign',
                    'name' => 'تسک اختصاصی',
                    'url' => 'https://app.clickup.com/t/task_assign',
                    'status' => ['status' => 'to do'],
                    'list' => ['id' => '901200123456'],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $this->postSignedWebhook([
            'update_id' => 7002,
            'callback_query' => [
                'id' => 'cb-as-1',
                'from' => ['id' => 111, 'username' => 'aref_telegram'],
                'message' => [
                    'message_id' => 88,
                    'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                    'text' => 'assign',
                ],
                'data' => 'as:ok:'.$ref,
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/task/task_assign/comment')
                && str_contains((string) ($request['comment_text'] ?? ''), 'قبول کرد');
        });
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
}
