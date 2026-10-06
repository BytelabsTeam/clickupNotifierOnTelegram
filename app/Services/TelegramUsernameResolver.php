<?php

namespace App\Services;

class TelegramUsernameResolver
{
    public function resolve(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        $username = config('telegram.usernames')[$email] ?? null;

        if (! is_string($username) || $username === '') {
            return null;
        }

        return ltrim(trim($username), '@');
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
