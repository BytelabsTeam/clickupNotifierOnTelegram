<?php

namespace Tests\Feature;

use App\Models\TeamMember;
use App\Services\TeamIdentityService;
use Database\Seeders\TeamMemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMemberSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_five_known_members_with_telegram_ids(): void
    {
        $this->seed(TeamMemberSeeder::class);

        $this->assertDatabaseCount('team_members', 5);
        $this->assertDatabaseHas('team_members', ['telegram_user_id' => 5550717851, 'is_admin' => true]);
        $this->assertDatabaseHas('team_members', ['telegram_user_id' => 1080648232, 'display_name' => 'عارف']);
        $this->assertDatabaseHas('team_members', ['telegram_user_id' => 103063134, 'display_name' => 'حسن']);
        $this->assertDatabaseHas('team_members', ['telegram_user_id' => 353166574, 'display_name' => 'حمید']);
        $this->assertDatabaseHas('team_members', ['telegram_user_id' => 2006061782, 'display_name' => 'محمدرضا']);
    }

    public function test_seeding_twice_does_not_duplicate_members(): void
    {
        $this->seed(TeamMemberSeeder::class);
        $this->seed(TeamMemberSeeder::class);

        $this->assertDatabaseCount('team_members', 5);
    }

    public function test_github_commit_authors_map_to_the_right_person_case_insensitively(): void
    {
        $this->seed(TeamMemberSeeder::class);

        $identity = app(TeamIdentityService::class);

        $this->assertSame('tg:5550717851', $identity->resolveGithubLogin('mainarminghaderi')['key']);
        $this->assertSame('tg:1080648232', $identity->resolveGithubLogin('dearef')['key']);
        $this->assertSame('tg:103063134', $identity->resolveGithubLogin('mahdinasiri13861386')['key']);
        $this->assertSame('tg:353166574', $identity->resolveGithubLogin('hamidtad')['key']);
        $this->assertSame('tg:2006061782', $identity->resolveGithubLogin('MRSYTp')['key']);
        $this->assertNull($identity->resolveGithubLogin('unknown-person'));
    }

    public function test_only_armin_is_admin(): void
    {
        $this->seed(TeamMemberSeeder::class);

        $identity = app(TeamIdentityService::class);

        $this->assertTrue($identity->isAdmin(5550717851));
        $this->assertFalse($identity->isAdmin(1080648232));
        $this->assertSame(1, TeamMember::query()->where('is_admin', true)->count());
    }
}
