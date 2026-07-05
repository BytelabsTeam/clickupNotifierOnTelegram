<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramNotifier
{
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

    private function sendText(string $text): void
    {
        [$token, $chatId] = $this->credentials();

        $response = Http::asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
        ]);

        $response->throw();
    }

    /**
     * @param  array{url: string, type: string}  $media
     */
    private function sendMedia(array $media, ?string $caption): void
    {
        [$token, $chatId] = $this->credentials();

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

        $response = Http::asForm()->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

        $response->throw();
    }

    /**
     * @param  list<array{url: string, type: string}>  $items
     */
    private function sendMediaGroup(array $items, ?string $caption): void
    {
        [$token, $chatId] = $this->credentials();

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

            $response = Http::asForm()->post("https://api.telegram.org/bot{$token}/sendMediaGroup", [
                'chat_id' => $chatId,
                'media' => json_encode($chunk, JSON_UNESCAPED_UNICODE),
            ]);

            $response->throw();
        }
    }

    /**
     * @return array{0: string, 1: string|int}
     */
    private function credentials(): array
    {
        $token = config('telegram.bot_token');
        $chatId = config('telegram.chat_id');

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        if ($chatId === null || $chatId === '') {
            throw new \RuntimeException('TELEGRAM_CHAT_ID is not configured.');
        }

        return [$token, $chatId];
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
