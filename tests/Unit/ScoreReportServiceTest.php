<?php

namespace Tests\Unit;

use App\Models\CommitScore;
use App\Models\TaskScore;
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

        config([
            'app.timezone' => 'Asia/Tehran',
            'telegram.usernames' => [
                'aref@example.com' => 'aref_telegram',
            ],
            'clickup.user_names' => [
                'aref@example.com' => 'عارف',
            ],
            'github.user_logins' => [
                'aref@example.com' => 'DeAref',
            ],
        ]);
    }

    public function test_it_formats_my_score_breakdown(): void
    {
        $now = now('Asia/Tehran');
        $month = JalaliCalendar::monthKey($now);

        CommitScore::query()->create([
            'sha' => str_repeat('a', 40),
            'repo' => 'BytelabsTeam/MegaShop',
            'login' => 'DeAref',
            'email' => 'aref@example.com',
            'name' => 'Aref',
            'message' => "feat: checkout\n\nbody",
            'score' => 900,
            'committed_at' => $now->copy()->utc(),
            'month' => $month,
        ]);

        TaskScore::query()->create([
            'task_id' => 'task_design',
            'email' => 'aref@example.com',
            'username' => 'Aref',
            'name' => 'Aref',
            'task_name' => 'طراحی بنر',
            'is_code' => false,
            'score' => 1200,
            'reason' => 'کار خفن طراحی',
            'completed_at' => $now->copy()->utc(),
            'month' => $month,
        ]);

        $text = app(ScoreReportService::class)->formatMyScore('aref_telegram', 'today');

        $this->assertStringContainsString('امتیاز امروز', $text);
        $this->assertStringContainsString('GitHub: 900', $text);
        $this->assertStringContainsString('ClickUp غیرکدی: 1,200', $text);
        $this->assertStringContainsString('جمع: 2,100', $text);
        $this->assertStringContainsString('طراحی بنر', $text);
        $this->assertStringContainsString('دلیل: کار خفن طراحی', $text);
    }

    public function test_it_formats_daily_highlight(): void
    {
        $now = now('Asia/Tehran');
        $month = JalaliCalendar::monthKey($now);

        TaskScore::query()->create([
            'task_id' => 'best',
            'email' => 'aref@example.com',
            'username' => 'Aref',
            'task_name' => 'ریسرچ بازار',
            'is_code' => false,
            'score' => 2500,
            'reason' => 'تأثیر بالا',
            'completed_at' => $now->copy()->utc(),
            'month' => $month,
        ]);

        $text = app(ScoreReportService::class)->formatDailyHighlight($now);

        $this->assertStringContainsString('خفن‌ترین کار امروز', $text);
        $this->assertStringContainsString('ریسرچ بازار', $text);
        $this->assertStringContainsString('2,500', $text);
    }
}
