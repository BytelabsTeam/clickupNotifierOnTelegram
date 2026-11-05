<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TeamTask;
use App\Support\JalaliCalendar;
use Carbon\Carbon;

class GitHubLeaderboardService
{
    public function __construct(private readonly TeamIdentityService $identity)
    {
    }

    public function formatTopToday(): string
    {
        $timezone = JalaliCalendar::timezone();
        $from = Carbon::now($timezone)->startOfDay();
        $to = Carbon::now($timezone)->endOfDay();

        return $this->format($from, $to, 'برترین‌های امروز');
    }

    public function formatTopWeek(): string
    {
        $timezone = JalaliCalendar::timezone();
        $from = Carbon::now($timezone)->startOfWeek(Carbon::SATURDAY);
        $to = $from->copy()->addWeek()->subSecond();

        return $this->format($from, $to, 'برترین‌های این هفته');
    }

    public function formatTopMonth(): string
    {
        $month = JalaliCalendar::currentMonth();

        return $this->format($month['from'], $month['to'], 'برترین‌های این ماه');
    }

    private function format(Carbon $from, Carbon $to, string $title): string
    {
        // Leaderboard is DB-only. Scoring runs via cron (github:score-commits).
        $month = JalaliCalendar::monthKey(Carbon::now(JalaliCalendar::timezone()));
        $counts = [];

        $commitRows = CommitScore::query()
            ->where('month', $month)
            ->whereNotNull('committed_at')
            ->where('committed_at', '>=', $from->copy()->utc())
            ->where('committed_at', '<=', $to->copy()->utc())
            ->get();

        foreach ($commitRows as $row) {
            $identity = $this->identity->resolveGithubLogin($row->login);

            if ($identity === null) {
                continue;
            }

            $this->addScore($counts, $identity, (int) $row->score);
        }

        $taskRows = TeamTask::query()
            ->where('is_code', false)
            ->where('score', '>', 0)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $from->copy()->utc())
            ->where('completed_at', '<=', $to->copy()->utc())
            ->get();

        foreach ($taskRows as $row) {
            $this->addScore($counts, [
                'key' => 'tg:'.$row->assignee_telegram_id,
                'display' => (string) ($row->assignee_name ?: '@'.$row->assignee_username),
            ], (int) $row->score);
        }

        if ($counts === []) {
            return $title.":\nدر این بازه امتیازی ثبت نشده.".$this->scoringRulesFooter();
        }

        uasort($counts, static function (array $a, array $b): int {
            return $b['lines'] <=> $a['lines']
                ?: strcmp($a['display'], $b['display']);
        });

        $lines = [$title.':', ''];
        $rank = 1;

        foreach ($counts as $person) {
            $medal = match ($rank) {
                1 => '🥇 ',
                2 => '🥈 ',
                3 => '🥉 ',
                default => $rank.'. ',
            };

            $lines[] = $medal.$person['display'].' — '.number_format($person['lines']).' امتیاز';
            $rank++;
        }

        return implode("\n", $lines).$this->scoringRulesFooter();
    }

    /**
     * @param  array<string, array{display: string, lines: int}>  $counts
     * @param  array{key: string, display: string}  $identity
     */
    private function addScore(array &$counts, array $identity, int $score): void
    {
        if ($identity['key'] === '' || $score <= 0) {
            return;
        }

        $counts[$identity['key']] ??= ['display' => $identity['display'], 'lines' => 0];
        $counts[$identity['key']]['lines'] += $score;
    }

    private function scoringRulesFooter(): string
    {
        return implode("\n", [
            '',
            '',
            'معیار امتیاز دهی:',
            '🤖 کامیت GitHub با LLM (۰ تا ۳۰۰۰)',
            '📋 تسک‌های تیم با LLM (۰ تا ۵۰۰۰) — تسک‌های کدی صفر می‌مانند چون در Git امتیاز گرفته‌اند',
            '⭐ فقط کارهای واقعاً خفن نزدیک سقف امتیاز می‌گیرند',
            '📅 فقط همین ماه شمسی (به وقت ایران) محاسبه و ذخیره می‌شود',
        ]);
    }
}
