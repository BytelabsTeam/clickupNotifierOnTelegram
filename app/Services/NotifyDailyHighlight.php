<?php

namespace App\Services;

use App\Support\JalaliCalendar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NotifyDailyHighlight
{
    public function __construct(
        private readonly ScoreReportService $scoreReportService,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    /**
     * @return array{sent: bool, skipped: bool, text: string, work_date: string}
     */
    public function notify(): array
    {
        $timezone = JalaliCalendar::timezone();
        $workDate = now($timezone)->toDateString();
        $cacheKey = "telegram:daily-highlight:{$workDate}";

        if (! Cache::add($cacheKey, true, now($timezone)->endOfDay()->addHour())) {
            return [
                'sent' => false,
                'skipped' => true,
                'text' => '',
                'work_date' => $workDate,
            ];
        }

        $text = $this->scoreReportService->formatDailyHighlight();

        if (str_contains($text, 'هنوز ثبت نشده')) {
            Cache::forget($cacheKey);

            Log::info('Daily highlight skipped; no scores today', ['work_date' => $workDate]);

            return [
                'sent' => false,
                'skipped' => true,
                'text' => $text,
                'work_date' => $workDate,
            ];
        }

        $this->telegramNotifier->send($text);

        return [
            'sent' => true,
            'skipped' => false,
            'text' => $text,
            'work_date' => $workDate,
        ];
    }
}
