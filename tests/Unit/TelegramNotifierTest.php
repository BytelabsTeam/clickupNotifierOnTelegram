<?php

namespace Tests\Unit;

use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => '-1001234567890',
        ]);
    }

    public function test_it_sends_single_photo_without_media_group(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        app(TelegramNotifier::class)->send('caption text', [
            ['url' => 'https://example.com/one.png', 'type' => 'photo'],
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123456:telegram-token/sendPhoto'
                && $request['photo'] === 'https://example.com/one.png'
                && $request['caption'] === 'caption text';
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'sendMediaGroup');
        });
    }

    public function test_it_appends_project_tag_below_message(): void
    {
        config([
            'telegram.message_template' => '{name} تسک "{task}" رو انجام داد ✅',
        ]);

        $message = app(TelegramNotifier::class)->formatMessage('عارف', 'رفع باگ', '#minishop_Telegramclient');

        $this->assertSame(
            "عارف تسک \"رفع باگ\" رو انجام داد ✅\n\n#minishop_Telegramclient",
            $message
        );
    }

    public function test_it_sends_multiple_photos_as_media_group(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        app(TelegramNotifier::class)->send('caption text', [
            ['url' => 'https://example.com/one.png', 'type' => 'photo'],
            ['url' => 'https://example.com/two.png', 'type' => 'photo'],
        ]);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.telegram.org/bot123456:telegram-token/sendMediaGroup') {
                return false;
            }

            $media = json_decode($request['media'], true);

            return $media === [
                [
                    'type' => 'photo',
                    'media' => 'https://example.com/one.png',
                    'caption' => 'caption text',
                ],
                [
                    'type' => 'photo',
                    'media' => 'https://example.com/two.png',
                ],
            ];
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'sendPhoto');
        });
    }
}
