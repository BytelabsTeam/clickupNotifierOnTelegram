<?php

namespace App\Services;

use App\Support\JalaliCalendar;
use App\Support\TaskCallbackRef;
use Carbon\Carbon;

class AssignActionService
{
    public const CALLBACK_PREFIX = 'as:';

    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly UserNameResolver $userNameResolver,
    ) {
    }

    public function isAssignCallback(string $data): bool
    {
        return str_starts_with($data, self::CALLBACK_PREFIX);
    }

    /**
     * @param  array<string, mixed>  $from
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function keyboardForTask(string $taskId): array
    {
        $ref = TaskCallbackRef::remember($taskId);

        return [
            [
                ['text' => 'قبول کردم', 'callback_data' => 'as:ok:'.$ref],
                ['text' => 'شروع کردم', 'callback_data' => 'as:go:'.$ref],
            ],
            [
                ['text' => 'وضعیت', 'callback_data' => 'as:st:'.$ref],
                ['text' => 'ددلاین فردا', 'callback_data' => 'as:due:'.$ref],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast?: string, alert?: bool}
     */
    public function handleCallback(string $data, array $from): array
    {
        if (preg_match('/^as:(ok|go|st|due|set):([a-f0-9]{8})(?::(\d+))?$/', $data, $matches) !== 1) {
            return [
                'text' => 'دکمه نامعتبر.',
                'keyboard' => [[['text' => 'منو', 'callback_data' => 'tm:home']]],
                'toast' => 'دکمه نامعتبر',
                'alert' => true,
            ];
        }

        $action = $matches[1];
        $ref = $matches[2];
        $index = isset($matches[3]) ? (int) $matches[3] : null;
        $taskId = TaskCallbackRef::resolve($ref);

        if ($taskId === null) {
            return [
                'text' => 'این دکمه منقضی شده. منتظر اعلان بعدی بمان.',
                'keyboard' => [[['text' => 'منو', 'callback_data' => 'tm:home']]],
                'toast' => 'منقضی شده',
                'alert' => true,
            ];
        }

        TaskCallbackRef::remember($taskId, $ref);
        $actor = $this->actorName($from);

        return match ($action) {
            'ok' => $this->accept($taskId, $ref, $actor),
            'go' => $this->start($taskId, $ref, $actor),
            'due' => $this->dueTomorrow($taskId, $ref, $actor),
            'st' => $this->statusPicker($taskId, $ref),
            'set' => $this->setStatus($taskId, $ref, $index ?? -1, $actor),
            default => [
                'text' => 'دکمه نامعتبر.',
                'keyboard' => $this->keyboardForTask($taskId),
                'toast' => 'نامعتبر',
                'alert' => true,
            ],
        };
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast: string}
     */
    private function accept(string $taskId, string $ref, string $actor): array
    {
        $this->clickUpClient->addTaskComment($taskId, "{$actor} تسک را از تلگرام قبول کرد.");
        $task = $this->clickUpClient->getTask($taskId);

        return [
            'text' => $this->formatTaskCard($task, "✅ {$actor} قبول کرد."),
            'keyboard' => $this->keyboardForTask($taskId),
            'toast' => 'قبول شد',
        ];
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast: string}
     */
    private function start(string $taskId, string $ref, string $actor): array
    {
        $task = $this->clickUpClient->getTask($taskId);
        $listId = $this->listIdFromTask($task);
        $status = $listId !== '' ? $this->clickUpClient->resolveInProgressStatus($listId) : 'in progress';
        $task = $this->clickUpClient->updateTaskStatus($taskId, $status);
        $this->clickUpClient->addTaskComment($taskId, "{$actor} کار را از تلگرام شروع کرد → {$status}");

        return [
            'text' => $this->formatTaskCard($task, "🚀 {$actor} شروع کرد ({$status})."),
            'keyboard' => $this->keyboardForTask($taskId),
            'toast' => 'شروع شد',
        ];
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast: string}
     */
    private function dueTomorrow(string $taskId, string $ref, string $actor): array
    {
        $timezone = JalaliCalendar::timezone();
        $dueMs = Carbon::now($timezone)->addDay()->startOfDay()->getTimestampMs();
        $task = $this->clickUpClient->updateTask($taskId, [
            'due_date' => $dueMs,
            'due_date_time' => false,
        ]);
        $this->clickUpClient->addTaskComment($taskId, "{$actor} ددلاین را از تلگرام روی فردا گذاشت.");

        return [
            'text' => $this->formatTaskCard($task, "📅 {$actor} ددلاین را فردا گذاشت."),
            'keyboard' => $this->keyboardForTask($taskId),
            'toast' => 'ددلاین فردا',
        ];
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function statusPicker(string $taskId, string $ref): array
    {
        $task = $this->clickUpClient->getTask($taskId);
        $listId = $this->listIdFromTask($task);
        $names = [];

        if ($listId !== '') {
            foreach ($this->clickUpClient->getListStatuses($listId) as $status) {
                $name = trim((string) ($status['status'] ?? ''));

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        TaskCallbackRef::rememberStatuses($ref, $names);
        $current = is_array($task['status'] ?? null) ? trim((string) ($task['status']['status'] ?? '')) : '';
        $keyboard = [];
        $row = [];

        foreach ($names as $index => $name) {
            $label = $name === $current ? '✓ '.$name : $name;
            $row[] = [
                'text' => mb_strlen($label) > 28 ? mb_substr($label, 0, 27).'…' : $label,
                'callback_data' => "as:set:{$ref}:{$index}",
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard[] = $row;
        }

        $keyboard[] = [
            ['text' => 'بازگشت', 'callback_data' => 'as:ok:'.$ref],
        ];

        $taskName = trim((string) ($task['name'] ?? $taskId));

        return [
            'text' => "وضعیت تسک:\n{$taskName}\nفعلی: ".($current !== '' ? $current : '—'),
            'keyboard' => $keyboard !== [] ? $keyboard : $this->keyboardForTask($taskId),
        ];
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast?: string, alert?: bool}
     */
    private function setStatus(string $taskId, string $ref, int $index, string $actor): array
    {
        $statuses = TaskCallbackRef::resolveStatuses($ref);

        if ($index < 0 || ! isset($statuses[$index])) {
            return [
                'text' => 'وضعیت منقضی شده.',
                'keyboard' => $this->keyboardForTask($taskId),
                'toast' => 'منقضی',
                'alert' => true,
            ];
        }

        $status = $statuses[$index];
        $task = $this->clickUpClient->updateTaskStatus($taskId, $status);
        $this->clickUpClient->addTaskComment($taskId, "{$actor} وضعیت را از تلگرام به {$status} تغییر داد.");

        return [
            'text' => $this->formatTaskCard($task, "وضعیت → {$status} (توسط {$actor})"),
            'keyboard' => $this->keyboardForTask($taskId),
            'toast' => 'وضعیت → '.$status,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function formatTaskCard(array $task, string $headline): string
    {
        $name = trim((string) ($task['name'] ?? 'تسک'));
        $status = is_array($task['status'] ?? null) ? trim((string) ($task['status']['status'] ?? '')) : '';
        $url = (string) ($task['url'] ?? '');
        $lines = [$headline, '', $name];

        if ($status !== '') {
            $lines[] = 'وضعیت: '.$status;
        }

        if ($url !== '') {
            $lines[] = $url;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function listIdFromTask(array $task): string
    {
        $list = $task['list'] ?? null;

        if (is_array($list) && (string) ($list['id'] ?? '') !== '') {
            return (string) $list['id'];
        }

        $configured = config('clickup.list_id');

        return is_string($configured) ? $configured : '';
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function actorName(array $from): string
    {
        $username = (string) ($from['username'] ?? '');
        $email = null;

        foreach (config('telegram.usernames', []) as $mappedEmail => $mapped) {
            if (mb_strtolower(ltrim(trim((string) $mapped), '@')) === mb_strtolower(ltrim($username, '@'))) {
                $email = (string) $mappedEmail;
                break;
            }
        }

        return $this->userNameResolver->resolve($email, $username !== '' ? $username : null);
    }
}
