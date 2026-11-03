<?php

namespace Tests\Unit;

use App\Models\TaskScore;
use App\Services\ScoreOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScoreOverrideServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Tehran',
            'clickup.api_token' => 'pk_test',
            'clickup.team_id' => '123',
            'telegram.admin_usernames' => ['aref_telegram'],
            'scoring.email_aliases' => [],
        ]);
    }

    public function test_admin_can_fix_score_for_existing_task(): void
    {
        TaskScore::query()->create([
            'task_id' => 'abc123',
            'email' => 'aref@example.com',
            'username' => 'Aref',
            'task_name' => 'طراحی',
            'is_code' => true,
            'score' => 0,
            'reason' => 'code',
            'completed_at' => now('UTC'),
            'month' => '1404-08',
        ]);

        Http::fake([
            'api.clickup.com/*' => Http::response([
                'id' => 'abc123',
                'name' => 'طراحی بنر',
                'status' => ['type' => 'closed', 'status' => 'complete'],
                'assignees' => [
                    ['email' => 'aref@example.com', 'username' => 'Aref'],
                ],
            ], 200),
        ]);

        $text = app(ScoreOverrideService::class)->fixScore('abc123', 1800, 'دستی', 'aref_telegram');

        $this->assertStringContainsString('1,800', $text);
        $this->assertDatabaseHas('task_scores', [
            'task_id' => 'abc123',
            'is_code' => 0,
            'score' => 1800,
        ]);
    }

    public function test_non_admin_cannot_override(): void
    {
        $this->expectException(\RuntimeException::class);

        app(ScoreOverrideService::class)->fixScore('abc123', 100, null, 'random_user');
    }
}
