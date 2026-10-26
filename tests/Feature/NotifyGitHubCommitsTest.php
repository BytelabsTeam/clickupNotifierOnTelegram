<?php

namespace Tests\Feature;

use App\Services\PollGitHubCommits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotifyGitHubCommitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'github.token' => 'ghp_test_token',
            'github.org' => '',
            'github.repos' => ['BytelabsTeam/MegaShop'],
            'github.user_logins' => [
                'arefmohaamd332@gmail.com' => 'aref-github',
            ],
            'github.commit_poll_lookback_minutes' => 30,
            'clickup.user_names' => [
                'arefmohaamd332@gmail.com' => 'عارف',
            ],
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => '-1001234567890',
            'telegram.commit_message_template' => "{name} توسعه داد:\n{message}\n#{repo}",
            'clickup.cron_token' => 'secret-cron-token',
            'iranrouter.api_key' => 'ir-test-key',
            'iranrouter.base_url' => 'https://www.iranrouter.net/api/v1',
            'iranrouter.model' => 'openai/gpt-6-luna',
            'iranrouter.timeout' => 30,
        ]);
    }

    public function test_it_notifies_new_commits_translated_to_persian(): void
    {
        Http::fake([
            'api.github.com/repos/BytelabsTeam/MegaShop/commits*' => Http::response([
                [
                    'sha' => 'abc123',
                    'parents' => [['sha' => 'parent']],
                    'author' => ['login' => 'aref-github'],
                    'commit' => [
                        'message' => "Fix login bug\n\nDetails",
                        'author' => [
                            'email' => 'arefmohaamd332@gmail.com',
                            'name' => 'Aref',
                            'date' => now('UTC')->subMinutes(5)->toIso8601String(),
                        ],
                    ],
                ],
                [
                    'sha' => 'merge999',
                    'parents' => [['sha' => 'a'], ['sha' => 'b']],
                    'author' => ['login' => 'aref-github'],
                    'commit' => [
                        'message' => 'Merge branch main',
                        'author' => [
                            'email' => 'arefmohaamd332@gmail.com',
                            'name' => 'Aref',
                            'date' => now('UTC')->subMinutes(4)->toIso8601String(),
                        ],
                    ],
                ],
            ], 200),
            'www.iranrouter.net/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'رفع باگ لاگین']],
                ],
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $count = app(PollGitHubCommits::class)->poll();

        $this->assertSame(1, $count);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $expected = "عارف توسعه داد:\nرفع باگ لاگین\n\nDetails\n#MegaShop";

            return ($request['text'] ?? null) === $expected;
        });

        $this->assertSame(0, app(PollGitHubCommits::class)->poll());
    }

    public function test_cron_endpoint_runs_poll_with_valid_token(): void
    {
        $this->mock(PollGitHubCommits::class, function ($mock): void {
            $mock->shouldReceive('poll')->once()->andReturn(3);
        });

        $this->get('/cron/github-commits?token=secret-cron-token')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'notified' => 3,
            ]);
    }

    public function test_cron_endpoint_rejects_invalid_token(): void
    {
        $this->get('/cron/github-commits?token=wrong')->assertForbidden();
    }
}
