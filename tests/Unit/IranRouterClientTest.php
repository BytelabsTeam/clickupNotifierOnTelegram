<?php

namespace Tests\Unit;

use App\Services\IranRouterClient;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
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
            'iranrouter.net/api/v1/chat/completions' => Http::response([
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
            return $request->url() === 'https://iranrouter.net/api/v1/chat/completions'
                && $request->method() === 'POST'
                && $request->hasHeader('Content-Type', 'application/json')
                && $request['model'] === 'openai/gpt-6-luna'
                && ! array_key_exists('temperature', $request->data())
                && ($request->header('Authorization')[0] ?? '') === 'Bearer ir-test-key';
        });
    }

    public function test_proxy_url_is_null_when_host_empty(): void
    {
        config([
            'iranrouter.proxy.host' => '',
            'iranrouter.proxy.port' => '55767',
            'iranrouter.proxy.user' => 'user',
            'iranrouter.proxy.pass' => 'pass',
        ]);

        $this->assertNull($this->invokeProxyUrl());
    }

    public function test_proxy_url_includes_auth_when_configured(): void
    {
        config([
            'iranrouter.proxy.host' => 'proxy.example.com',
            'iranrouter.proxy.port' => '55767',
            'iranrouter.proxy.user' => 'lihkyg9d',
            'iranrouter.proxy.pass' => 'whtm6zzy5yea',
        ]);

        $this->assertSame(
            'http://lihkyg9d:whtm6zzy5yea@proxy.example.com:55767',
            $this->invokeProxyUrl()
        );
    }

    private function invokeProxyUrl(): ?string
    {
        $client = app(IranRouterClient::class);
        $method = new ReflectionMethod(IranRouterClient::class, 'proxyUrl');
        $method->setAccessible(true);

        return $method->invoke($client);
    }
}
