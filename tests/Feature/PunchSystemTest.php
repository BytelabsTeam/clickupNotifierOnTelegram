<?php

namespace Tests\Feature;

use App\Models\PunchStatToken;
use App\Models\WorkSession;
use App\Models\WorkStart;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PunchSystemTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'telegram-secret-token';

    private const GROUP_CHAT_ID = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Asia/Tehran'));

        config([
            'app.timezone' => 'Asia/Tehran',
            'app.url' => 'https://example.test',
            'clickup.cron_token' => 'secret-cron-token',
            'clickup.user_names' => [
                'ali@example.com' => 'علی',
            ],
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => self::GROUP_CHAT_ID,
            'telegram.webhook_secret' => self::WEBHOOK_SECRET,
            'telegram.usernames' => [
                'ali@example.com' => 'ali_telegram',
                'admin@example.com' => 'megageek',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_punch_in_creates_session_and_work_start(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 701],
            ], 200),
        ]);

        $response = $this->postSignedWebhook($this->privateCommandUpdate(11, 101, 'ali_telegram', '/punch'));

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('work_sessions', 1);
        $session = WorkSession::query()->first();
        $this->assertNotNull($session);
        $this->assertNull($session->punched_out_at);
        $this->assertSame(101, $session->telegram_user_id);
        $this->assertSame(701, $session->telegram_message_id);

        $this->assertDatabaseCount('work_starts', 1);
        $start = WorkStart::query()->first();
        $this->assertNotNull($start);
        $this->assertSame(101, $start->telegram_user_id);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $markup = json_decode((string) $request['reply_markup'], true);

            return str_contains((string) $request['text'], 'شروع کار ثبت شد')
                && ($markup['inline_keyboard'][0][0]['callback_data'] ?? null) === 'punch:out';
        });
    }

    public function test_second_punch_while_open_is_rejected(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 702],
            ], 200),
        ]);

        $this->postSignedWebhook($this->privateCommandUpdate(12, 101, 'ali_telegram', '/punch'));
        $this->postSignedWebhook($this->privateCommandUpdate(13, 101, 'ali_telegram', '/punch'));

        $this->assertDatabaseCount('work_sessions', 1);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'قبلاً punch کردی');
        });
    }

    public function test_punch_out_via_callback_closes_session(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 703]], 200),
        ]);

        $this->postSignedWebhook($this->privateCommandUpdate(14, 101, 'ali_telegram', '/punch'));

        Carbon::setTestNow(Carbon::parse('2026-11-11 12:30:00', 'Asia/Tehran'));

        $this->postSignedWebhook($this->callbackUpdate(
            'cb-punch-out',
            15,
            101,
            'ali_telegram',
            'علی',
            703,
            'punch:out',
            true,
        ))->assertOk();

        $session = WorkSession::query()->first();
        $this->assertNotNull($session);
        $this->assertNotNull($session->punched_out_at);
        $this->assertSame(9000, $session->duration_seconds);
    }

    public function test_multiple_sessions_allowed_after_punch_out(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 800]], 200),
        ]);

        $this->postSignedWebhook($this->privateCommandUpdate(21, 101, 'ali_telegram', '/punch'));
        $this->postSignedWebhook($this->privateCommandUpdate(22, 101, 'ali_telegram', '/punchout'));
        $this->postSignedWebhook($this->privateCommandUpdate(23, 101, 'ali_telegram', '/punch'));

        $this->assertDatabaseCount('work_sessions', 2);
        $this->assertSame(1, WorkSession::query()->open()->count());
        $this->assertDatabaseCount('work_starts', 1);
    }

    public function test_reminder_cron_notifies_open_sessions_after_two_hours(): void
    {
        WorkSession::query()->create([
            'work_date' => '2026-11-11',
            'telegram_user_id' => 101,
            'telegram_username' => 'ali_telegram',
            'display_name' => 'علی',
            'email' => 'ali@example.com',
            'punched_in_at' => Carbon::parse('2026-11-11 07:00:00', 'Asia/Tehran'),
            'source' => 'command',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]], 200),
        ]);

        $response = $this->get('/cron/punch-reminders?token=secret-cron-token');

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'reminded' => 1,
            'auto_closed' => 0,
        ]);

        $session = WorkSession::query()->first();
        $this->assertNotNull($session?->last_reminded_at);
        $this->assertSame(1, $session->reminder_count);

        Http::assertSent(function ($request) {
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);

            return str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'یادآوری punch')
                && ($markup['inline_keyboard'][0][0]['callback_data'] ?? null) === 'punch:still'
                && ($markup['inline_keyboard'][0][1]['callback_data'] ?? null) === 'punch:out';
        });
    }

    public function test_reminder_auto_closes_previous_day_open_sessions(): void
    {
        WorkSession::query()->create([
            'work_date' => '2026-11-10',
            'telegram_user_id' => 101,
            'telegram_username' => 'ali_telegram',
            'display_name' => 'علی',
            'punched_in_at' => Carbon::parse('2026-11-10 18:00:00', 'Asia/Tehran'),
            'source' => 'command',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]], 200),
        ]);

        $response = $this->get('/cron/punch-reminders?token=secret-cron-token');
        $response->assertOk()->assertJson([
            'status' => 'ok',
            'auto_closed' => 1,
        ]);

        $session = WorkSession::query()->first();
        $this->assertNotNull($session);
        $this->assertTrue($session->auto_closed);
        $this->assertNotNull($session->punched_out_at);
        $this->assertNotNull($session->duration_seconds);
    }

    public function test_stat_denied_for_non_admin(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 910]], 200),
        ]);

        $this->postSignedWebhook($this->privateCommandUpdate(31, 101, 'ali_telegram', '/stat'))
            ->assertOk();

        $this->assertDatabaseCount('punch_stat_tokens', 0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'اجازه دسترسی به آمار نداری');
        });
    }

    public function test_stat_sends_private_link_for_admin_and_page_works(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 920]], 200),
        ]);

        WorkSession::query()->create([
            'work_date' => '2026-11-11',
            'telegram_user_id' => 101,
            'telegram_username' => 'ali_telegram',
            'display_name' => 'علی',
            'punched_in_at' => Carbon::parse('2026-11-11 09:00:00', 'Asia/Tehran'),
            'punched_out_at' => Carbon::parse('2026-11-11 10:00:00', 'Asia/Tehran'),
            'duration_seconds' => 3600,
            'source' => 'command',
        ]);

        $this->postSignedWebhook($this->privateCommandUpdate(32, 777, 'megageek', '/stat'))
            ->assertOk();

        $token = PunchStatToken::query()->first();
        $this->assertNotNull($token);

        Http::assertSent(function ($request) use ($token) {
            return str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'https://example.test/punch/stats/'.$token->token);
        });

        $page = $this->get('/punch/stats/'.$token->token);
        $page->assertOk();
        $page->assertSee('آمار کار تیم');
        $page->assertSee('علی');
        $page->assertSee('Activity');
    }

    public function test_expired_stat_token_is_rejected(): void
    {
        PunchStatToken::query()->create([
            'token' => 'expiredtoken123',
            'created_by_telegram_user_id' => 777,
            'created_by_username' => 'megageek',
            'expires_at' => Carbon::parse('2026-11-10 10:00:00', 'Asia/Tehran'),
        ]);

        $this->get('/punch/stats/expiredtoken123')->assertForbidden()->assertSee('لینک آمار معتبر نیست');
    }

    public function test_morning_checkin_cron_is_disabled(): void
    {
        $response = $this->get('/cron/morning-checkin?token=secret-cron-token');

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'sent' => false,
            'disabled' => true,
        ]);
    }

    public function test_still_working_callback_updates_reminder_anchor(): void
    {
        $session = WorkSession::query()->create([
            'work_date' => '2026-11-11',
            'telegram_user_id' => 101,
            'telegram_username' => 'ali_telegram',
            'display_name' => 'علی',
            'punched_in_at' => Carbon::parse('2026-11-11 07:00:00', 'Asia/Tehran'),
            'last_reminded_at' => Carbon::parse('2026-11-11 09:00:00', 'Asia/Tehran'),
            'reminder_count' => 1,
            'source' => 'command',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->postSignedWebhook($this->callbackUpdate(
            'cb-still',
            41,
            101,
            'ali_telegram',
            'علی',
            555,
            'punch:still',
            true,
        ))->assertOk();

        $session->refresh();
        $this->assertTrue($session->isOpen());
        $this->assertTrue(
            $session->last_reminded_at->equalTo(Carbon::parse('2026-11-11 10:00:00', 'Asia/Tehran'))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function privateCommandUpdate(int $updateId, int $userId, string $username, string $text): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 1000 + $updateId,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'first_name' => 'User',
                ],
                'chat' => [
                    'id' => $userId,
                    'type' => 'private',
                ],
                'text' => $text,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackUpdate(
        string $callbackId,
        int $updateId,
        int $userId,
        string $username,
        string $firstName,
        int $messageId,
        string $data,
        bool $private = false,
    ): array {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => $callbackId,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'first_name' => $firstName,
                ],
                'message' => [
                    'message_id' => $messageId,
                    'chat' => [
                        'id' => $private ? $userId : self::GROUP_CHAT_ID,
                        'type' => $private ? 'private' : 'supergroup',
                    ],
                    'text' => 'punch',
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
}
