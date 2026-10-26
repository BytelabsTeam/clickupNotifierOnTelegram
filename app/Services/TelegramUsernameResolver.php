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
