<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TelegramNotifier
{
    private const MESSAGE_LIMIT = 4000;

    /**
     * @param  list<array{url: string, type: string}>  $mediaAttachments
     */
    public function send(string $text, array $mediaAttachments = []): void
    {
        if ($mediaAttachments === []) {
            $this->sendText($text);

            return;
        }

        $albumItems = [];
        $separateItems = [];

        foreach ($mediaAttachments as $media) {
            if ($media['type'] === 'animation') {
                $separateItems[] = $media;

                continue;
            }

            $albumItems[] = $media;
        }

        $caption = $text;

        if ($albumItems !== []) {
            if (count($albumItems) === 1) {
                $this->sendMedia($albumItems[0], $caption);
            } else {
                $this->sendMediaGroup($albumItems, $caption);
            }

            $caption = null;
        }

        foreach ($separateItems as $media) {
            $this->sendMedia($media, $caption);
            $caption = null;
        }
    }

    public function reply(string|int $chatId, string $text, string|int|null $messageThreadId = null): ?int
    {
        $messageId = null;

        foreach ($this->chunkText($text) as $chunk) {
            $json = $this->postForm('sendMessage', $this->applyThreadId([
                'chat_id' => $chatId,
                'text' => $chunk,
            ], $messageThreadId));

            $id = $json['result']['message_id'] ?? null;

            if (is_numeric($id)) {
                $messageId = (int) $id;
            }
        }

        return $messageId;
    }

    public function edit(string|int $chatId, int $messageId, string $text): void
    {
        $chunks = $this->chunkText($text);
        $first = $chunks[0] ?? $text;

        $this->postForm('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $first,
        ]);

        foreach (array_slice($chunks, 1) as $chunk) {
            $this->reply($chatId, $chunk);
        }
    }

    /**
     * @return array{url: string, result: mixed}
     */
    public function registerBotWebhook(): array
    {
        $token = $this->botToken();
        $url = $this->webhookUrl();
        $secret = $this->webhookSecret();

        $response = Http::asForm()->post("https://api.telegram.org/bot{$token}/setWebhook", [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['message']),
            'drop_pending_updates' => 'true',
        ]);

        $response->throw();

        Http::asForm()->post("https://api.telegram.org/bot{$token}/setMyCommands", [
            'commands' => json_encode([
                ['command' => 'tasks', 'description' => 'لیست تسک‌های باز'],
                ['command' => 'today', 'description' => 'تسک‌های امروز'],
                ['command' => 'tomorrow', 'description' => 'تسک‌های امروز، فردا و عقب‌افتاده'],
                ['command' => 'overdue', 'description' => 'تسک‌های عقب‌افتاده'],
                ['command' => 'me', 'description' => 'فقط تسک‌های خودت'],
                ['command' => 'topday', 'description' => 'برترین‌های امروز (خطوط کد)'],
                ['command' => 'topweek', 'description' => 'برترین‌های این هفته (خطوط کد)'],
                ['command' => 'topmonth', 'description' => 'برترین‌های این ماه (خطوط کد)'],
                ['command' => 'help', 'description' => 'راهنما'],
            ], JSON_UNESCAPED_UNICODE),
        ])->throw();

        $info = Http::get("https://api.telegram.org/bot{$token}/getWebhookInfo");

        return [
            'url' => $url,
            'result' => $response->json(),
            'webhook_info' => $info->json(),
        ];
    }

    public function webhookSecret(): string
    {
        $configured = config('telegram.webhook_secret');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return Cache::rememberForever(
            'telegram:webhook_secret',
            static fn (): string => bin2hex(random_bytes(24))
        );
    }

    public function webhookUrl(): string
    {
        $configured = config('telegram.webhook_endpoint');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/api/webhooks/telegram';
    }

    private function sendText(string $text): void
    {
        [, $chatId] = $this->credentials();

        $this->postForm('sendMessage', $this->applyThreadId([
            'chat_id' => $chatId,
            'text' => $text,
        ], config('telegram.message_thread_id')));
    }

    /**
     * @param  array{url: string, type: string}  $media
     */
    private function sendMedia(array $media, ?string $caption): void
    {
        [, $chatId] = $this->credentials();

        [$method, $field] = match ($media['type']) {
            'photo' => ['sendPhoto', 'photo'],
            'video' => ['sendVideo', 'video'],
            'animation' => ['sendAnimation', 'animation'],
            default => ['sendDocument', 'document'],
        };

        $payload = [
            'chat_id' => $chatId,
            $field => $media['url'],
        ];

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        $this->postForm($method, $this->applyThreadId($payload, config('telegram.message_thread_id')));
    }

    /**
     * @param  list<array{url: string, type: string}>  $items
     */
    private function sendMediaGroup(array $items, ?string $caption): void
    {
        [, $chatId] = $this->credentials();

        $media = [];

        foreach ($items as $index => $item) {
            $entry = [
                'type' => $item['type'] === 'video' ? 'video' : 'photo',
                'media' => $item['url'],
            ];

            if ($index === 0 && $caption !== null && $caption !== '') {
                $entry['caption'] = $caption;
            }

            $media[] = $entry;
        }

        foreach (array_chunk($media, 10) as $chunkIndex => $chunk) {
            if ($chunkIndex > 0) {
                $chunk = array_map(static function (array $entry): array {
                    unset($entry['caption']);

                    return $entry;
                }, $chunk);
            }

            $this->postForm('sendMediaGroup', $this->applyThreadId([
                'chat_id' => $chatId,
                'media' => json_encode($chunk, JSON_UNESCAPED_UNICODE),
            ], config('telegram.message_thread_id')));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyThreadId(array $payload, string|int|null $threadId): array
    {
        if ($threadId !== null && $threadId !== '') {
            $payload['message_thread_id'] = $threadId;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postForm(string $method, array $payload): array
    {
        $token = $this->botToken();

        $response = Http::asForm()->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return list<string>
     */
    private function chunkText(string $text, int $limit = self::MESSAGE_LIMIT): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            $candidate = $current === '' ? $line : $current."\n".$line;

            if (mb_strlen($candidate) <= $limit) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            if (mb_strlen($line) <= $limit) {
                $current = $line;

                continue;
            }

            foreach (mb_str_split($line, $limit) as $piece) {
                $chunks[] = $piece;
            }

            $current = '';
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks !== [] ? $chunks : [$text];
    }

    private function botToken(): string
    {
        $token = config('telegram.bot_token');

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        return $token;
    }

    /**
     * @return array{0: string, 1: string|int}
     */
    private function credentials(): array
    {
        $chatId = config('telegram.chat_id');

        if ($chatId === null || $chatId === '') {
            throw new \RuntimeException('TELEGRAM_CHAT_ID is not configured.');
        }

        return [$this->botToken(), $chatId];
    }

    public function formatMessage(string $name, string $taskName, string $projectTag = ''): string
    {
        $template = (string) config('telegram.message_template');

        $message = str_replace(
            ['{name}', '{task}'],
            [$name, $taskName],
            $template
        );

        if ($projectTag !== '') {
            $message .= "\n\n".$projectTag;
        }

        return $message;
    }
}
