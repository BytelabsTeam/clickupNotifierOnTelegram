<?php

namespace Tests\Feature;

use App\Models\CommitScore;
use App\Models\ReportRun;
use App\Models\TeamMember;
use App\Models\TeamTask;
use App\Services\HistoryService;
use App\Services\WeeklyReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeeklyReportAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Tehran',
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => '-1001234567890',
            'app.cron_token' => 'secret-cron-token',
            'iranrouter.api_key' => 'ir-test-key',
            'iranrouter.base_url' => 'https://www.iranrouter.net/api/v1',
            'iranrouter.model' => 'openai/gpt-6-luna',
        ]);

        TeamMember::query()->create(['telegram_user_id' => 1080648232, 'telegram_username' => 'ageek', 'github_login' => 'DeAref', 'display_name' => 'عارف']);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    public function test_weekly_report_is_sent_on_friday_evening_tehran_only(): void
    {
        $service = app(WeeklyReportService::class);

        // Thursday evening Tehran: not yet
        $this->assertFalse($service->sendIfDue(Carbon::parse('2026-11-05 20:00:00', 'Asia/Tehran'))['sent']);
        // Friday 17:59 Tehran: not yet
        $this->assertFalse($service->sendIfDue(Carbon::parse('2026-11-06 17:59:00', 'Asia/Tehran'))['sent']);
    }

    public function test_weekly_report_is_idempotent_per_week(): void
    {
        $service = app(WeeklyReportService::class);
        $friday = Carbon::parse('2026-11-06 18:30:00', 'Asia/Tehran');

        $first = $service->sendIfDue($friday);
        $second = $service->sendIfDue($friday->copy()->addMinutes(10));

        $this->assertTrue($first['sent']);
        $this->assertFalse($second['sent']);
        $this->assertSame(1, ReportRun::query()->where('type', 'weekly')->count());
        Http::assertSentCount(1);
    }

    public function test_weekly_report_contains_best_commit_task_and_person_totals(): void
    {
        $friday = Carbon::parse('2026-11-06 18:30:00', 'Asia/Tehran');
        $saturdayMorning = Carbon::parse('2026-11-01 10:00:00', 'Asia/Tehran');

        CommitScore::query()->create([
            'sha' => 'best1',
            'repo' => 'BytelabsTeam/MegaShop',
            'login' => 'DeAref',
            'message' => "feat: big thing\n\nbody",
            'score' => 2000,
            'committed_at' => $saturdayMorning->copy()->utc(),
            'month' => '1405-08',
        ]);

        TeamTask::query()->create([
            'title' => 'ریسرچ بازار',
            'status' => 'done',
            'priority' => 'normal',
            'assignee_telegram_id' => 1080648232,
            'assignee_name' => 'عارف',
            'created_by_telegram_id' => 1080648232,
            'is_code' => false,
            'score' => 1500,
            'completed_at' => $saturdayMorning->copy()->utc(),
        ]);

        $this->assertTrue(app(WeeklyReportService::class)->sendIfDue($friday)['sent']);

        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, 'گزارش هفتگی')
                && str_contains($text, 'بهترین کامیت: 2,000 — feat: big thing')
                && str_contains($text, 'بهترین تسک: 1,500 — ریسرچ بازار')
                && str_contains($text, 'عارف — 3,500');
        });
    }

    public function test_history_day_week_month_lists_commits_tasks_and_scores(): void
    {
        $now = Carbon::parse('2026-11-04 12:00:00', 'Asia/Tehran');

        CommitScore::query()->create([
            'sha' => 'h1',
            'repo' => 'BytelabsTeam/MegaShop',
            'login' => 'DeAref',
            'message' => 'history commit',
            'score' => 700,
            'committed_at' => $now->copy()->utc(),
            'month' => '1405-08',
        ]);

        TeamTask::query()->create([
            'title' => 'تسک تاریخچه',
            'status' => 'done',
            'priority' => 'normal',
            'assignee_telegram_id' => 1080648232,
            'assignee_name' => 'عارف',
            'created_by_telegram_id' => 1080648232,
            'is_code' => false,
            'score' => 300,
            'completed_at' => $now->copy()->utc(),
        ]);

        $history = app(HistoryService::class);

        $day = $history->format('day', $now);
        $week = $history->format('week', $now);
        $month = $history->format('month', $now);

        foreach ([$day, $week, $month] as $text) {
            $this->assertStringContainsString('history commit', $text);
            $this->assertStringContainsString('تسک تاریخچه', $text);
            $this->assertStringContainsString('عارف — 1,000', $text);
        }

        $this->assertStringContainsString('تاریخچه امروز', $day);
        $this->assertStringContainsString('تاریخچه هفته', $week);
        $this->assertStringContainsString('تاریخچه ماه', $month);
    }

    public function test_history_excludes_items_outside_the_period(): void
    {
        $now = Carbon::parse('2026-11-04 12:00:00', 'Asia/Tehran');

        CommitScore::query()->create([
            'sha' => 'old1',
            'repo' => 'BytelabsTeam/MegaShop',
            'login' => 'DeAref',
            'message' => 'old commit',
            'score' => 900,
            'committed_at' => $now->copy()->subDays(10)->utc(),
            'month' => '1405-07',
        ]);

        $day = app(HistoryService::class)->format('day', $now);

        $this->assertStringNotContainsString('old commit', $day);
    }
}
