<?php

namespace App\Services;

class TelegramUsernameResolver
{
    public function __construct(private readonly ScoreIdentityResolver $scoreIdentityResolver)
    {
    }

    public function resolve(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            mb_strtolower(trim($email)),
            $this->scoreIdentityResolver->canonicalizeEmail($email),
        ])));

        foreach ($candidates as $candidate) {
            foreach (config('telegram.usernames', []) as $mappedEmail => $username) {
                if (mb_strtolower((string) $mappedEmail) !== $candidate) {
                    continue;
                }

                if (! is_string($username) || $username === '') {
                    continue;
                }

                return ltrim(trim($username), '@');
            }
        }

        return null;
    }

    public function emailForUsername(?string $telegramUsername): ?string
    {
        $username = mb_strtolower(ltrim(trim((string) $telegramUsername), '@'));

        if ($username === '') {
            return null;
        }

        foreach (config('telegram.usernames', []) as $mappedEmail => $mappedUsername) {
            if (! is_string($mappedUsername) || $mappedUsername === '') {
                continue;
            }

            if (mb_strtolower(ltrim(trim($mappedUsername), '@')) !== $username) {
                continue;
            }

            return $this->scoreIdentityResolver->canonicalizeEmail((string) $mappedEmail);
        }

        return null;
    }

    public function isAdmin(?string $telegramUsername): bool
    {
        $username = mb_strtolower(ltrim(trim((string) $telegramUsername), '@'));

        if ($username === '') {
            return false;
        }

        foreach (config('telegram.admin_usernames', []) as $admin) {
            if (mb_strtolower(ltrim(trim((string) $admin), '@')) === $username) {
                return true;
            }
        }

        return false;
    }

    public function formatDisplayName(string $displayName, ?string $email): string
    {
        $username = $this->resolve($email);

        if ($username === null) {
            return $displayName;
        }

        // @username tags the person in Telegram groups; Persian name stays readable.
        return "{$displayName} (@{$username})";
    }

    public function formatMention(string $displayName, ?string $email): string
    {
        $username = $this->resolve($email);

        if ($username === null) {
            return $displayName;
        }

        return '@'.$username;
    }
}
