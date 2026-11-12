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

        return $this->summarizeCached($text);
    }

    private function summarizeCached(string $text): string
    {
        $cacheKey = 'commit_translate:iranrouter:v2:dash-mashti:'.sha1(mb_strtolower($text));

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($text): string {
            return $this->summarize($text);
        });
    }

    private function summarize(string $text): string
    {
        try {
            $summarized = $this->iranRouterClient->chat([
                [
                    'role' => 'system',
                    'content' => implode("\n", [
                        'تو خلاصه‌نویس نوتیف کامیت گیت برای گروه تلگرام تیم هستی.',
                        'متن کامل پیام کامیت را بخوان و در یک پاراگراف کوتاه فارسی خلاصه‌اش کن (حدود ۲ تا ۴ جمله؛ نه یک خط خشک، نه چند پاراگراف).',
                        'لحن: داش‌مشتی / لات قدیم تهرانی — صمیمی، رک، باحال، با حس رفاقت و مردانگی خوب‌طینت؛ مثل داش‌های قدیم محل.',
                        'از واژه‌هایی مثل داش، مشتی، داداش، ببین، حسابی، تمومش کرد، یه کاری کرد، درآورد، جمعش کرد استفاده کن وقتی طبیعی است؛ اجباری نیست همه را پشت سر هم بچپانی.',
                        'فحش، توهین، لحن زننده یا لاتی مبتذل ممنوع. رسمی و کتابی هم نباش.',
                        'فقط همان یک پاراگراف را برگردان — بدون عنوان، بدون گیومه، بدون بولت، بدون توضیح اضافه.',
                        'اگر پیام انگلیسی است، خلاصه را فارسی بنویس. اصطلاحات فنی را قابل‌فهم نگه دار.',
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ], 400);

            $summarized = trim($summarized, " \t\n\r\0\x0B\"'«»");
            $summarized = preg_replace("/\r\n|\r|\n+/", ' ', $summarized) ?? $summarized;
            $summarized = trim(preg_replace('/\s+/u', ' ', $summarized) ?? $summarized);

            return $summarized !== '' ? $summarized : $this->subjectLine($text);
        } catch (Throwable $exception) {
            Log::warning('Commit summary failed: '.$exception->getMessage());

            return $this->subjectLine($text);
        }
    }

    private function subjectLine(string $text): string
    {
        $parts = preg_split("/\r\n|\n|\r/", $text, 2);

        return trim((string) ($parts[0] ?? $text));
    }
}
