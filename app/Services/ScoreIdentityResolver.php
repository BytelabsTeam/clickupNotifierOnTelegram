<?php

namespace App\Services;

class ScoreIdentityResolver
{
    /**
     * Resolve a stable identity key for leaderboard aggregation.
     *
     * @return array{key: string, email: ?string, username: ?string}
     */
    public function resolve(?string $login, ?string $email, ?string $name): array
    {
        $login = $login !== null && $login !== '' ? mb_strtolower(ltrim(trim($login), '@')) : '';
        $email = $email !== null && $email !== '' ? mb_strtolower(trim($email)) : '';
        $name = $name !== null ? trim($name) : '';

        $mappedEmail = $login !== '' ? $this->emailForGithubLogin($login) : null;

        if ($mappedEmail === null && $email !== '') {
            $mappedEmail = $this->knownEmail($email) ?? $email;
        }

        if ($mappedEmail !== null) {
            $mappedEmail = $this->canonicalizeEmail($mappedEmail);
        }

        $key = $mappedEmail
            ?? ($login !== '' ? 'login:'.$login : null)
            ?? ($email !== '' ? 'email:'.$this->canonicalizeEmail($email) : null)
            ?? ($name !== '' ? 'name:'.mb_strtolower($name) : '');

        return [
            'key' => $key,
            'email' => $mappedEmail ?? ($email !== '' ? $this->canonicalizeEmail($email) : null),
            'username' => $login !== '' ? $login : ($name !== '' ? $name : null),
        ];
    }

    public function canonicalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $aliases = config('scoring.email_aliases', []);

        if (! is_array($aliases) || $aliases === []) {
            return $email;
        }

        $seen = [];

        while (isset($aliases[$email]) && is_string($aliases[$email]) && $aliases[$email] !== '') {
            if (isset($seen[$email])) {
                break;
            }

            $seen[$email] = true;
            $email = mb_strtolower(trim($aliases[$email]));
        }

        return $email;
    }

    private function emailForGithubLogin(string $login): ?string
    {
        foreach (config('github.user_logins', []) as $email => $mapped) {
            if (mb_strtolower(ltrim(trim((string) $mapped), '@')) === $login) {
                return $this->canonicalizeEmail(mb_strtolower((string) $email));
            }
        }

        return null;
    }

    private function knownEmail(string $email): ?string
    {
        $email = mb_strtolower(trim($email));

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

        foreach (array_keys(config('scoring.email_aliases', [])) as $alias) {
            if (mb_strtolower((string) $alias) === $email) {
                return $email;
            }
        }

        foreach (config('scoring.email_aliases', []) as $canonical) {
            if (is_string($canonical) && mb_strtolower($canonical) === $email) {
                return $email;
            }
        }

        return null;
    }
}
