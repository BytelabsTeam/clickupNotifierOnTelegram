<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TaskScore;
use App\Support\JalaliCalendar;
use Carbon\Carbon;

class ScoreReportService
{
    public function __construct(
        private readonly ScoreIdentityResolver $scoreIdentityResolver,
        private readonly TelegramUsernameResolver $telegramUsernameResolver,
        private readonly UserNameResolver $userNameResolver,
    ) {
    }

    public function formatMyScore(string $telegramUsername, string $period = 'today'): string
    {
        $email = $this->telegramUsernameResolver->emailForUsername($telegramUsername);

        if ($email === null) {
            return "یوزرنیم @{$telegramUsername} در TELEGRAM_USERNAMES نگاشت نشده.";
        }

        [$from, $to, $title] = $this->periodBounds($period);
        $identity = $this->scoreIdentityResolver->resolve(null, $email, null);
        $display = $this->userNameResolver->resolve($email, $telegramUsername);

        $items = $this->collectItems($from, $to);
        $mine = array_values(array_filter(
            $items,
            fn (array $item): bool => $item['key'] === $identity['key']
        ));

        if ($mine === []) {
            return "{$title} — {$display}:\nدر این بازه امتیازی برای تو ثبت نشده.";
        }

        $commitTotal = 0;
        $taskTotal = 0;

        foreach ($mine as $item) {
            if ($item['source'] === 'commit') {
                $commitTotal += $item['score'];
            } else {
                $taskTotal += $item['score'];
            }
        }

        usort($mine, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $lines = [
            "{$title} — {$display}:",
            '',
            '💻 GitHub: '.number_format($commitTotal),
            '📋 ClickUp غیرکدی: '.number_format($taskTotal),
            '⭐ جمع: '.number_format($commitTotal + $taskTotal),
            '',
            'برترین کارها:',
        ];

        foreach (array_slice($mine, 0, 5) as $index => $item) {
            $n = $index + 1;
            $lines[] = "{$n}) {$item['label']} — ".number_format($item['score']);

            if ($item['detail'] !== '') {
                $lines[] = '   '.$item['detail'];
            }
        }

        return implode("\n", $lines);
    }

    public function formatDailyHighlight(?Carbon $day = null): string
    {
        $timezone = JalaliCalendar::timezone();
        $day = ($day ?? Carbon::now($timezone))->copy()->timezone($timezone);
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $items = $this->collectItems($from, $to);

        if ($items === []) {
            return 'خفن‌ترین کار امروز هنوز ثبت نشده.';
        }

        usort($items, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $items[0];

        $lines = [
            '🔥 خفن‌ترین کار امروز',
            '',
            $best['person'].' — '.number_format($best['score']).' امتیاز',
            $best['label'],
        ];

        if ($best['detail'] !== '') {
            $lines[] = $best['detail'];
        }

        if (count($items) > 1) {
            $lines[] = '';
            $lines[] = 'نزدیک‌ترین‌ها:';

            foreach (array_slice($items, 1, 2) as $item) {
                $lines[] = '• '.$item['person'].' — '.number_format($item['score']).' — '.$item['short'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function periodBounds(string $period): array
    {
        $timezone = JalaliCalendar::timezone();
        $now = Carbon::now($timezone);

        return match ($period) {
            'week' => [
                $now->copy()->startOfWeek(Carbon::SATURDAY),
                $now->copy()->startOfWeek(Carbon::SATURDAY)->addWeek()->subSecond(),
                'امتیاز این هفته',
            ],
            'month' => [
                JalaliCalendar::currentMonth($now)['from'],
                JalaliCalendar::currentMonth($now)['to'],
                'امتیاز این ماه',
            ],
            default => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                'امتیاز امروز',
            ],
        };
    }

    /**
     * @return list<array{
     *   key: string,
     *   person: string,
     *   source: string,
     *   score: int,
     *   label: string,
     *   short: string,
     *   detail: string
     * }>
     */
    private function collectItems(Carbon $from, Carbon $to): array
    {
        $month = JalaliCalendar::monthKey(Carbon::now(JalaliCalendar::timezone()));
        $items = [];

        $commits = CommitScore::query()
            ->where('month', $month)
            ->where('score', '>', 0)
            ->whereNotNull('committed_at')
            ->where('committed_at', '>=', $from->copy()->utc())
            ->where('committed_at', '<=', $to->copy()->utc())
            ->get();

        foreach ($commits as $row) {
            $identity = $this->scoreIdentityResolver->resolve($row->login, $row->email, $row->name);
            $subject = $this->commitSubject((string) ($row->message ?? ''));
            $repo = (string) $row->repo;
            $shortRepo = str_contains($repo, '/') ? substr($repo, strrpos($repo, '/') + 1) : $repo;

            $items[] = [
                'key' => $identity['key'],
                'person' => $this->telegramUsernameResolver->formatDisplayName(
                    $this->userNameResolver->resolve($identity['email'], $identity['username']),
                    $identity['email']
                ),
                'source' => 'commit',
                'score' => (int) $row->score,
                'label' => "💻 کامیت #{$shortRepo}: {$subject}",
                'short' => $subject,
                'detail' => '',
            ];
        }

        $tasks = TaskScore::query()
            ->where('month', $month)
            ->where('is_code', false)
            ->where('score', '>', 0)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $from->copy()->utc())
            ->where('completed_at', '<=', $to->copy()->utc())
            ->get();

        foreach ($tasks as $row) {
            $identity = $this->scoreIdentityResolver->resolve(null, $row->email, $row->username ?? $row->name);
            $taskName = (string) $row->task_name;
            $reason = trim((string) ($row->reason ?? ''));

            $items[] = [
                'key' => $identity['key'],
                'person' => $this->telegramUsernameResolver->formatDisplayName(
                    $this->userNameResolver->resolve($identity['email'], $identity['username']),
                    $identity['email']
                ),
                'source' => 'task',
                'score' => (int) $row->score,
                'label' => "📋 تسک: {$taskName}",
                'short' => $taskName,
                'detail' => $reason !== '' ? "دلیل: {$reason}" : '',
            ];
        }

        return $items;
    }

    private function commitSubject(string $message): string
    {
        $first = trim(explode("\n", $message, 2)[0] ?? '');

        if ($first === '') {
            return '(بدون پیام)';
        }

        return mb_strlen($first) > 120 ? mb_substr($first, 0, 120).'…' : $first;
    }
}
