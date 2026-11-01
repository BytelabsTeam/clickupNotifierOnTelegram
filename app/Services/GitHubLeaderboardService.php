<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TaskScore;
use App\Support\JalaliCalendar;
use Carbon\Carbon;

class GitHubLeaderboardService
{
    public function __construct(
        private readonly ScoreIdentityResolver $scoreIdentityResolver,
        private readonly UserNameResolver $userNameResolver,
        private readonly TelegramUsernameResolver $telegramUsernameResolver,
        private readonly ClickUpClient $clickUpClient,
    ) {
    }

    public function formatTopToday(): string
    {
        $timezone = $this->timezone();
        $from = Carbon::now($timezone)->startOfDay();
        $to = Carbon::now($timezone)->endOfDay();

        return $this->format($from, $to, 'برترین‌های امروز');
    }

    public function formatTopWeek(): string
    {
        $timezone = $this->timezone();
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
        $hasGithub = $this->githubConfigured();
        $hasClickUp = $this->clickUpClient->isConfigured();

        if (! $hasGithub && ! $hasClickUp) {
            return $title.":\nنه GitHub و نه ClickUp برای امتیازدهی تنظیم شده.";
        }

        // Leaderboard is DB-only. Scoring runs via cron (github:score-commits / clickup:score-tasks).
        $month = JalaliCalendar::monthKey(Carbon::now($this->timezone()));
        $counts = [];

        if ($hasGithub) {
            $commitRows = CommitScore::query()
                ->where('month', $month)
                ->whereNotNull('committed_at')
                ->where('committed_at', '>=', $from->copy()->utc())
                ->where('committed_at', '<=', $to->copy()->utc())
                ->get();

            foreach ($commitRows as $row) {
                $this->addScore(
                    $counts,
                    $this->scoreIdentityResolver->resolve($row->login, $row->email, $row->name),
                    (int) $row->score,
                );
            }
        }

        if ($hasClickUp) {
            $taskRows = TaskScore::query()
                ->where('month', $month)
                ->where('is_code', false)
                ->where('score', '>', 0)
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', $from->copy()->utc())
                ->where('completed_at', '<=', $to->copy()->utc())
                ->get();

            foreach ($taskRows as $row) {
                $this->addScore(
                    $counts,
                    $this->scoreIdentityResolver->resolve(null, $row->email, $row->username ?? $row->name),
                    (int) $row->score,
                );
            }
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
     * @param  array{key: string, email: ?string, username: ?string}  $identity
     */
    private function addScore(array &$counts, array $identity, int $score): void
    {
        if ($identity['key'] === '' || $score <= 0) {
            return;
        }

        $counts[$identity['key']] ??= [
            'display' => $this->telegramUsernameResolver->formatDisplayName(
                $this->userNameResolver->resolve($identity['email'], $identity['username']),
                $identity['email']
            ),
            'lines' => 0,
        ];
        $counts[$identity['key']]['lines'] += $score;
    }

    private function githubConfigured(): bool
    {
        $token = config('github.token');

        if (! is_string($token) || $token === '') {
            return false;
        }

        $org = config('github.org');
        $repos = config('github.repos', []);

        return (is_string($org) && trim($org) !== '')
            || (is_array($repos) && $repos !== []);
    }

    private function scoringRulesFooter(): string
    {
        return implode("\n", [
            '',
            '',
            'معیار امتیاز دهی:',
            '🤖 کامیت GitHub با LLM (۰ تا ۳۰۰۰)',
            '📋 تسک Done غیرکدی ClickUp با LLM (۰ تا ۵۰۰۰) — تسک‌های کدی صفر می‌مانند چون در Git امتیاز گرفته‌اند',
            '⭐ فقط کارهای واقعاً خفن نزدیک سقف امتیاز می‌گیرند',
            '📅 فقط همین ماه شمسی (به وقت ایران) محاسبه و ذخیره می‌شود',
        ]);
    }

    private function timezone(): string
    {
        return JalaliCalendar::timezone();
    }
}
