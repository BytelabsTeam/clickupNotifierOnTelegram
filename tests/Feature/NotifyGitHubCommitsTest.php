<?php

namespace Tests\Feature;

use App\Models\CommitScore;
use App\Models\TeamMember;
use App\Services\NotifyScoredCommits;
use App\Services\PollGitHubCommits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotifyGitHubCommitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'github.token' => 'ghp_test_token',
            'github.org' => '',
            'github.repos' => ['BytelabsTeam/MegaShop'],
            'github.commit_poll_lookback_minutes' => 30,
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => '-1001234567890',
            'telegram.commit_message_template' => "{name} توسعه داد:\n{message}\n#{repo}",
            'app.cron_token' => 'secret-cron-token',
            'iranrouter.api_key' => 'ir-test-key',
            'iranrouter.base_url' => 'https://www.iranrouter.net/api/v1',
            'iranrouter.model' => 'openai/gpt-6-luna',
            'iranrouter.timeout' => 30,
        ]);

        TeamMember::query()->create([
            'telegram_user_id' => 1080648232,
            'telegram_username' => 'ageek',
            'github_login' => 'DeAref',
            'display_name' => 'عارف',
        ]);
    }

    public function test_poll_only_fetches_commits_and_does_not_notify_before_scoring(): void
    {
        Http::fake([
            'api.github.com/repos/BytelabsTeam/MegaShop/commits*' => Http::response([
                [
                    'sha' => 'abc123',
                    'parents' => [['sha' => 'parent']],
                    'author' => ['login' => 'DeAref'],
                    'commit' => [
                        'message' => "Fix login bug\n\nDetails",
                        'author' => ['name' => 'Aref', 'date' => now('UTC')->subMinutes(5)->toIso8601String()],
                    ],
                ],
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->assertSame(1, app(PollGitHubCommits::class)->poll());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    public function test_scored_commit_is_notified_with_score_and_only_once(): void
    {
        CommitScore::query()->create([
            'sha' => 'abc123',
            'repo' => 'BytelabsTeam/MegaShop',
            'login' => 'DeAref',
            'name' => 'Aref',
            'message' => "Fix login bug\n\nDetails",
            'score' => 850,
            'reason' => 'رفع باگ مهم',
            'committed_at' => now('UTC')->subMinutes(5),
            'month' => '1405-08',
        ]);

        Http::fake([
            'www.iranrouter.net/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'رفع باگ لاگین']]],
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->assertSame(1, app(NotifyScoredCommits::class)->notify());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) $request['text'];

            return str_contains($text, 'عارف توسعه داد:')
                && str_contains($text, 'رفع باگ لاگین')
                && str_contains($text, '⭐ امتیاز: 850')
                && str_contains($text, 'دلیل: رفع باگ مهم');
        });

        $this->assertSame(0, app(NotifyScoredCommits::class)->notify());
    }

    public function test_unscored_commit_is_not_notified(): void
    {
        CommitScore::query()->create([
            'sha' => 'zero1',
            'repo' => 'BytelabsTeam/MegaShop',
            'login' => 'DeAref',
            'message' => 'x',
            'score' => 0,
            'committed_at' => now('UTC')->subMinutes(5),
            'month' => '1405-08',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->assertSame(0, app(NotifyScoredCommits::class)->notify());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    public function test_commit_from_unknown_github_login_is_never_announced_to_anyone(): void
    {
        CommitScore::query()->create([
            'sha' => 'unknown1',
            'repo' => 'BytelabsTeam/MegaShop',
            'login' => 'nobody-here',
            'message' => 'x',
            'score' => 500,
            'committed_at' => now('UTC')->subMinutes(5),
            'month' => '1405-08',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->assertSame(0, app(NotifyScoredCommits::class)->notify());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
        $this->assertNotNull(CommitScore::query()->where('sha', 'unknown1')->first()->notified_at);
    }

    public function test_cron_endpoint_runs_poll_with_valid_token(): void
    {
        $this->mock(PollGitHubCommits::class, function ($mock): void {
            $mock->shouldReceive('poll')->once()->andReturn(3);
        });

        $this->get('/cron/github-commits?token=secret-cron-token')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'notified' => 3]);
    }

    public function test_cron_endpoint_rejects_invalid_token(): void
    {
        $this->get('/cron/github-commits?token=wrong')->assertForbidden();
    }
}
