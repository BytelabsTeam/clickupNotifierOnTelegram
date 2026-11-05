<?php

namespace Database\Seeders;

use App\Models\TeamMember;
use Illuminate\Database\Seeder;

/**
 * Seeds the known team. Telegram numeric id is the identity key; re-running is safe.
 */
class TeamMemberSeeder extends Seeder
{
    public function run(): void
    {
        $members = [
            [
                'telegram_user_id' => 5550717851,
                'telegram_username' => 'armin_ghaderi',
                'github_login' => null,
                'display_name' => 'آرمین',
                'is_admin' => true,
            ],
            [
                'telegram_user_id' => 1080648232,
                'telegram_username' => 'ageek',
                'github_login' => 'DeAref',
                'display_name' => 'عارف',
                'is_admin' => false,
            ],
            [
                'telegram_user_id' => 103063134,
                'telegram_username' => 'Call_oF_Dutty',
                'github_login' => 'mahdinasiri13861386',
                'display_name' => 'حسن',
                'is_admin' => false,
            ],
            [
                'telegram_user_id' => 353166574,
                'telegram_username' => 'HamidTad',
                'github_login' => 'HAMIDTAD',
                'display_name' => 'حمید',
                'is_admin' => false,
            ],
            [
                'telegram_user_id' => 2006061782,
                'telegram_username' => 'mrs_yt',
                'github_login' => 'MRSYTp',
                'display_name' => 'محمدرضا',
                'is_admin' => false,
            ],
        ];

        foreach ($members as $member) {
            TeamMember::query()->updateOrCreate(
                ['telegram_user_id' => $member['telegram_user_id']],
                $member + ['is_active' => true],
            );
        }
    }
}
