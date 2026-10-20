<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class GitHubLeaderboardService
{
    public function __construct(
        private readonly GitHubClient $gitHubClient,
        private readonly UserNameResolver $userNameResolver,
        private readonly TelegramUsernameResolver $telegramUsernameResolver,
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
        if ($this->gitHubClient->repos() === []) {
            return $title.":\nلیست ریپوها خالی است. GITHUB_ORG یا GITHUB_REPOS را در .env پر کن.";
        }

        try {
            $this->gitHubClient->requireToken();
        } catch (\RuntimeException) {
            return $title.":\nتوکن گیت‌هاب تنظیم نشده. GITHUB_TOKEN را در .env بگذار.";
        }

        $cacheKey = sprintf('github:score:v6:%d:%d', $from->getTimestamp(), $to->getTimestamp());

        /** @var array{commits: list<array{login: ?string, email: ?string, name: ?string, additions: int, committed_at: string}>, skipped: list<array{repo: string, reason: string}>} $result */
        $result = Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            fn (): array => $this->gitHubClient->commitAdditions($from, $to)
        );

        $commits = $result['commits'] ?? [];
        /** @var list<array{repo: string, reason: string}> $skipped */
        $skipped = $result['skipped'] ?? [];
        $counts = [];
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();

        foreach ($commits as $commit) {
            $committedAt = $commit['committed_at'] !== ''
                ? Carbon::parse($commit['committed_at'])->getTimestamp()
                : null;

            if ($committedAt !== null && ($committedAt < $fromTs || $committedAt > $toTs)) {
                continue;
            }

            $identity = $this->identity($commit['login'], $commit['email'], $commit['name']);

            if ($identity['key'] === '') {
                continue;
            }

            $counts[$identity['key']] ??= [
                'display' => $this->telegramUsernameResolver->formatDisplayName(
                    $this->userNameResolver->resolve($identity['email'], $identity['username']),
                    $identity['email']
                ),
                'lines' => 0,
            ];
            $counts[$identity['key']]['lines'] += $commit['additions'];
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
            '🟢 هر خط کد اضافه شده به پروژه ۱ امتیاز مثبت',
            '🔴 هر خط کد کم شده از پروژه ۲ امتیاز مثبت',
        ]);
    }

    /**
     * @return array{key: string, email: ?string, username: ?string}
     */
    private function identity(?string $login, ?string $email, ?string $name): array
    {
        $login = $login !== null && $login !== '' ? mb_strtolower(ltrim(trim($login), '@')) : '';
        $email = $email !== null && $email !== '' ? mb_strtolower(trim($email)) : '';
        $name = $name !== null ? trim($name) : '';

        $mappedEmail = $login !== '' ? $this->emailForGithubLogin($login) : null;

        if ($mappedEmail === null && $email !== '') {
            $mappedEmail = $this->knownEmail($email);
        }

        $key = $mappedEmail
            ?? ($login !== '' ? 'login:'.$login : null)
            ?? ($email !== '' ? 'email:'.$email : null)
            ?? ($name !== '' ? 'name:'.mb_strtolower($name) : '');

        return [
            'key' => $key,
            'email' => $mappedEmail ?? ($email !== '' ? $email : null),
            'username' => $login !== '' ? $login : ($name !== '' ? $name : null),
        ];
    }

    private function emailForGithubLogin(string $login): ?string
    {
        foreach (config('github.user_logins', []) as $email => $mapped) {
            if (mb_strtolower(ltrim(trim((string) $mapped), '@')) === $login) {
                return mb_strtolower((string) $email);
            }
        }

        return null;
    }

    private function knownEmail(string $email): ?string
    {
        foreach (array_keys(config('github.user_logins', [])) as $mappedEmail) {
            if (mb_strtolower((string) $mappedEmail) === $email) {
                return $email;
            }
        }

        foreach (array_keys(config('clickup.user_names', [])) as $mappedEmail) {
            if (mb_strtolower((string) $mappedEmail) === $email) {
                return $email;
            }
        }

        return null;
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}
