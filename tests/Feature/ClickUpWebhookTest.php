<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClickUpWebhookTest extends TestCase
{
    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'clickup.webhook_secret' => self::WEBHOOK_SECRET,
            'clickup.api_token' => 'pk_test_token',
            'clickup.done_statuses' => ['complete', 'done', 'تکمیل'],
            'clickup.user_names' => [
                'user@example.com' => 'عارف',
            ],
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => '-1001234567890',
            'telegram.message_template' => '{name} تسک "{task}" رو انجام داد ✅',
        ]);
    }

    public function test_it_rejects_requests_without_valid_signature(): void
    {
        $payload = $this->donePayload();

        $response = $this->call(
            'POST',
            '/api/webhooks/clickup',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );

        $response->assertUnauthorized();
    }

    public function test_it_ignores_non_done_status_updates(): void
    {
        $payload = [
            'event' => 'taskStatusUpdated',
            'task_id' => 'abc123',
            'webhook_id' => 'wh_1',
            'history_items' => [
                [
                    'id' => 'hist_1',
                    'field' => 'status',
                    'user' => [
                        'email' => 'user@example.com',
                        'username' => 'Aref',
                    ],
                    'after' => [
                        'status' => 'in progress',
                        'type' => 'custom',
                    ],
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload);

        $response->assertOk()->assertJson(['status' => 'ignored']);
        Http::assertNothingSent();
    }

    public function test_it_sends_telegram_message_when_task_is_marked_done(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/task/abc123' => Http::response([
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

        $response = $this->postSignedWebhook($this->donePayload());

        $response->assertOk()->assertJson(['status' => 'queued']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123456:telegram-token/sendMessage'
                && $request['text'] === "عارف تسک \"رفع باگ لاگین\" رو انجام داد ✅\n\n#minishop_Telegramclient";
        });
    }

    public function test_it_does_not_send_duplicate_notifications(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/task/abc123' => Http::response([
                'name' => 'رفع باگ لاگین',
                'attachments' => [],
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $payload = $this->donePayload();

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk();

        Http::assertSentCount(2);
    }

    public function test_it_sends_task_media_with_caption_when_attachments_exist(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/task/abc123' => Http::response([
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

        $response = $this->postSignedWebhook($this->donePayload());

        $response->assertOk()->assertJson(['status' => 'queued']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123456:telegram-token/sendPhoto'
                && $request['photo'] === 'https://attachments.clickup.com/screenshot.png'
                && $request['caption'] === "عارف تسک \"رفع باگ لاگین\" رو انجام داد ✅\n\n#minishop_Telegramclient";
        });
    }

    private function postSignedWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->call(
            'POST',
            '/api/webhooks/clickup',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
            ],
            $body
        );
    }

    private function donePayload(): array
    {
        return [
            'event' => 'taskStatusUpdated',
            'task_id' => 'abc123',
            'webhook_id' => 'wh_1',
            'history_items' => [
                [
                    'id' => 'hist_1',
                    'field' => 'status',
                    'user' => [
                        'email' => 'user@example.com',
                        'username' => 'Aref',
                    ],
                    'before' => [
                        'status' => 'in progress',
                        'type' => 'custom',
                    ],
                    'after' => [
                        'status' => 'complete',
                        'type' => 'closed',
                    ],
                ],
            ],
        ];
    }
}
