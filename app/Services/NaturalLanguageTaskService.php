<?php

namespace App\Services;

use App\Support\JalaliCalendar;
use App\Support\TaskCallbackRef;
use Carbon\Carbon;

class NaturalLanguageTaskService
{
    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly IranRouterClient $iranRouterClient,
        private readonly UserNameResolver $userNameResolver,
    ) {
    }

    public function looksLikeCreateIntent(string $text): bool
    {
        $normalized = trim(str_replace('‌', ' ', $text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        if (preg_match('/^\/task(?:@\w+)?(?:\s+|$)/iu', $normalized) === 1) {
            return true;
        }

        return (bool) preg_match('/^(بساز تسک|تسک بساز|ایجاد تسک)\b/u', $normalized);
    }

    public function extractPrompt(string $text): string
    {
        $text = trim($text);

        if (preg_match('/^\/task(?:@\w+)?\s*(.*)$/isu', $text, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        if (preg_match('/^(?:بساز تسک|تسک بساز|ایجاد تسک)\s*(.*)$/u', $text, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function createFromText(string $text, array $from): array
    {
        $prompt = $this->extractPrompt($text);

        if ($prompt === '') {
            return [
                'text' => "عنوان تسک را بعد از دستور بنویس.\nمثال: /task فردا برای عارف ریفکتور لاگین با اولویت بالا",
                'keyboard' => [[['text' => 'منو', 'callback_data' => 'tm:home']]],
            ];
        }

        $parsed = $this->parse($prompt);
        $listId = $this->clickUpClient->defaultListId();
        $payload = [
            'name' => $parsed['title'],
        ];

        if ($parsed['due_ms'] !== null) {
            $payload['due_date'] = $parsed['due_ms'];
            $payload['due_date_time'] = false;
        }

        if ($parsed['priority'] !== null) {
            $payload['priority'] = $parsed['priority'];
        }

        if ($parsed['assignee_id'] !== null) {
            $payload['assignees'] = [$parsed['assignee_id']];
        } else {
            $selfId = $this->clickUpUserIdForTelegram($from);

            if ($selfId !== null) {
                $payload['assignees'] = [$selfId];
            }
        }

        $task = $this->clickUpClient->createTask($listId, $payload);
        $taskId = (string) ($task['id'] ?? '');
        $name = trim((string) ($task['name'] ?? $parsed['title']));
        $url = (string) ($task['url'] ?? '');
        $lines = ['✅ تسک از متن ساخته شد:', $name];

        if (! empty($parsed['notes'])) {
            $lines[] = $parsed['notes'];
        }

        if ($url !== '') {
            $lines[] = $url;
        }

        $keyboard = [[['text' => 'منو', 'callback_data' => 'tm:home']]];

        if ($taskId !== '') {
            $ref = TaskCallbackRef::remember($taskId);
            array_unshift($keyboard, [
                ['text' => 'مشاهده', 'callback_data' => 'tm:task:'.$ref],
                ['text' => 'تغییر وضعیت', 'callback_data' => 'tm:st:'.$ref],
            ]);
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => $keyboard,
        ];
    }

    /**
     * @return array{title: string, due_ms: ?int, priority: ?int, assignee_id: ?int, notes: string}
     */
    private function parse(string $prompt): array
    {
        $llm = $this->parseWithLlm($prompt);

        if ($llm !== null) {
            return $llm;
        }

        return $this->parseFallback($prompt);
    }

    /**
     * @return array{title: string, due_ms: ?int, priority: ?int, assignee_id: ?int, notes: string}|null
     */
    private function parseWithLlm(string $prompt): ?array
    {
        try {
            $this->iranRouterClient->requireApiKey();
        } catch (\Throwable) {
            return null;
        }

        $memberHints = [];

        foreach (config('clickup.user_names', []) as $email => $name) {
            $memberHints[] = $name.' <'.$email.'>';
        }

        try {
            $raw = $this->iranRouterClient->chat([
                [
                    'role' => 'system',
                    'content' => 'Extract a ClickUp task from Persian/English user text. Reply ONLY JSON: {"title":"...","due":"today|tomorrow|YYYY-MM-DD|null","priority":1|2|3|4|null,"assignee_email":"email or null","notes":"short"}. priority: 1 urgent, 2 high, 3 normal, 4 low. Known people: '.implode(', ', $memberHints),
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ], 300);

            if (! preg_match('/\{.*\}/s', $raw, $matches)) {
                return null;
            }

            $json = json_decode($matches[0], true);

            if (! is_array($json) || trim((string) ($json['title'] ?? '')) === '') {
                return null;
            }

            return [
                'title' => trim((string) $json['title']),
                'due_ms' => $this->dueToMs(isset($json['due']) ? (string) $json['due'] : null),
                'priority' => is_numeric($json['priority'] ?? null) ? (int) $json['priority'] : null,
                'assignee_id' => $this->assigneeIdFromEmail(isset($json['assignee_email']) ? (string) $json['assignee_email'] : null),
                'notes' => trim((string) ($json['notes'] ?? '')),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array{title: string, due_ms: ?int, priority: ?int, assignee_id: ?int, notes: string}
     */
    private function parseFallback(string $prompt): array
    {
        $dueMs = null;
        $priority = null;
        $title = $prompt;

        if (preg_match('/\b(فردا|tomorrow)\b/ui', $prompt) === 1) {
            $dueMs = Carbon::now(JalaliCalendar::timezone())->addDay()->startOfDay()->getTimestampMs();
            $title = trim(preg_replace('/\b(فردا|tomorrow)\b/ui', '', $title) ?? $title);
        } elseif (preg_match('/\b(امروز|today)\b/ui', $prompt) === 1) {
            $dueMs = Carbon::now(JalaliCalendar::timezone())->startOfDay()->getTimestampMs();
            $title = trim(preg_replace('/\b(امروز|today)\b/ui', '', $title) ?? $title);
        }

        if (preg_match('/\b(فوری|urgent)\b/ui', $prompt) === 1) {
            $priority = 1;
        } elseif (preg_match('/\b(اولویت بالا|high)\b/ui', $prompt) === 1) {
            $priority = 2;
        }

        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return [
            'title' => $title !== '' ? $title : $prompt,
            'due_ms' => $dueMs,
            'priority' => $priority,
            'assignee_id' => null,
            'notes' => 'پارس ساده بدون LLM',
        ];
    }

    private function dueToMs(?string $due): ?int
    {
        if ($due === null || $due === '' || $due === 'null') {
            return null;
        }

        $timezone = JalaliCalendar::timezone();
        $due = mb_strtolower(trim($due));

        if ($due === 'today' || $due === 'امروز') {
            return Carbon::now($timezone)->startOfDay()->getTimestampMs();
        }

        if ($due === 'tomorrow' || $due === 'فردا') {
            return Carbon::now($timezone)->addDay()->startOfDay()->getTimestampMs();
        }

        try {
            return Carbon::parse($due, $timezone)->startOfDay()->getTimestampMs();
        } catch (\Throwable) {
            return null;
        }
    }

    private function assigneeIdFromEmail(?string $email): ?int
    {
        if ($email === null || $email === '') {
            return null;
        }

        $needle = mb_strtolower(trim($email));

        try {
            foreach ($this->clickUpClient->getTeamMembers() as $member) {
                if (mb_strtolower($member['email']) === $needle) {
                    return $member['id'];
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function clickUpUserIdForTelegram(array $from): ?int
    {
        $username = mb_strtolower(ltrim((string) ($from['username'] ?? ''), '@'));

        if ($username === '') {
            return null;
        }

        $email = null;

        foreach (config('telegram.usernames', []) as $mappedEmail => $mapped) {
            if (mb_strtolower(ltrim(trim((string) $mapped), '@')) === $username) {
                $email = mb_strtolower((string) $mappedEmail);
                break;
            }
        }

        if ($email === null) {
            return null;
        }

        return $this->assigneeIdFromEmail($email);
    }
}
