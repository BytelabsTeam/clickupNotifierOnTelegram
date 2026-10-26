<?php

namespace Tests\Unit;

use App\Services\IranRouterClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IranRouterClientTest extends TestCase
{
    public function test_it_calls_chat_completions(): void
    {
        config([
            'iranrouter.api_key' => 'ir-test-key',
            'iranrouter.base_url' => 'https://www.iranrouter.net/api/v1',
            'iranrouter.model' => 'openai/gpt-6-luna',
        ]);

        Http::fake([
            'www.iranrouter.net/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{"score": 10, "reason": "ok"}']],
                ],
            ], 200),
        ]);

        $content = app(IranRouterClient::class)->chat([
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('{"score": 10, "reason": "ok"}', $content);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://www.iranrouter.net/api/v1/chat/completions'
                && $request['model'] === 'openai/gpt-6-luna'
                && ($request->header('Authorization')[0] ?? '') === 'Bearer ir-test-key';
        });
    }
}
