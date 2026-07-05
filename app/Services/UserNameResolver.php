<?php

namespace App\Services;

class UserNameResolver
{
    public function resolve(?string $email, ?string $username): string
    {
        if ($email !== null && $email !== '') {
            $mappedName = config('clickup.user_names')[$email] ?? null;

            if (is_string($mappedName) && $mappedName !== '') {
                return $mappedName;
            }
        }

        if (is_string($username) && $username !== '') {
            return $username;
        }

        return 'کاربر ناشناس';
    }
}
