<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class IranRouterClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function chat(array $messages, ?int $maxTokens = 1024): string
    {
        $apiKey = $this->requireApiKey();
        $baseUrl = $this->normalizedBaseUrl();
        $model = (string) config('iranrouter.model', 'openai/gpt-6-luna');
        $timeout = max(5, (int) config('iranrouter.timeout', 60));

        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }

        $options = [
            // 301/302 from www → apex otherwise downgrades POST to GET (IranRouter 405).
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => true,
                'protocols' => ['https'],
            ],
        ];

        $proxy = $this->proxyUrl();
        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->withOptions($options)
            ->timeout($timeout)
            ->connectTimeout(10)
            ->post("{$baseUrl}/chat/completions", $payload);

        if (! $response->successful()) {
            $body = mb_substr($response->body(), 0, 300);

            throw new RuntimeException("IranRouter HTTP {$response->status()}: {$body}");
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('IranRouter returned an empty completion.');
        }

        return trim($content);
    }

    public function requireApiKey(): string
    {
        $key = config('iranrouter.api_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('IRANROUTER_API_KEY is not configured.');
        }

        return $key;
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = rtrim((string) config('iranrouter.base_url'), '/');

        // www host 301s to apex; without strict redirects POST becomes GET → 405.
        return str_replace('://www.iranrouter.net', '://iranrouter.net', $baseUrl);
    }

    /**
     * Build a Guzzle proxy URL when AI_PROXY_HOST is set; otherwise null (direct).
     */
    private function proxyUrl(): ?string
    {
        $host = trim((string) config('iranrouter.proxy.host', ''));
        if ($host === '') {
            return null;
        }

        $port = trim((string) config('iranrouter.proxy.port', ''));
        $user = (string) config('iranrouter.proxy.user', '');
        $pass = (string) config('iranrouter.proxy.pass', '');

        $authority = $host;
        if ($port !== '') {
            $authority .= ':'.$port;
        }

        if ($user !== '') {
            $auth = rawurlencode($user);
            if ($pass !== '') {
                $auth .= ':'.rawurlencode($pass);
            }

            return "http://{$auth}@{$authority}";
        }

        return "http://{$authority}";
    }
}
