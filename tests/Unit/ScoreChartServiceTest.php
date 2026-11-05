<?php

namespace Tests\Unit;

use App\Models\CommitScore;
use App\Models\TeamMember;
use App\Models\TeamTask;
use App\Services\ScoreChartService;
use App\Support\JalaliCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreChartServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Tehran']);

        TeamMember::query()->create(['telegram_user_id' => 1080648232, 'telegram_username' => 'ageek', 'github_login' => 'DeAref', 'display_name' => 'عارف']);
        TeamMember::query()->create(['telegram_user_id' => 103063134, 'telegram_username' => 'Call_oF_Dutty', 'github_login' => 'mahdinasiri13861386', 'display_name' => 'حسن']);
    }

    public function test_chart_ranks_people_with_bars_and_percentages(): void
    {
        $now = Carbon::parse('2026-11-04 12:00:00', 'Asia/Tehran');
        $month = JalaliCalendar::monthKey($now);

        CommitScore::query()->create(['sha' => 'a', 'repo' => 'x/y', 'login' => 'DeAref', 'message' => 'm', 'score' => 900, 'committed_at' => $now->copy()->utc(), 'month' => $month]);
        CommitScore::query()->create(['sha' => 'b', 'repo' => 'x/y', 'login' => 'mahdinasiri13861386', 'message' => 'm', 'score' => 300, 'committed_at' => $now->copy()->utc(), 'month' => $month]);

        TeamTask::query()->create([
            'title' => 'کار غیرکدی', 'status' => 'done', 'priority' => 'normal',
            'assignee_telegram_id' => 103063134, 'assignee_name' => 'حسن', 'created_by_telegram_id' => 103063134,
            'is_code' => false, 'score' => 300, 'completed_at' => $now->copy()->utc(),
        ]);

        $text = app(ScoreChartService::class)->formatMonth($now);

        $this->assertStringContainsString($month, $text);
        $this->assertStringContainsString('1) عارف', $text);
        $this->assertStringContainsString('900 (60%)', $text);
        $this->assertStringContainsString('2) حسن', $text);
        $this->assertStringContainsString('600 (40%)', $text);
        $this->assertStringContainsString('جمع تیم: 1,500', $text);
        $this->assertStringContainsString('█', $text);
    }

    public function test_chart_handles_empty_month(): void
    {
        $text = app(ScoreChartService::class)->formatMonth(Carbon::parse('2026-11-04 12:00:00', 'Asia/Tehran'));

        $this->assertStringContainsString('هنوز امتیازی ثبت نشده', $text);
    }

    public function test_unknown_github_login_is_excluded_from_chart(): void
    {
        $now = Carbon::parse('2026-11-04 12:00:00', 'Asia/Tehran');

        CommitScore::query()->create(['sha' => 'z', 'repo' => 'x/y', 'login' => 'stranger', 'message' => 'm', 'score' => 999, 'committed_at' => $now->copy()->utc(), 'month' => JalaliCalendar::monthKey($now)]);

        $text = app(ScoreChartService::class)->formatMonth($now);

        $this->assertStringNotContainsString('999', $text);
    }
}
