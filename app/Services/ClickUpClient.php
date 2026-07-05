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
        $token = $this->requireApiToken();
        $teamId = $this->requireTeamId();

        $query = [
            'include_closed' => 'true',
            'date_updated_gt' => $updatedAfterMs,
            'order_by' => 'updated',
            'subtasks' => 'true',
        ];

        if ($listId = config('clickup.list_id')) {
            $query['list_ids[]'] = (int) $listId;
        }

        if ($folderId = config('clickup.folder_id')) {
            $query['project_ids[]'] = (int) $folderId;
        }

        if ($spaceId = config('clickup.space_id')) {
            $query['space_ids[]'] = (int) $spaceId;
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
