<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CommitMessageTranslator
{
    public function toPersian(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return $text;
        }

        // فقط خط اول پیام کامیت را ترجمه کن (بدنهٔ طولانی را نگه دار)
        $subject = $this->subjectLine($text);
        $body = $this->bodyAfterSubject($text);

        if ($this->looksMostlyPersian($subject)) {
            return $text;
        }

        $translatedSubject = $this->translateCached($subject);

        if ($body === '') {
            return $translatedSubject;
        }

        return $translatedSubject."\n\n".$body;
    }

    private function subjectLine(string $text): string
    {
        $parts = preg_split("/\r\n|\n|\r/", $text, 2);

        return trim((string) ($parts[0] ?? $text));
    }

    private function bodyAfterSubject(string $text): string
    {
        $parts = preg_split("/\r\n|\n|\r/", $text, 2);

        return trim((string) ($parts[1] ?? ''));
    }

    private function looksMostlyPersian(string $text): bool
    {
        $persian = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text) ?: 0;
        $latin = preg_match_all('/[A-Za-z]/', $text) ?: 0;

        return $persian > 0 && $persian >= $latin;
    }

    private function translateCached(string $text): string
    {
        $cacheKey = 'commit_translate:v1:'.sha1(mb_strtolower($text));

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($text): string {
            return $this->translate($text);
        });
    }

    private function translate(string $text): string
    {
        try {
            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->acceptJson()
                ->get('https://translate.googleapis.com/translate_a/single', [
                    'client' => 'gtx',
                    'sl' => 'auto',
                    'tl' => 'fa',
                    'dt' => 't',
                    'q' => $text,
                ]);

            if (! $response->successful()) {
                Log::warning('Commit translation HTTP '.$response->status());

                return $text;
            }

            $json = $response->json();

            if (! is_array($json) || ! isset($json[0]) || ! is_array($json[0])) {
                return $text;
            }

            $pieces = [];

            foreach ($json[0] as $chunk) {
                if (is_array($chunk) && isset($chunk[0]) && is_string($chunk[0]) && $chunk[0] !== '') {
                    $pieces[] = $chunk[0];
                }
            }

            $translated = trim(implode('', $pieces));

            return $translated !== '' ? $translated : $text;
        } catch (Throwable $exception) {
            Log::warning('Commit translation failed: '.$exception->getMessage());

            return $text;
        }
    }
}
