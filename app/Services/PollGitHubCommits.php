<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PollGitHubCommits
{
    private const LAST_CHECK_CACHE_KEY = 'github_commits_poll:last_check';

    public function __construct(
        private readonly GitHubClient $gitHubClient,
        private readonly UserNameResolver $userNameResolver,
        private readonly CommitMessageTranslator $translator,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    public function poll(): int
    {
        if ($this->gitHubClient->repos() === []) {
            return 0;
        }

        $this->gitHubClient->requireToken();

        $from = $this->resolveSince();
        $to = Carbon::now('UTC');
        $notifiedCount = 0;

        foreach ($this->gitHubClient->recentCommits($from, $to) as $commit) {
            $cacheKey = 'github_commits_poll:notified:'.$commit['sha'];

            if (! Cache::add($cacheKey, true, now()->addDays(30))) {
                continue;
            }

            $identity = $this->identity($commit['login'], $commit['email'], $commit['name']);
            $displayName = $this->userNameResolver->resolve($identity['email'], $identity['username']);
            $translated = $this->translator->toPersian($commit['message']);
            $message = $this->telegramNotifier->formatCommitMessage(
                $displayName,
                $translated,
                $commit['repo_name']
            );

            $this->telegramNotifier->send($message);
            $notifiedCount++;
        }

        Cache::put(self::LAST_CHECK_CACHE_KEY, $to->toIso8601String(), now()->addDays(30));

        return $notifiedCount;
    }

    private function resolveSince(): Carbon
    {
        $lastCheck = Cache::get(self::LAST_CHECK_CACHE_KEY);

        if (is_string($lastCheck) && $lastCheck !== '') {
            return Carbon::parse($lastCheck, 'UTC')->subSeconds(30);
        }

        $lookbackMinutes = (int) config('github.commit_poll_lookback_minutes', 15);

        return Carbon::now('UTC')->subMinutes(max(1, $lookbackMinutes));
    }

    /**
     * @return array{email: ?string, username: ?string}
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

        return [
            'email' => $mappedEmail ?? ($email !== '' ? $email : null),
            'username' => $login !== '' ? $login : ($name !== '' ? $name : null),
        ];
    }

    private function emailForGithubLogin(string $login): ?string
    {
        foreach (config('github.user_logins', []) as $mappedEmail => $mapped) {
            if (mb_strtolower(ltrim(trim((string) $mapped), '@')) === $login) {
                return mb_strtolower((string) $mappedEmail);
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
}
