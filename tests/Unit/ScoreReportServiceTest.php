<?php

namespace Tests\Unit;

use App\Models\CommitScore;
use App\Models\TeamMember;
use App\Models\TeamTask;
use App\Services\ScoreReportService;
use App\Support\JalaliCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Tehran']);
    }

    public function test_it_formats_my_score_breakdown(): void
    {
        $now = now('Asia/Tehran');
        $month = JalaliCalendar::monthKey($now);

        TeamMember::query()->create([
            'telegram_user_id' => 1,
            'telegram_username' => 'aref_telegram',
            'github_login' => 'DeAref',
            'display_name' => 'عارف',
        ]);

        CommitScore::query()->create([
            'sha' => str_repeat('a', 40),
            'repo' => 'BytelabsTeam/MegaShop',
            'login' => 'DeAref',
            'email' => null,
            'name' => 'Aref',
            'message' => "feat: checkout\n\nbody",
            'score' => 900,
            'committed_at' => $now->copy()->utc(),
            'month' => $month,
        ]);

        TeamTask::query()->create([
            'title' => 'طراحی بنر',
            'status' => 'done',
            'priority' => 'normal',
            'assignee_telegram_id' => 1,
            'assignee_username' => 'aref_telegram',
            'assignee_name' => 'عارف',
            'created_by_telegram_id' => 1,
            'is_code' => false,
            'score' => 1200,
            'score_reason' => 'کار خفن طراحی',
            'completed_at' => $now->copy()->utc(),
        ]);

        $text = app(ScoreReportService::class)->formatMyScore(1, 'today');

        $this->assertStringContainsString('امتیاز امروز', $text);
        $this->assertStringContainsString('GitHub: 900', $text);
        $this->assertStringContainsString('تسک تیم غیرکدی: 1,200', $text);
        $this->assertStringContainsString('جمع: 2,100', $text);
        $this->assertStringContainsString('طراحی بنر', $text);
        $this->assertStringContainsString('دلیل: کار خفن طراحی', $text);
    }

    public function test_unknown_github_login_is_not_attributed_to_anyone(): void
    {
        $now = now('Asia/Tehran');

        CommitScore::query()->create([
            'sha' => str_repeat('b', 40),
            'repo' => 'BytelabsTeam/MegaShop',
            'login' => 'ghost-user',
            'email' => null,
            'name' => 'Ghost',
            'message' => 'unknown commit',
            'score' => 999,
            'committed_at' => $now->copy()->utc(),
            'month' => JalaliCalendar::monthKey($now),
        ]);

        $text = app(ScoreReportService::class)->formatDailyHighlight($now);

        $this->assertStringNotContainsString('999', $text);
    }

    public function test_it_formats_daily_highlight(): void
    {
        $now = now('Asia/Tehran');

        TeamTask::query()->create([
            'title' => 'ریسرچ بازار',
            'status' => 'done',
            'priority' => 'normal',
            'assignee_telegram_id' => 1,
            'assignee_username' => 'aref_telegram',
            'assignee_name' => 'عارف',
            'created_by_telegram_id' => 1,
            'is_code' => false,
            'score' => 2500,
            'score_reason' => 'تأثیر بالا',
            'completed_at' => $now->copy()->utc(),
        ]);

        $text = app(ScoreReportService::class)->formatDailyHighlight($now);

        $this->assertStringContainsString('خفن‌ترین کار امروز', $text);
        $this->assertStringContainsString('ریسرچ بازار', $text);
        $this->assertStringContainsString('2,500', $text);
    }
}
