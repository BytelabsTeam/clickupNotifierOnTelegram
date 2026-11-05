<?php

namespace Tests\Feature;

use App\Models\TeamMember;
use App\Models\TeamTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamBotTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'telegram-secret-token';

    private const GROUP_CHAT_ID = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => self::GROUP_CHAT_ID,
            'telegram.webhook_secret' => self::WEBHOOK_SECRET,
            'github.token' => '',
            'github.repos' => [],
            'iranrouter.api_key' => 'ir-test-key',
            'iranrouter.base_url' => 'https://www.iranrouter.net/api/v1',
            'iranrouter.model' => 'openai/gpt-6-luna',
        ]);

        TeamMember::query()->create(['telegram_user_id' => 1001, 'telegram_username' => 'armin_ghaderi', 'display_name' => '?????', 'is_admin' => true]);
        TeamMember::query()->create(['telegram_user_id' => 1002, 'telegram_username' => 'ageek', 'github_login' => 'DeAref', 'display_name' => '????']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
            'www.iranrouter.net/*' => Http::response([
                'choices' => [['message' => ['content' => '{"is_code": false, "score": 800, "reason": "ok"}']]],
            ], 200),
        ]);
    }

    public function test_it_rejects_requests_without_valid_secret(): void
    {
        $this->postJson('/api/webhooks/telegram', $this->update(1002, 'alice', '/help'))
            ->assertUnauthorized();
    }

    public function test_unknown_member_is_rejected_in_private_chat(): void
    {
        $this->postSigned($this->update(9999, 'stranger', '/help', 'private'));

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'اجازه دسترسی'));
    }

    public function test_member_can_create_and_list_own_task(): void
    {
        $this->postSigned($this->update(1002, 'alice', '/newtask طراحی بنر | توضیح | 2026-11-10'));

        $task = TeamTask::query()->first();
        $this->assertNotNull($task);
        $this->assertSame(1002, (int) $task->assignee_telegram_id);
        $this->assertSame('طراحی بنر', $task->title);

        $this->postSigned($this->update(1002, 'alice', '/tasks'));

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'طراحی بنر'));
    }

    public function test_member_cannot_see_other_members_tasks(): void
    {
        TeamTask::query()->create([
            'title' => 'کار محرمانه',
            'status' => 'todo',
            'priority' => 'normal',
            'assignee_telegram_id' => 1001,
            'created_by_telegram_id' => 1001,
        ]);

        $this->postSigned($this->update(1002, 'alice', '/tasks'));

        Http::assertSent(fn ($request) => ! str_contains((string) $request['text'], 'کار محرمانه'));
    }

    public function test_member_can_mark_own_task_done_and_it_is_scored(): void
    {
        $task = TeamTask::query()->create([
            'title' => 'مستندسازی API',
            'description' => 'نوشتن راهنما',
            'status' => 'todo',
            'priority' => 'normal',
            'assignee_telegram_id' => 1002,
            'created_by_telegram_id' => 1002,
        ]);

        $this->postSigned($this->update(1002, 'alice', "/status {$task->id} done"));

        $task->refresh();
        $this->assertSame('done', $task->status);
        $this->assertSame(800, (int) $task->score);
        $this->assertFalse((bool) $task->is_code);
        $this->assertNotNull($task->completed_at);
    }

    public function test_member_cannot_change_another_members_task(): void
    {
        $task = TeamTask::query()->create([
            'title' => 'کار دیگران',
            'status' => 'todo',
            'priority' => 'normal',
            'assignee_telegram_id' => 1001,
            'created_by_telegram_id' => 1001,
        ]);

        $this->postSigned($this->update(1002, 'alice', "/status {$task->id} done"));

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_admin_can_see_team_tasks(): void
    {
        TeamTask::query()->create([
            'title' => 'کار تیم',
            'status' => 'todo',
            'priority' => 'normal',
            'assignee_telegram_id' => 1002,
            'created_by_telegram_id' => 1001,
        ]);

        $this->postSigned($this->update(1001, 'admin', '/team'));

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'کار تیم'));
    }

    public function test_admin_can_assign_task_to_known_member(): void
    {
        TeamMember::query()->create([
            'telegram_user_id' => 1003,
            'telegram_username' => 'bob',
            'display_name' => 'Bob',
        ]);

        $task = TeamTask::query()->create([
            'title' => 'واگذاری',
            'status' => 'todo',
            'priority' => 'normal',
            'assignee_telegram_id' => 1001,
            'created_by_telegram_id' => 1001,
        ]);

        $this->postSigned($this->update(1001, 'admin', "/assign {$task->id} @bob"));

        $this->assertSame(1003, (int) $task->fresh()->assignee_telegram_id);
    }

    public function test_non_admin_cannot_assign_tasks(): void
    {
        $task = TeamTask::query()->create([
            'title' => 'واگذاری',
            'status' => 'todo',
            'priority' => 'normal',
            'assignee_telegram_id' => 1002,
            'created_by_telegram_id' => 1002,
        ]);

        $this->postSigned($this->update(1002, 'alice', "/assign {$task->id} @bob"));

        $this->assertSame(1002, (int) $task->fresh()->assignee_telegram_id);
    }

    public function test_it_ignores_duplicate_telegram_update_ids(): void
    {
        $update = $this->update(1002, 'alice', '/help');

        $this->postSigned($update);
        $this->postSigned($update);

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function update(int $userId, string $username, string $text, string $chatType = 'private'): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $userId, 'username' => $username, 'first_name' => ucfirst($username)],
                'chat' => [
                    'id' => $chatType === 'private' ? $userId : (int) self::GROUP_CHAT_ID,
                    'type' => $chatType,
                ],
                'text' => $text,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function postSigned(array $update): void
    {
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => self::WEBHOOK_SECRET])
            ->postJson('/api/webhooks/telegram', $update)
            ->assertOk();
    }
}

