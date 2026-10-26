<?php

namespace App\Services;

class UserNameResolver
{
    public function __construct(private readonly ScoreIdentityResolver $scoreIdentityResolver)
    {
    }

    public function resolve(?string $email, ?string $username): string
    {
        if ($email !== null && $email !== '') {
            $candidates = array_values(array_unique(array_filter([
                mb_strtolower(trim($email)),
                $this->scoreIdentityResolver->canonicalizeEmail($email),
            ])));

            foreach ($candidates as $candidate) {
                foreach (config('clickup.user_names', []) as $mappedEmail => $mappedName) {
                    if (mb_strtolower((string) $mappedEmail) !== $candidate) {
                        continue;
                    }

                    if (is_string($mappedName) && $mappedName !== '') {
                        return $mappedName;
                    }
                }
            }
        }

        if (is_string($username) && $username !== '') {
            return $username;
        }

        return 'کاربر ناشناس';
    }
}
