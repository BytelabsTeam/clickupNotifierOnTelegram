<?php

namespace Tests\Feature;

use App\Models\TeamMember;
use App\Models\TeamTask;
use App\Services\TaskMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskMenuTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_CHAT_ID = '-1001234567890';

    private const SECRET = 'telegram-secret-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:telegram-token',
            'telegram.chat_id' => self::GROUP_CHAT_ID,
            'telegram.webhook_secret' => self::SECRET,
            'iranrouter.api_key' => 'ir-test-key',
            'iranrouter.base_url' => 'https://www.iranrouter.net/api/v1',
            'iranrouter.model' => 'openai/gpt-6-luna',
        ]);

        TeamMember::query()->create(['telegram_user_id' => 1080648232, 'telegram_username' => 'ageek', 'display_name' => 'عارف']);
        TeamMember::query()->create(['telegram_user_id' => 103063134, 'telegram_username' => 'Call_oF_Dutty', 'display_name' => 'حسن']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200),
            'www.iranrouter.net/*' => Http::response([
                'choices' => [['message' => ['content' => '{"is_code": false, "score": 600, "reason": "ok"}']]],
            ], 200),
        ]);
    }

    public function test_menu_lists_only_own_open_tasks_with_buttons(): void
    {
        $mine = TeamTask::query()->create(['title' => 'طراحی لوگو', 'status' => 'todo', 'priority' => 'normal', 'assignee_telegram_id' => 1080648232, 'created_by_telegram_id' => 1080648232]);
        TeamTask::query()->create(['title' => 'کار حسن', 'status' => 'todo', 'priority' => 'normal', 'assignee_telegram_id' => 103063134, 'created_by_telegram_id' => 103063134]);

        $menu = app(TaskMenuService::class)->menuFor(['id' => 1080648232]);

        $this->assertStringContainsString('طراحی لوگو', $menu['text']);
        $this->assertStringNotContainsString('کار حسن', $menu['text']);
        $this->assertSame('task:start:'.$mine->id, $menu['keyboard'][0][0]['callback_data']);
        $this->assertSame('task:done:'.$mine->id, $menu['keyboard'][0][1]['callback_data']);
    }

    public function test_member_can_mark_own_task_done_from_button_and_it_gets_scored(): void
    {
        $task = TeamTask::query()->create(['title' => 'مستندسازی', 'status' => 'todo', 'priority' => 'normal', 'assignee_telegram_id' => 1080648232, 'created_by_telegram_id' => 1080648232]);

        $this->postCallback(1080648232, "task:done:{$task->id}");

        $task->refresh();
        $this->assertSame('done', $task->status);
        $this->assertSame(600, (int) $task->score);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery'));
    }

    public function test_member_cannot_press_button_on_someone_elses_task(): void
    {
        $task = TeamTask::query()->create(['title' => 'کار حسن', 'status' => 'todo', 'priority' => 'normal', 'assignee_telegram_id' => 103063134, 'created_by_telegram_id' => 103063134]);

        $this->postCallback(1080648232, "task:done:{$task->id}");

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_start_button_moves_task_to_in_progress(): void
    {
        $task = TeamTask::query()->create(['title' => 'شروع کار', 'status' => 'todo', 'priority' => 'normal', 'assignee_telegram_id' => 1080648232, 'created_by_telegram_id' => 1080648232]);

        $this->postCallback(1080648232, "task:start:{$task->id}");

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_invalid_callback_data_is_ignored(): void
    {
        $task = TeamTask::query()->create(['title' => 'کار', 'status' => 'todo', 'priority' => 'normal', 'assignee_telegram_id' => 1080648232, 'created_by_telegram_id' => 1080648232]);

        $this->postCallback(1080648232, 'task:drop:'.$task->id);

        $this->assertSame('todo', $task->fresh()->status);
    }

    private function postCallback(int $userId, string $data): void
    {
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->postJson('/api/webhooks/telegram', [
                'update_id' => random_int(1, PHP_INT_MAX),
                'callback_query' => [
                    'id' => 'cb-'.random_int(1, 999999),
                    'from' => ['id' => $userId, 'username' => 'u'],
                    'data' => $data,
                    'message' => [
                        'message_id' => 77,
                        'chat' => ['id' => $userId, 'type' => 'private'],
                    ],
                ],
            ])
            ->assertOk();
    }

    public function test_team_group_ignores_commands_and_sends_nothing(): void
    {
        TeamTask::query()->create(['title' => '???', 'status' => 'todo', 'priority' => 'normal', 'assignee_telegram_id' => 1080648232, 'created_by_telegram_id' => 1080648232]);

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->postJson('/api/webhooks/telegram', [
                'update_id' => random_int(1, PHP_INT_MAX),
                'message' => [
                    'message_id' => 5,
                    'from' => ['id' => 1080648232, 'username' => 'ageek', 'first_name' => '????'],
                    'chat' => ['id' => (int) self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                    'text' => '/menu',
                ],
            ])
            ->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    public function test_task_button_from_group_is_rejected(): void
    {
        $task = TeamTask::query()->create(['title' => '??? ????', 'status' => 'todo', 'priority' => 'normal', 'assignee_telegram_id' => 1080648232, 'created_by_telegram_id' => 1080648232]);

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->postJson('/api/webhooks/telegram', [
                'update_id' => random_int(1, PHP_INT_MAX),
                'callback_query' => [
                    'id' => 'cb-group',
                    'from' => ['id' => 1080648232, 'username' => 'ageek'],
                    'data' => "task:done:{$task->id}",
                    'message' => ['message_id' => 77, 'chat' => ['id' => (int) self::GROUP_CHAT_ID, 'type' => 'supergroup']],
                ],
            ])
            ->assertOk();

        $this->assertSame('todo', $task->fresh()->status);
    }
}