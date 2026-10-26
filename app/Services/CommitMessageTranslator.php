<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CommitMessageTranslator
{
    public function __construct(private readonly IranRouterClient $iranRouterClient)
    {
    }

    public function toPersian(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return $text;
        }

        // فقط خط اول (عنوان) کامیت را برای تلگرام نگه دار؛ بدنه را حذف کن
        $subject = $this->subjectLine($text);

        if ($this->looksMostlyPersian($subject)) {
            return $subject;
        }

        return $this->translateCached($subject);
    }

    private function subjectLine(string $text): string
    {
        $parts = preg_split("/\r\n|\n|\r/", $text, 2);

        return trim((string) ($parts[0] ?? $text));
    }

    private function looksMostlyPersian(string $text): bool
    {
        $persian = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text) ?: 0;
        $latin = preg_match_all('/[A-Za-z]/', $text) ?: 0;

        return $persian > 0 && $persian >= $latin;
    }

    private function translateCached(string $text): string
    {
        $cacheKey = 'commit_translate:iranrouter:v1:'.sha1(mb_strtolower($text));

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($text): string {
            return $this->translate($text);
        });
    }

    private function translate(string $text): string
    {
        try {
            $translated = $this->iranRouterClient->chat([
                [
                    'role' => 'system',
                    'content' => implode("\n", [
                        'You translate Git commit subject lines into natural Persian (Farsi).',
                        'Return ONLY the Persian translation — no quotes, no explanation, no extra lines.',
                        'Keep technical terms readable when a common Persian equivalent does not exist.',
                        'If the text is already Persian, return it unchanged.',
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ], 200);

            $translated = trim($translated, " \t\n\r\0\x0B\"'«»");

            return $translated !== '' ? $translated : $text;
        } catch (Throwable $exception) {
            Log::warning('Commit translation failed: '.$exception->getMessage());

            return $text;
        }
    }
}
