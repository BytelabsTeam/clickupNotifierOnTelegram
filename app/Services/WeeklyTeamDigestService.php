<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TaskScore;
use App\Support\JalaliCalendar;
use Carbon\Carbon;

class WeeklyTeamDigestService
{
    public function __construct(
        private readonly IranRouterClient $iranRouterClient,
        private readonly ScoreBreakdownService $scoreBreakdownService,
        private readonly StreakCompetitionService $streakCompetitionService,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    /**
     * @return array{sent: bool, used_llm: bool}
     */
    public function notify(): array
    {
        $breakdown = $this->scoreBreakdownService->formatWeek();
        $streakView = $this->streakCompetitionService->view();
        $champion = $this->streakCompetitionService->weekChampion();
        $facts = $this->factsBlock($champion);
        $narrative = $this->llmNarrative($facts, $breakdown);
        $usedLlm = $narrative !== null;

        $text = implode("\n\n", array_filter([
            '📊 خلاصه هفتگی تیم',
            $narrative,
            $facts,
            $breakdown,
            $streakView['text'],
        ]));

        $this->telegramNotifier->send($text);

        return [
            'sent' => true,
            'used_llm' => $usedLlm,
        ];
    }

    /**
     * @param  array{display: string, score: int, key: string}|null  $champion
     */
    private function factsBlock(?array $champion): string
    {
        $timezone = JalaliCalendar::timezone();
        $from = Carbon::now($timezone)->startOfWeek(Carbon::SATURDAY)->utc();
        $to = Carbon::now($timezone)->startOfWeek(Carbon::SATURDAY)->addWeek()->subSecond()->utc();
        $month = JalaliCalendar::monthKey(Carbon::now($timezone));

        $commitCount = CommitScore::query()
            ->where('month', $month)
            ->where('score', '>', 0)
            ->whereBetween('committed_at', [$from, $to])
            ->count();

        $taskCount = TaskScore::query()
            ->where('month', $month)
            ->where('is_code', false)
            ->where('score', '>', 0)
            ->whereBetween('completed_at', [$from, $to])
            ->count();

        $lines = [
            'آمار خام هفته:',
            '• کامیت امتیازدار: '.$commitCount,
            '• تسک غیرکدی امتیازدار: '.$taskCount,
        ];

        if ($champion !== null) {
            $lines[] = '• قهرمان هفته: '.$champion['display'].' ('.number_format($champion['score']).')';
        }

        return implode("\n", $lines);
    }

    private function llmNarrative(string $facts, string $breakdown): ?string
    {
        try {
            $this->iranRouterClient->requireApiKey();
        } catch (\Throwable) {
            return null;
        }

        try {
            $raw = $this->iranRouterClient->chat([
                [
                    'role' => 'system',
                    'content' => 'تو مدیر فنی مهربان یک تیم نرم‌افزاری هستی. فقط فارسی، حداکثر ۸ خط، بدون بولت‌پوینت زیاد. جمع‌بندی انگیزشی و دقیق از عملکرد هفته بده. عدد جعلی نساز.',
                ],
                [
                    'role' => 'user',
                    'content' => "آمار:\n{$facts}\n\nجزئیات:\n{$breakdown}",
                ],
            ], 500);

            $raw = trim($raw);

            return $raw !== '' ? $raw : null;
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
