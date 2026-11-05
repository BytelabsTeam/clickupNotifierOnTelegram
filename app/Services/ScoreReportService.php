<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TeamMember;
use App\Models\TeamTask;
use App\Support\JalaliCalendar;
use Carbon\Carbon;

class ScoreReportService
{
    public function __construct(private readonly TeamIdentityService $identity)
    {
    }

    public function formatMyScore(int $telegramUserId, string $period = 'today'): string
    {
        $member = $this->identity->findByTelegramId($telegramUserId);

        if ($member === null) {
            return 'این حساب در لیست اعضای تیم ثبت نشده.';
        }

        [$from, $to, $title] = $this->periodBounds($period);
        $key = 'tg:'.$telegramUserId;
        $mine = array_values(array_filter(
            $this->collectItems($from, $to),
            fn (array $item): bool => $item['key'] === $key
        ));

        if ($mine === []) {
            return "{$title} — {$member->display_name}:\nدر این بازه امتیازی برای تو ثبت نشده.";
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
            "{$title} — {$member->display_name}:",
            '',
            '💻 GitHub: '.number_format($commitTotal),
            '📋 تسک تیم غیرکدی: '.number_format($taskTotal),
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
        $items = $this->collectItems($day->copy()->startOfDay(), $day->copy()->endOfDay());

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
     * @return list<array{key: string, person: string, source: string, score: int, label: string, short: string, detail: string}>
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
            $identity = $this->identity->resolveGithubLogin($row->login);

            if ($identity === null) {
                // Unknown GitHub login: never guess an owner; keep it out of personal scores.
                continue;
            }

            $subject = $this->commitSubject((string) ($row->message ?? ''));
            $repo = (string) $row->repo;
            $shortRepo = str_contains($repo, '/') ? substr($repo, strrpos($repo, '/') + 1) : $repo;

            $items[] = [
                'key' => $identity['key'],
                'person' => $identity['display'],
                'source' => 'commit',
                'score' => (int) $row->score,
                'label' => "💻 کامیت #{$shortRepo}: {$subject}",
                'short' => $subject,
                'detail' => '',
            ];
        }

        $tasks = TeamTask::query()
            ->where('is_code', false)
            ->where('score', '>', 0)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $from->copy()->utc())
            ->where('completed_at', '<=', $to->copy()->utc())
            ->get();

        foreach ($tasks as $row) {
            $taskName = (string) $row->title;
            $reason = trim((string) ($row->score_reason ?? ''));

            $items[] = [
                'key' => 'tg:'.$row->assignee_telegram_id,
                'person' => (string) ($row->assignee_name ?: '@'.$row->assignee_username),
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
