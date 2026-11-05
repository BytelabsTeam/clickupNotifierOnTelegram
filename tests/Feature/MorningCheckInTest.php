<?php

namespace Tests\Feature;

use App\Models\MorningCheckinMessage;
use App\Models\TeamMember;
use App\Models\WorkStart;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MorningCheckInTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'telegram-secret-token';

    private const GROUP_CHAT_ID = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', 'Asia/Tehran'));

        config([
            'app.timezone' => 'Asia/Tehran',
            'app.cron_token' => 'secret-cron-token',
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => self::GROUP_CHAT_ID,
            'telegram.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cron_sends_morning_greeting_with_mentions_and_button(): void
    {
        TeamMember::query()->create(['telegram_user_id' => 1002, 'telegram_username' => 'ageek', 'github_login' => 'DeAref', 'display_name' => '????']);
        TeamMember::query()->create(['telegram_user_id' => 1003, 'telegram_username' => 'Call_oF_Dutty', 'github_login' => 'mahdinasiri13861386', 'display_name' => '???']);

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ], 200),
        ]);

        $response = $this->get('/cron/morning-checkin?token=secret-cron-token');

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'sent' => true,
            'message_id' => 501,
            'work_date' => '2026-10-27',
            'mentions' => 2,
        ]);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.telegram.org/bot123456:telegram-token/sendMessage') {
                return false;
            }

            $text = (string) $request['text'];
            $markup = json_decode((string) $request['reply_markup'], true);

            return str_contains($text, 'صبح بخیر')
                && str_contains($text, '@ageek')
                && str_contains($text, '@Call_oF_Dutty')
                && ($markup['inline_keyboard'][0][0]['text'] ?? null) === 'شروع کار'
                && ($markup['inline_keyboard'][0][0]['callback_data'] ?? null) === 'work_start';
        });

        $stored = MorningCheckinMessage::query()->first();
        $this->assertNotNull($stored);
        $this->assertSame('2026-10-27', $stored->work_date->toDateString());
        $this->assertSame(self::GROUP_CHAT_ID, $stored->chat_id);
        $this->assertSame(501, $stored->message_id);
    }

    public function test_cron_rejects_invalid_token(): void
    {
        $this->get('/cron/morning-checkin?token=wrong')->assertForbidden();
    }

    public function test_callback_records_start_and_edits_same_message(): void
    {
        TeamMember::query()->create(['telegram_user_id' => 222, 'telegram_username' => 'ageek', 'github_login' => 'DeAref', 'display_name' => 'عارف']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        MorningCheckinMessage::query()->create([
            'work_date' => '2026-10-27',
            'chat_id' => self::GROUP_CHAT_ID,
            'message_id' => 501,
        ]);

        $response = $this->postSignedWebhook($this->callbackUpdate(
            callbackId: 'cb-1',
            updateId: 9001,
            userId: 222,
            username: 'ageek',
            firstName: 'Ali',
            messageId: 501,
        ));

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('work_starts', 1);
        $stored = WorkStart::query()->first();
        $this->assertNotNull($stored);
        $this->assertSame('2026-10-27', $stored->work_date->toDateString());
        $this->assertSame(222, $stored->telegram_user_id);
        $this->assertSame('ageek', $stored->telegram_username);
        $this->assertSame('عارف', $stored->display_name);
        $this->assertNull($stored->email);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'answerCallbackQuery')
                && $request['callback_query_id'] === 'cb-1'
                && str_contains((string) $request['text'], 'شروع کار ثبت شد');
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'editMessageText')) {
                return false;
            }

            $text = (string) $request['text'];
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);

            return (string) $request['chat_id'] === self::GROUP_CHAT_ID
                && (int) $request['message_id'] === 501
                && str_contains($text, 'صبح بخیر')
                && str_contains($text, 'شروع‌کرده‌ها:')
                && str_contains($text, 'عارف — ۰۹:۰۰')
                && ($markup['inline_keyboard'][0][0]['callback_data'] ?? null) === 'work_start';
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'sendMessage');
        });
    }

    public function test_second_callback_same_day_does_not_duplicate_or_edit(): void
    {
        TeamMember::query()->create(['telegram_user_id' => 222, 'telegram_username' => 'ageek', 'github_login' => 'DeAref', 'display_name' => '????']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        WorkStart::query()->create([
            'work_date' => '2026-10-27',
            'telegram_user_id' => 222,
            'telegram_username' => 'ageek',
            'display_name' => 'علی',
            'email' => null,
            'started_at' => Carbon::parse('2026-10-27 09:00:00', 'Asia/Tehran'),
        ]);

        $this->postSignedWebhook($this->callbackUpdate(
            callbackId: 'cb-2',
            updateId: 9002,
            userId: 222,
            username: 'ageek',
            firstName: 'Ali',
            messageId: 501,
        ))->assertOk();

        $this->assertDatabaseCount('work_starts', 1);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'answerCallbackQuery')
                && str_contains((string) $request['text'], 'قبلاً ثبت شده');
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'editMessageText');
        });
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
                        'id' => self::GROUP_CHAT_ID,
                        'type' => 'supergroup',
                    ],
                    'text' => 'صبح بخیر',
                ],
                'data' => 'work_start',
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
