<?php

namespace App\Services;

use App\Models\TeamMember;

/**
 * Single source of truth for who is who.
 *
 * - Telegram numeric user id is the only identity key for tasks and permissions.
 * - GitHub login maps a commit author to a member (case-insensitive).
 * - Usernames are never used as keys; they are display/mention text only.
 */
class TeamIdentityService
{
    /**
     * @return array{key: string, member_id: ?int, display: string}|null
     */
    public function resolveGithubLogin(?string $login): ?array
    {
        $login = mb_strtolower(ltrim(trim((string) $login), '@'));

        if ($login === '') {
            return null;
        }

        $member = TeamMember::query()
            ->whereRaw('LOWER(github_login) = ?', [$login])
            ->first();

        if ($member === null) {
            return null;
        }

        return $this->identityFor($member);
    }

    /**
     * @return array{key: string, member_id: ?int, display: string}
     */
    public function identityFor(TeamMember $member): array
    {
        return [
            'key' => $member->telegram_user_id !== null
                ? 'tg:'.$member->telegram_user_id
                : 'member:'.$member->id,
            'member_id' => $member->id,
            'display' => $member->display_name,
        ];
    }

    public function findByTelegramId(int $telegramUserId): ?TeamMember
    {
        return TeamMember::query()->where('telegram_user_id', $telegramUserId)->first();
    }

    public function isAdmin(int $telegramUserId): bool
    {
        return TeamMember::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('is_admin', true)
            ->where('is_active', true)
            ->exists();
    }

    public function isMember(int $telegramUserId): bool
    {
        return TeamMember::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('is_active', true)
            ->exists();
    }
}
