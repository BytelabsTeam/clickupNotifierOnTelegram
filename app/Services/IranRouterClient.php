<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class IranRouterClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function chat(array $messages, ?float $temperature = 0.2, ?int $maxTokens = 1024): string
    {
        $apiKey = $this->requireApiKey();
        $baseUrl = (string) config('iranrouter.base_url');
        $model = (string) config('iranrouter.model', 'openai/gpt-6-luna');
        $timeout = max(5, (int) config('iranrouter.timeout', 60));

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(10)
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'messages' => $messages,
            ]);

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
}
