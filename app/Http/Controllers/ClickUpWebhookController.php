<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyTaskDoneOnTelegram;
use App\Support\TaskDoneDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClickUpWebhookController extends Controller
{
    public function __construct(private readonly TaskDoneDetector $taskDoneDetector)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (! $this->taskDoneDetector->isTaskDone($payload)) {
            return response()->json(['status' => 'ignored']);
        }

        $historyItem = $this->taskDoneDetector->extractStatusHistoryItem($payload);

        if ($historyItem === null) {
            return response()->json(['status' => 'ignored']);
        }

        $taskId = (string) ($payload['task_id'] ?? '');

        if ($taskId === '') {
            return response()->json(['status' => 'ignored']);
        }

        $user = is_array($historyItem['user'] ?? null) ? $historyItem['user'] : [];

        NotifyTaskDoneOnTelegram::dispatch(
            taskId: $taskId,
            email: isset($user['email']) ? (string) $user['email'] : null,
            username: isset($user['username']) ? (string) $user['username'] : null,
            idempotencyKey: $this->buildIdempotencyKey($payload, $historyItem),
        );

        return response()->json(['status' => 'queued']);
    }

    private function buildIdempotencyKey(array $payload, array $historyItem): string
    {
        $webhookId = (string) ($payload['webhook_id'] ?? 'unknown');
        $historyItemId = (string) ($historyItem['id'] ?? 'unknown');

        return "{$webhookId}:{$historyItemId}";
    }
}
