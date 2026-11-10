<?php

namespace Tests\Feature;

use App\Models\CommitScore;
use App\Models\ProjectScoringDoc;
use App\Models\TaskScore;
use App\Services\BackupMissedDoneTasks;
use App\Services\CommitLlmScorer;
use App\Services\CommitMessageTranslator;
use App\Services\IranRouterClient;
use App\Services\TaskLlmScorer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class BackupMissedDoneTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'Asia/Tehran'));

        config([
            'app.timezone' => 'Asia/Tehran',
            'clickup.api_token' => 'pk_test_token',
            'clickup.team_id' => '90121842245',
            'clickup.done_statuses' => ['complete', 'done', 'تکمیل'],
            'clickup.user_names' => [
                'arefmohaamd332@gmail.com' => 'عارف',
            ],
            'clickup.backup_lookback_days' => 7,
            'clickup.backup_tasks_per_run' => 3,
            'clickup.cron_token' => 'secret-cron-token',
            'github.token' => '',
            'github.repos' => [],
            'github.user_logins' => [
                'arefmohaamd332@gmail.com' => 'arefdev',
            ],
            'iranrouter.api_key' => 'test-iranrouter-key',
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => '-1001234567890',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_registers_missing_task_scores_and_notifies_group(): void
    {
        $day = '2026-11-03';
        $doneMs = Carbon::parse($day.' 15:00:00', 'Asia/Tehran')->getTimestampMs();

        $this->mockTaskScorer(1200);

        Http::fake(function ($request) use ($day, $doneMs) {
            $url = $request->url();

            if (str_contains($url, 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            if (str_contains($url, '/api/v2/task/old_task_1')) {
                return Http::response($this->doneTask('old_task_1', 'مستندات قدیمی', $doneMs), 200);
            }

            if (str_contains($url, '/team/90121842245/task')) {
                $page = (int) ($request['page'] ?? 0);
                $requestedDay = $this->dayFromDoneFilters($request);

                if ($page > 0) {
                    return Http::response(['tasks' => []], 200);
                }

                if ($requestedDay === $day) {
                    return Http::response([
                        'tasks' => [
                            $this->doneTask('old_task_1', 'مستندات قدیمی', $doneMs),
                            $this->doneTask('already_scored', 'قبلا ثبت شده', $doneMs),
                        ],
                    ], 200);
                }

                return Http::response(['tasks' => []], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        TaskScore::query()->create([
            'task_id' => 'already_scored',
            'email' => 'arefmohaamd332@gmail.com',
            'username' => 'Aref',
            'name' => 'Aref',
            'task_name' => 'قبلا ثبت شده',
            'task_description' => null,
            'is_code' => false,
            'score' => 100,
            'reason' => 'existing',
            'completed_at' => $day.' 15:00:00',
            'month' => '1405-08',
        ]);

        $result = app(BackupMissedDoneTasks::class)->run();

        $this->assertSame($day, $result['clickup']['day']);
        $this->assertSame(1, $result['clickup']['queued']);
        $this->assertSame(1, $result['clickup']['registered']);
        $this->assertSame(1, $result['clickup']['notified']);
        $this->assertSame(0, $result['github']['registered']);

        $this->assertDatabaseHas('task_scores', [
            'task_id' => 'old_task_1',
            'score' => 1200,
        ]);

        Http::assertSent(function ($request) use ($day) {
            if (! str_contains($request->url(), 'api.telegram.org/bot123456:telegram-token/sendMessage')) {
                return false;
            }

            $text = (string) ($request['text'] ?? '');

            return str_contains($text, 'تسک قدیمی ثبت نشده')
                && str_contains($text, 'مستندات قدیمی')
                && str_contains($text, 'تاریخ: '.$day)
                && str_contains($text, 'امتیاز: 1200');
        });
    }

    public function test_it_registers_missing_github_commits_and_notifies_group(): void
    {
        config([
            'clickup.api_token' => '',
            'clickup.team_id' => '',
            'github.token' => 'ghp_test_token',
            'github.repos' => ['acme/app'],
        ]);

        $day = '2026-11-03';
        $committedAt = '2026-11-03T12:30:00Z';

        ProjectScoringDoc::query()->create([
            'repo' => 'acme/app',
            'content' => 'Baseline',
            'generated_at' => now(),
        ]);

        $iran = Mockery::mock(IranRouterClient::class);
        $iran->shouldReceive('requireApiKey')->andReturn('test-iranrouter-key');
        $iran->shouldReceive('chat')->andReturn('{"score": 850, "reason": "backup commit"}');
        $this->app->instance(IranRouterClient::class, $iran);
        $this->app->instance(CommitLlmScorer::class, new CommitLlmScorer($iran));

        $translator = Mockery::mock(CommitMessageTranslator::class);
        $translator->shouldReceive('toPersian')->andReturnUsing(fn (string $message): string => $message);
        $this->app->instance(CommitMessageTranslator::class, $translator);

        Http::fake(function ($request) use ($committedAt) {
            $url = $request->url();

            if (str_contains($url, 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            if (str_contains($url, 'api.github.com/repos/acme/app/commits/oldsha')) {
                return Http::response([
                    'sha' => 'oldsha',
                    'commit' => [
                        'message' => 'feat: old missed commit',
                        'author' => [
                            'name' => 'Aref',
                            'email' => 'arefmohaamd332@gmail.com',
                            'date' => $committedAt,
                        ],
                    ],
                    'author' => ['login' => 'arefdev'],
                    'files' => [
                        ['filename' => 'app.php', 'additions' => 12, 'deletions' => 2],
                    ],
                ], 200);
            }

            if (str_contains($url, 'api.github.com/repos/acme/app/commits')) {
                $since = Carbon::parse((string) ($request['since'] ?? ''), 'UTC');
                $until = Carbon::parse((string) ($request['until'] ?? ''), 'UTC');
                $at = Carbon::parse($committedAt, 'UTC');

                if ($at->lt($since) || $at->gt($until)) {
                    return Http::response([], 200);
                }

                return Http::response([
                    [
                        'sha' => 'oldsha',
                        'parents' => [['sha' => 'parent']],
                        'commit' => [
                            'message' => 'feat: old missed commit',
                            'author' => [
                                'name' => 'Aref',
                                'email' => 'arefmohaamd332@gmail.com',
                                'date' => $committedAt,
                            ],
                        ],
                        'author' => ['login' => 'arefdev'],
                    ],
                    [
                        'sha' => 'already',
                        'parents' => [['sha' => 'parent']],
                        'commit' => [
                            'message' => 'already scored',
                            'author' => [
                                'name' => 'Aref',
                                'email' => 'arefmohaamd332@gmail.com',
                                'date' => $committedAt,
                            ],
                        ],
                        'author' => ['login' => 'arefdev'],
                    ],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        CommitScore::query()->create([
            'sha' => 'already',
            'repo' => 'acme/app',
            'login' => 'arefdev',
            'email' => 'arefmohaamd332@gmail.com',
            'name' => 'Aref',
            'message' => 'already scored',
            'score' => 100,
            'committed_at' => '2026-11-03 12:00:00',
            'month' => '1405-08',
        ]);

        $result = app(BackupMissedDoneTasks::class)->run();

        $this->assertSame($day, $result['github']['day']);
        $this->assertSame(1, $result['github']['queued']);
        $this->assertSame(1, $result['github']['registered']);
        $this->assertSame(1, $result['github']['notified']);
        $this->assertSame(0, $result['clickup']['registered']);

        $this->assertDatabaseHas('commit_scores', [
            'sha' => 'oldsha',
            'repo' => 'acme/app',
            'score' => 850,
        ]);

        Http::assertSent(function ($request) use ($day) {
            if (! str_contains($request->url(), 'api.telegram.org/bot123456:telegram-token/sendMessage')) {
                return false;
            }

            $text = (string) ($request['text'] ?? '');

            return str_contains($text, 'کامیت قدیمی ثبت نشده')
                && str_contains($text, 'feat: old missed commit')
                && str_contains($text, 'تاریخ: '.$day)
                && str_contains($text, 'امتیاز: 850');
        });
    }

    public function test_it_skips_tasks_already_in_database(): void
    {
        $day = '2026-11-03';
        $doneMs = Carbon::parse($day.' 15:00:00', 'Asia/Tehran')->getTimestampMs();

        $scorer = Mockery::mock(TaskLlmScorer::class);
        $scorer->shouldReceive('score')->never();
        $this->app->instance(TaskLlmScorer::class, $scorer);

        Http::fake(function ($request) use ($day, $doneMs) {
            $url = $request->url();

            if (str_contains($url, 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            if (str_contains($url, '/team/90121842245/task')) {
                $page = (int) ($request['page'] ?? 0);
                $requestedDay = $this->dayFromDoneFilters($request);

                if ($page === 0 && $requestedDay === $day) {
                    return Http::response([
                        'tasks' => [$this->doneTask('already_scored', 'قبلا ثبت شده', $doneMs)],
                    ], 200);
                }

                return Http::response(['tasks' => []], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        TaskScore::query()->create([
            'task_id' => 'already_scored',
            'email' => 'arefmohaamd332@gmail.com',
            'username' => 'Aref',
            'name' => 'Aref',
            'task_name' => 'قبلا ثبت شده',
            'task_description' => null,
            'is_code' => false,
            'score' => 100,
            'reason' => 'existing',
            'completed_at' => $day.' 15:00:00',
            'month' => '1405-08',
        ]);

        $result = app(BackupMissedDoneTasks::class)->run();

        $this->assertSame(0, $result['queued']);
        $this->assertSame(0, $result['registered']);
        $this->assertSame(0, $result['notified']);

        $telegramSends = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->count();

        $this->assertSame(0, $telegramSends);
    }

    public function test_cron_endpoint_runs_with_valid_token(): void
    {
        $this->mock(BackupMissedDoneTasks::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturn([
                'day' => '2026-11-03',
                'fetched' => 2,
                'queued' => 1,
                'registered' => 1,
                'notified' => 1,
                'remaining_days' => 6,
                'remaining_tasks' => 0,
                'clickup' => [
                    'day' => '2026-11-03',
                    'fetched' => 1,
                    'queued' => 1,
                    'registered' => 1,
                    'notified' => 1,
                    'remaining_days' => 6,
                    'remaining_tasks' => 0,
                ],
                'github' => [
                    'day' => null,
                    'fetched' => 0,
                    'queued' => 0,
                    'registered' => 0,
                    'notified' => 0,
                    'remaining_days' => 0,
                    'remaining_commits' => 0,
                ],
            ]);
        });

        $this->get('/cron/clickup-backup-missed?token=secret-cron-token')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'day' => '2026-11-03',
                'registered' => 1,
                'notified' => 1,
            ]);
    }

    public function test_cron_endpoint_rejects_invalid_token(): void
    {
        $this->get('/cron/clickup-backup-missed?token=wrong')->assertForbidden();
    }

    private function mockTaskScorer(int $score): void
    {
        $iran = Mockery::mock(IranRouterClient::class);
        $iran->shouldReceive('requireApiKey')->andReturn('test-iranrouter-key');
        $iran->shouldReceive('chat')->andReturn(json_encode([
            'is_code' => false,
            'score' => $score,
            'reason' => 'backup score',
        ], JSON_UNESCAPED_UNICODE));
        $this->app->instance(IranRouterClient::class, $iran);
        $this->app->instance(TaskLlmScorer::class, new TaskLlmScorer($iran));
    }

    /**
     * @return array<string, mixed>
     */
    private function doneTask(string $id, string $name, int $doneMs): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'description' => 'توضیح کافی برای تسک قدیمی با جزئیات لازم',
            'date_done' => (string) $doneMs,
            'date_closed' => (string) $doneMs,
            'date_updated' => (string) $doneMs,
            'status' => [
                'status' => 'complete',
                'type' => 'closed',
            ],
            'assignees' => [
                [
                    'email' => 'arefmohaamd332@gmail.com',
                    'username' => 'Aref',
                ],
            ],
            'space' => ['id' => 'space_1'],
        ];
    }

    private function dayFromDoneFilters($request): ?string
    {
        $gt = $request['date_done_gt'] ?? null;
        $lt = $request['date_done_lt'] ?? null;

        if (! is_numeric($gt) || ! is_numeric($lt)) {
            return null;
        }

        return Carbon::createFromTimestampMs((int) $gt, 'Asia/Tehran')->toDateString();
    }
}
