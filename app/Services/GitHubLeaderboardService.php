<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TaskScore;
use Carbon\Carbon;

class GitHubLeaderboardService
{
    public function __construct(
        private readonly GitHubClient $gitHubClient,
        private readonly CommitScoreSyncService $commitScoreSyncService,
        private readonly TaskScoreSyncService $taskScoreSyncService,
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
        $timezone = $this->timezone();
        $from = Carbon::now($timezone)->startOfMonth();
        $to = Carbon::now($timezone)->endOfMonth();

        return $this->format($from, $to, 'برترین‌های این ماه');
    }

    private function format(Carbon $from, Carbon $to, string $title): string
    {
        $hasGithub = $this->gitHubClient->repos() !== [];
        $hasClickUp = $this->clickUpClient->isConfigured();

        if (! $hasGithub && ! $hasClickUp) {
            return $title.":\nنه GitHub و نه ClickUp برای امتیازدهی تنظیم شده.";
        }

        $skipped = [];

        if ($hasGithub) {
            try {
                $this->gitHubClient->requireToken();
            } catch (\RuntimeException) {
                return $title.":\nتوکن گیت‌هاب تنظیم نشده. GITHUB_TOKEN را در .env بگذار.";
            }
        }

        try {
            app(IranRouterClient::class)->requireApiKey();
        } catch (\RuntimeException) {
            return $title.":\nکلید IranRouter تنظیم نشده. IRANROUTER_API_KEY را در .env بگذار.";
        }

        if ($hasGithub) {
            $sync = $this->commitScoreSyncService->syncCurrentMonth();
            $skipped = $sync['skipped'] ?? [];
        }

        if ($hasClickUp) {
            $this->taskScoreSyncService->syncCurrentMonth();
        }

        $month = Carbon::now($this->timezone())->format('Y-m');
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
            $empty = $title.":\nدر این بازه امتیازی ثبت نشده.";

            if ($skipped !== []) {
                $empty .= $this->skippedFooter($skipped);
            }

            return $empty.$this->scoringRulesFooter();
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

        $message = implode("\n", $lines);

        if ($skipped !== []) {
            $message .= $this->skippedFooter($skipped);
        }

        return $message.$this->scoringRulesFooter();
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

    /**
     * @param  list<array{repo: string, reason: string}>  $skipped
     */
    private function skippedFooter(array $skipped): string
    {
        $lines = [];

        foreach ($skipped as $item) {
            $repo = is_array($item) ? (string) ($item['repo'] ?? '') : (string) $item;
            $reason = is_array($item) ? (string) ($item['reason'] ?? '') : '';

            if ($repo === '') {
                continue;
            }

            $lines[] = $reason !== '' ? "{$repo} — {$reason}" : $repo;
        }

        if ($lines === []) {
            return '';
        }

        return "\n\nاین ریپوها امتیازگیری نشدند:\n- ".implode("\n- ", $lines);
    }

    private function scoringRulesFooter(): string
    {
        return implode("\n", [
            '',
            '',
            'معیار امتیاز دهی:',
            '🤖 کامیت GitHub با LLM (۰ تا ۵۰۰۰)',
            '📋 تسک Done غیرکدی ClickUp با LLM (۰ تا ۵۰۰۰) — تسک‌های کدی صفر می‌مانند چون در Git امتیاز گرفته‌اند',
            '⭐ فقط کارهای واقعاً خفن نزدیک سقف امتیاز می‌گیرند',
            '📅 فقط همین ماه محاسبه و ذخیره می‌شود',
        ]);
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}
