<?php

namespace App\Support;

class TaskDoneDetector
{
    public function isTaskDone(array $payload): bool
    {
        if (($payload['event'] ?? null) !== 'taskStatusUpdated') {
            return false;
        }

        foreach ($payload['history_items'] ?? [] as $historyItem) {
            if (($historyItem['field'] ?? null) !== 'status') {
                continue;
            }

            $after = $historyItem['after'] ?? null;

            if (! is_array($after)) {
                continue;
            }

            if (($after['type'] ?? null) === 'closed') {
                return true;
            }

            $status = mb_strtolower((string) ($after['status'] ?? ''));

            if ($status !== '' && in_array($status, config('clickup.done_statuses', []), true)) {
                return true;
            }
        }

        return false;
    }

    public function extractStatusHistoryItem(array $payload): ?array
    {
        foreach ($payload['history_items'] ?? [] as $historyItem) {
            if (($historyItem['field'] ?? null) === 'status') {
                return $historyItem;
            }
        }

        return null;
    }

    public function isApiTaskDone(array $task): bool
    {
        $status = $task['status'] ?? null;

        if (! is_array($status)) {
            return false;
        }

        if (($status['type'] ?? null) === 'closed') {
            return true;
        }

        $statusName = mb_strtolower((string) ($status['status'] ?? ''));

        return $statusName !== ''
            && in_array($statusName, config('clickup.done_statuses', []), true);
    }
}
