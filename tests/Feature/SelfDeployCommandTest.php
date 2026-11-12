<?php

namespace Tests\Feature;

use App\Services\SelfDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SelfDeployCommandTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'telegram-secret-token';

    private const GROUP_CHAT_ID = '-1001234567890';

    private const ADMIN_ID = 103063134;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => self::GROUP_CHAT_ID,
            'telegram.webhook_secret' => self::WEBHOOK_SECRET,
            'telegram.usernames' => [
                'arefmohaamd332@gmail.com' => 'aref_telegram',
            ],
            'deploy.admin_ids' => [self::ADMIN_ID, 1080648232],
            'clickup.cron_token' => 'secret-cron-token',
            'github.token' => 'ghp_test_token',
        ]);
    }

    public function test_non_admin_private_deploy_is_denied(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 99],
            ], 200),
        ]);

        $this->partialMock(SelfDeployService::class, function ($mock) {
            $mock->shouldReceive('run')->never();
        });

        $this->postSignedWebhook($this->commandUpdate('/deploy', [
            'chat' => ['id' => 555001, 'type' => 'private'],
            'from' => ['id' => 555001, 'username' => 'aref_telegram'],
        ]))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && (string) $request['chat_id'] === '555001'
                && str_contains((string) $request['text'], 'اجازه دسترسی');
        });
    }

    public function test_group_deploy_is_silently_ignored(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->partialMock(SelfDeployService::class, function ($mock) {
            $mock->shouldReceive('run')->never();
            $mock->shouldReceive('isAdmin')->never();
        });

        $this->postSignedWebhook($this->commandUpdate('/deploy', [
            'from' => ['id' => self::ADMIN_ID, 'username' => 'admin'],
        ]))->assertOk();

        Http::assertNothingSent();
    }

    public function test_admin_private_deploy_runs_after_response(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 77],
            ], 200),
        ]);

        $this->partialMock(SelfDeployService::class, function ($mock) {
            $mock->shouldReceive('isAdmin')->with(self::ADMIN_ID)->andReturn(true);
            $mock->shouldReceive('isLocked')->andReturn(false);
            $mock->shouldReceive('run')->once()->andReturn([
                'ok' => true,
                'message' => "✅ آپدیت انجام شد.\nفایل‌های کپی‌شده: 12",
            ]);
            $mock->shouldReceive('reportResult')
                ->once()
                ->withArgs(function ($chatId, $progressId, $result) {
                    return (string) $chatId === (string) self::ADMIN_ID
                        && is_array($result)
                        && ($result['ok'] ?? false) === true;
                });
        });

        $this->postSignedWebhook($this->commandUpdate('/deploy', [
            'chat' => ['id' => self::ADMIN_ID, 'type' => 'private'],
            'from' => ['id' => self::ADMIN_ID, 'username' => 'megageek'],
        ]))->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && (string) $request['chat_id'] === (string) self::ADMIN_ID
                && str_contains((string) $request['text'], 'در حال آپدیت');
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandUpdate(string $text, array $overrides = []): array
    {
        static $nextUpdateId = 5000;

        return [
            'update_id' => $nextUpdateId++,
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
}
