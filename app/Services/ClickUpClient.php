<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ClickUpClient
{
    /**
     * @return array<string, mixed>
     */
    public function getTask(string $taskId): array
    {
        $token = $this->requireApiToken();

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->get("https://api.clickup.com/api/v2/task/{$taskId}");

        $response->throw();

        $task = $response->json();

        return is_array($task) ? $task : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getList(string $listId): array
    {
        $token = $this->requireApiToken();

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->get("https://api.clickup.com/api/v2/list/{$listId}");

        $response->throw();

        $list = $response->json();

        return is_array($list) ? $list : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getListStatuses(string $listId): array
    {
        $list = $this->getList($listId);
        $statuses = $list['statuses'] ?? [];

        if (! is_array($statuses)) {
            return [];
        }

        $result = [];

        foreach ($statuses as $status) {
            if (is_array($status)) {
                $result[] = $status;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTask(string $listId, array $payload): array
    {
        $token = $this->requireApiToken();

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->asJson()
            ->post("https://api.clickup.com/api/v2/list/{$listId}/task", $payload);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw new \RuntimeException(
                'Failed to create ClickUp task: '.$exception->response?->body(),
                previous: $exception
            );
        }

        $task = $response->json();

        return is_array($task) ? $task : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateTask(string $taskId, array $payload): array
    {
        $token = $this->requireApiToken();

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->asJson()
            ->put("https://api.clickup.com/api/v2/task/{$taskId}", $payload);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw new \RuntimeException(
                'Failed to update ClickUp task: '.$exception->response?->body(),
                previous: $exception
            );
        }

        $task = $response->json();

        return is_array($task) ? $task : [];
    }

    public function updateTaskStatus(string $taskId, string $status): array
    {
        return $this->updateTask($taskId, ['status' => $status]);
    }

    /**
     * @return array<string, mixed>
     */
    public function addTaskComment(string $taskId, string $commentText): array
    {
        $token = $this->requireApiToken();

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->asJson()
            ->post("https://api.clickup.com/api/v2/task/{$taskId}/comment", [
                'comment_text' => $commentText,
            ]);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw new \RuntimeException(
                'Failed to comment on ClickUp task: '.$exception->response?->body(),
                previous: $exception
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function resolveReviewStatus(string $listId): string
    {
        $preferred = trim((string) config('clickup.review_status', 'review'));
        $statuses = $this->getListStatuses($listId);
        $names = [];

        foreach ($statuses as $status) {
            $name = trim((string) ($status['status'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        foreach ($names as $name) {
            if (mb_strtolower($name) === mb_strtolower($preferred)) {
                return $name;
            }
        }

        foreach ($statuses as $status) {
            $type = mb_strtolower((string) ($status['type'] ?? ''));
            $name = trim((string) ($status['status'] ?? ''));

            if ($name !== '' && ! in_array($type, ['closed', 'done'], true)) {
                return $name;
            }
        }

        return $preferred !== '' ? $preferred : 'to do';
    }

    public function resolveInProgressStatus(string $listId): string
    {
        $statuses = $this->getListStatuses($listId);

        foreach ($statuses as $status) {
            $name = trim((string) ($status['status'] ?? ''));
            $type = mb_strtolower((string) ($status['type'] ?? ''));

            if ($name !== '' && (str_contains(mb_strtolower($name), 'progress') || $type === 'custom')) {
                return $name;
            }
        }

        foreach ($statuses as $status) {
            $type = mb_strtolower((string) ($status['type'] ?? ''));
            $name = trim((string) ($status['status'] ?? ''));

            if ($name !== '' && ! in_array($type, ['closed', 'done'], true)) {
                return $name;
            }
        }

        return 'in progress';
    }

    public function defaultListId(): string
    {
        $listId = config('clickup.list_id');

        if (! is_string($listId) || $listId === '') {
            throw new \RuntimeException('CLICKUP_LIST_ID برای ایجاد تسک تنظیم نشده.');
        }

        return $listId;
    }

    /**
     * @return list<array{id: int, email: string, username: string}>
     */
    public function getTeamMembers(): array
    {
        $token = $this->requireApiToken();
        $teamId = $this->requireTeamId();

        return Cache::remember(
            "clickup:team:{$teamId}:members",
            now()->addHours(6),
            function () use ($token, $teamId): array {
                $response = Http::withHeaders(['Authorization' => $token])
                    ->acceptJson()
                    ->get('https://api.clickup.com/api/v2/team');

                $response->throw();

                $teams = $response->json('teams') ?? [];

                if (! is_array($teams)) {
                    return [];
                }

                $members = [];

                foreach ($teams as $team) {
                    if (! is_array($team) || (string) ($team['id'] ?? '') !== $teamId) {
                        continue;
                    }

                    foreach ($team['members'] ?? [] as $member) {
                        if (! is_array($member)) {
                            continue;
                        }

                        $user = $member['user'] ?? $member;

                        if (! is_array($user) || ! is_numeric($user['id'] ?? null)) {
                            continue;
                        }

                        $members[] = [
                            'id' => (int) $user['id'],
                            'email' => (string) ($user['email'] ?? ''),
                            'username' => (string) ($user['username'] ?? ''),
                        ];
                    }
                }

                return $members;
            }
        );
    }

    public function getTaskName(string $taskId): string
    {
        $task = $this->getTask($taskId);

        return (string) ($task['name'] ?? $taskId);
    }

    public function resolveProjectTag(array $task): string
    {
        $space = $task['space'] ?? null;
        $spaceId = is_array($space) ? (string) ($space['id'] ?? '') : '';

        if ($spaceId === '') {
            return '';
        }

        $spaceName = $this->getSpaceName($spaceId);

        if ($spaceName === '') {
            return '';
        }

        $tag = $this->sanitizeHashtagPart($spaceName);
        $folderName = $this->resolveFolderName($task);

        if ($folderName !== '') {
            $tag .= '_'.$this->sanitizeHashtagPart($folderName);
        }

        return '#'.$tag;
    }

    private function resolveFolderName(array $task): string
    {
        $folder = $task['folder'] ?? null;

        if (! is_array($folder) || ($folder['hidden'] ?? false)) {
            return '';
        }

        return trim((string) ($folder['name'] ?? ''));
    }

    private function getSpaceName(string $spaceId): string
    {
        return Cache::remember(
            "clickup:space:{$spaceId}:name",
            now()->addDays(7),
            function () use ($spaceId): string {
                $token = $this->requireApiToken();

                $response = Http::withHeaders(['Authorization' => $token])
                    ->acceptJson()
                    ->get("https://api.clickup.com/api/v2/space/{$spaceId}");

                $response->throw();

                $name = $response->json('name');

                return is_string($name) ? $name : '';
            }
        );
    }

    private function sanitizeHashtagPart(string $name): string
    {
        return str_replace([' ', '#'], '', trim($name));
    }

    /**
     * @param  list<mixed>  $attachments
     * @return list<array{url: string, type: string}>
     */
    public function extractMediaAttachments(array $attachments): array
    {
        $media = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || ($attachment['deleted'] ?? false)) {
                continue;
            }

            $url = $attachment['url'] ?? $attachment['url_w_host'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $mimetype = (string) ($attachment['mimetype'] ?? '');
            $extension = (string) ($attachment['extension'] ?? '');
            $type = $this->resolveTelegramMediaType($mimetype, $extension);

            if ($type === null) {
                continue;
            }

            $media[] = [
                'url' => $url,
                'type' => $type,
            ];
        }

        return $media;
    }

    private function resolveTelegramMediaType(string $mimetype, string $extension): ?string
    {
        $mimetype = strtolower($mimetype);
        $extension = strtolower($extension);

        if (str_starts_with($mimetype, 'image/')) {
            return $extension === 'gif' || $mimetype === 'image/gif' ? 'animation' : 'photo';
        }

        if (str_starts_with($mimetype, 'video/')) {
            return 'video';
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'bmp'], true)) {
            return 'photo';
        }

        if ($extension === 'gif') {
            return 'animation';
        }

        if (in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm'], true)) {
            return 'video';
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTasksUpdatedSince(int $updatedAfterMs): array
    {
        return $this->getTeamTasks([
            'include_closed' => 'true',
            'date_updated_gt' => $updatedAfterMs,
            'order_by' => 'updated',
            'subtasks' => 'true',
        ], applyScopeFilters: true);
    }

    /**
     * Closed/done tasks whose done date falls in [doneAfterMs, doneBeforeMs).
     *
     * @return list<array<string, mixed>>
     */
    public function getClosedTasksDoneBetween(int $doneAfterMs, int $doneBeforeMs): array
    {
        return $this->getTeamTasks([
            'include_closed' => 'true',
            'date_done_gt' => $doneAfterMs,
            'date_done_lt' => $doneBeforeMs,
            'order_by' => 'updated',
            'subtasks' => 'true',
        ], applyScopeFilters: false);
    }

    public function requireConfigured(): void
    {
        $this->requireApiToken();
        $this->requireTeamId();
    }

    public function isConfigured(): bool
    {
        $token = config('clickup.api_token');
        $teamId = config('clickup.team_id');

        return is_string($token) && $token !== ''
            && is_string($teamId) && $teamId !== '';
    }

    /**
     * Fetch open tasks across the whole workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function getOpenTasks(): array
    {
        return $this->getTeamTasks([
            'include_closed' => 'false',
            'order_by' => 'due_date',
            'subtasks' => 'true',
        ], applyScopeFilters: false);
    }

    /**
     * Fetch open tasks with due date before the given exclusive upper bound
     * across the whole workspace (includes overdue tasks).
     *
     * @return list<array<string, mixed>>
     */
    public function getOpenTasksDueBefore(int $dueDateLtMs): array
    {
        return $this->getTeamTasks([
            'include_closed' => 'false',
            'due_date_lt' => $dueDateLtMs,
            'order_by' => 'due_date',
            'subtasks' => 'true',
        ], applyScopeFilters: false);
    }

    /**
     * Fetch open tasks with a due date in the given window across the whole workspace.
     *
     * Scope filters (SPACE/FOLDER/LIST) are intentionally skipped so the daily
     * digest is not limited to the same space used for done-task notifications.
     *
     * @return list<array<string, mixed>>
     */
    public function getTasksDueBetween(int $dueDateGtMs, int $dueDateLtMs): array
    {
        return $this->getTeamTasks([
            'include_closed' => 'false',
            'due_date_gt' => $dueDateGtMs,
            'due_date_lt' => $dueDateLtMs,
            'order_by' => 'due_date',
            'subtasks' => 'true',
        ], applyScopeFilters: false);
    }

    /**
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    private function getTeamTasks(array $query, bool $applyScopeFilters = true): array
    {
        $token = $this->requireApiToken();
        $teamId = $this->requireTeamId();

        if ($applyScopeFilters) {
            if ($listId = config('clickup.list_id')) {
                $query['list_ids[]'] = (int) $listId;
            }

            if ($folderId = config('clickup.folder_id')) {
                $query['project_ids[]'] = (int) $folderId;
            }

            if ($spaceId = config('clickup.space_id')) {
                $query['space_ids[]'] = (int) $spaceId;
            }
        }

        $tasks = [];
        $page = 0;

        do {
            $query['page'] = $page;

            $response = Http::withHeaders(['Authorization' => $token])
                ->acceptJson()
                ->get("https://api.clickup.com/api/v2/team/{$teamId}/task", $query);

            $response->throw();

            $batch = $response->json('tasks') ?? [];

            if (! is_array($batch)) {
                break;
            }

            $tasks = array_merge($tasks, $batch);
            $page++;
        } while ($batch !== [] && $page < 20);

        return $tasks;
    }

    public function registerWebhook(): array
    {
        $token = $this->requireApiToken();
        $teamId = $this->requireTeamId();
        $endpoint = config('clickup.webhook_endpoint');

        if (! is_string($endpoint) || $endpoint === '') {
            throw new \RuntimeException('CLICKUP_WEBHOOK_ENDPOINT is not configured.');
        }

        $payload = [
            'endpoint' => $endpoint,
            'events' => ['taskStatusUpdated'],
        ];

        foreach (['space_id', 'folder_id', 'list_id'] as $key) {
            $value = config("clickup.{$key}");

            if ($value !== null && $value !== '') {
                $payload[$key] = (int) $value;
            }
        }

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->post("https://api.clickup.com/api/v2/team/{$teamId}/webhook", $payload);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw new \RuntimeException(
                'Failed to register ClickUp webhook: '.$exception->response?->body(),
                previous: $exception
            );
        }

        return $response->json();
    }

    private function requireApiToken(): string
    {
        $token = config('clickup.api_token');

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('CLICKUP_API_TOKEN is not configured.');
        }

        return $token;
    }

    private function requireTeamId(): string
    {
        $teamId = config('clickup.team_id');

        if (! is_string($teamId) || $teamId === '') {
            throw new \RuntimeException('CLICKUP_TEAM_ID is not configured.');
        }

        return $teamId;
    }
}
