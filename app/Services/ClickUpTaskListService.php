<?php

namespace App\Services;

use App\Support\TaskDoneDetector;
use Carbon\Carbon;

class ClickUpTaskListService
{
    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly TaskDoneDetector $taskDoneDetector,
        private readonly UserNameResolver $userNameResolver,
        private readonly TelegramUsernameResolver $telegramUsernameResolver,
    ) {
    }

    public function formatOpenTasks(): string
    {
        return $this->format($this->openTasks(), 'تسک‌های باز');
    }

    public function formatDueThroughTomorrow(): string
    {
        $timezone = $this->timezone();
        $tomorrow = Carbon::now($timezone)->addDay()->toDateString();

        $tasks = array_values(array_filter(
            $this->openTasks(),
            function (array $task) use ($tomorrow, $timezone): bool {
                if (! is_numeric($task['due_date'] ?? null)) {
                    return false;
                }

                $dueDate = Carbon::createFromTimestampMs((int) $task['due_date'], $timezone)->toDateString();

                return $dueDate <= $tomorrow;
            }
        ));

        return $this->format($tasks, 'تسک‌های امروز، فردا و عقب‌افتاده');
    }

    public function formatDueToday(): string
    {
        $timezone = $this->timezone();
        $today = Carbon::now($timezone)->toDateString();

        $tasks = array_values(array_filter(
            $this->openTasks(),
            function (array $task) use ($today, $timezone): bool {
                if (! is_numeric($task['due_date'] ?? null)) {
                    return false;
                }

                return Carbon::createFromTimestampMs((int) $task['due_date'], $timezone)->toDateString() === $today;
            }
        ));

        return $this->format($tasks, 'تسک‌های امروز');
    }

    public function formatOverdue(): string
    {
        $timezone = $this->timezone();
        $today = Carbon::now($timezone)->toDateString();

        $tasks = array_values(array_filter(
            $this->openTasks(),
            function (array $task) use ($today, $timezone): bool {
                if (! is_numeric($task['due_date'] ?? null)) {
                    return false;
                }

                return Carbon::createFromTimestampMs((int) $task['due_date'], $timezone)->toDateString() < $today;
            }
        ));

        return $this->format($tasks, 'تسک‌های عقب‌افتاده');
    }

    public function formatForTelegramUsername(string $username): string
    {
        $email = $this->emailForTelegramUsername($username);
        $tasks = $this->openTasksAssignedTo($email, $username);

        if ($email === null && $tasks === []) {
            return 'یوزرنیم تلگرام شما در TELEGRAM_USERNAMES ثبت نشده.';
        }

        $displayName = $this->userNameResolver->resolve($email, $username);
        $tagged = $this->telegramUsernameResolver->formatDisplayName($displayName, $email);

        return $this->formatPersonal($tasks, "تسک‌های {$tagged}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openTasks(): array
    {
        $tasks = [];

        foreach ($this->clickUpClient->getOpenTasks() as $task) {
            if (! is_array($task) || $this->taskDoneDetector->isApiTaskDone($task)) {
                continue;
            }

            $name = trim((string) ($task['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openTasksAssignedTo(?string $email, string $telegramUsername): array
    {
        return array_values(array_filter(
            $this->openTasks(),
            fn (array $task): bool => $this->taskAssignedTo($task, $email, $telegramUsername)
        ));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function taskAssignedTo(array $task, ?string $email, string $telegramUsername): bool
    {
        $assignees = $task['assignees'] ?? [];

        if (! is_array($assignees)) {
            return false;
        }

        $emailNeedle = $email !== null && $email !== '' ? mb_strtolower($email) : '';
        $usernameNeedle = mb_strtolower(ltrim(trim($telegramUsername), '@'));

        foreach ($assignees as $assignee) {
            if (! is_array($assignee)) {
                continue;
            }

            $assigneeEmail = isset($assignee['email']) ? mb_strtolower((string) $assignee['email']) : '';
            $assigneeUsername = isset($assignee['username']) ? mb_strtolower(ltrim((string) $assignee['username'], '@')) : '';

            if ($emailNeedle !== '' && $assigneeEmail === $emailNeedle) {
                return true;
            }

            if ($usernameNeedle !== '' && $assigneeUsername === $usernameNeedle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function formatPersonal(array $tasks, string $title): string
    {
        if ($tasks === []) {
            return $title.":\nهیچ تسکی پیدا نشد.";
        }

        $entries = [];

        foreach ($tasks as $task) {
            $entries[] = [
                'name' => trim((string) ($task['name'] ?? '')),
                'due' => $this->formatDueDate($task),
                'status' => $this->formatStatus($task),
                'tag' => $this->clickUpClient->resolveProjectTag($task),
                'priority' => $this->priorityRank($task),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority'];
        });

        $lines = [$title.' ('.count($entries).'):', ''];

        foreach ($entries as $task) {
            $line = '- '.$task['name'];
            $meta = array_values(array_filter([
                $task['due'],
                $task['status'] !== '—' ? $task['status'] : null,
                $task['tag'] !== '' ? $task['tag'] : null,
            ]));

            if ($meta !== []) {
                $line .= ' ('.implode('، ', $meta).')';
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function format(array $tasks, string $title): string
    {
        if ($tasks === []) {
            return $title.":\nهیچ تسکی پیدا نشد.";
        }

        $grouped = $this->groupByAssignee($tasks);
        $lines = [$title.' ('.count($tasks).'):'];

        foreach ($grouped as $person) {
            $lines[] = '';
            $lines[] = $person['display'].':';

            foreach ($person['tasks'] as $task) {
                $line = '- '.$task['name'];
                $meta = array_values(array_filter([
                    $task['due'],
                    $task['status'] !== '—' ? $task['status'] : null,
                    $task['tag'] !== '' ? $task['tag'] : null,
                ]));

                if ($meta !== []) {
                    $line .= ' ('.implode('، ', $meta).')';
                }

                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array{display: string, tasks: list<array{name: string, due: string, status: string, tag: string, priority: int}>}>
     */
    private function groupByAssignee(array $tasks): array
    {
        /** @var array<string, array{display: string, tasks: list<array{name: string, due: string, status: string, tag: string, priority: int}>}> $grouped */
        $grouped = [];

        foreach ($tasks as $task) {
            $entry = [
                'name' => trim((string) ($task['name'] ?? '')),
                'due' => $this->formatDueDate($task),
                'status' => $this->formatStatus($task),
                'tag' => $this->clickUpClient->resolveProjectTag($task),
                'priority' => $this->priorityRank($task),
            ];

            $assignees = $task['assignees'] ?? [];

            if (! is_array($assignees) || $assignees === []) {
                $grouped['__unassigned'] ??= [
                    'display' => 'بدون مسئول',
                    'tasks' => [],
                ];
                $grouped['__unassigned']['tasks'][] = $entry;

                continue;
            }

            foreach ($assignees as $assignee) {
                if (! is_array($assignee)) {
                    continue;
                }

                $email = isset($assignee['email']) ? (string) $assignee['email'] : null;
                $username = isset($assignee['username']) ? (string) $assignee['username'] : null;
                $key = $email ?: ($username ?: (string) ($assignee['id'] ?? ''));

                if ($key === '') {
                    continue;
                }

                if (! isset($grouped[$key])) {
                    $displayName = $this->userNameResolver->resolve($email, $username);
                    $grouped[$key] = [
                        'display' => $this->telegramUsernameResolver->formatDisplayName($displayName, $email),
                        'tasks' => [],
                    ];
                }

                $grouped[$key]['tasks'][] = $entry;
            }
        }

        foreach ($grouped as &$person) {
            usort($person['tasks'], static function (array $a, array $b): int {
                return $a['priority'] <=> $b['priority'];
            });
        }
        unset($person);

        $unassigned = $grouped['__unassigned'] ?? null;
        unset($grouped['__unassigned']);

        $people = array_values($grouped);

        if ($unassigned !== null) {
            $people[] = $unassigned;
        }

        return $people;
    }

    private function emailForTelegramUsername(string $username): ?string
    {
        $needle = mb_strtolower(ltrim(trim($username), '@'));

        if ($needle === '') {
            return null;
        }

        foreach (config('telegram.usernames', []) as $email => $mapped) {
            if (mb_strtolower(ltrim(trim((string) $mapped), '@')) === $needle) {
                return (string) $email;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function formatDueDate(array $task): string
    {
        if (! is_numeric($task['due_date'] ?? null)) {
            return 'بدون ددلاین';
        }

        $timezone = $this->timezone();
        $due = Carbon::createFromTimestampMs((int) $task['due_date'], $timezone);
        $today = Carbon::now($timezone)->toDateString();
        $dueDate = $due->toDateString();

        if ($dueDate < $today) {
            return 'عقب‌افتاده '.$due->format('Y-m-d');
        }

        if ($dueDate === $today) {
            return 'امروز';
        }

        if ($dueDate === Carbon::now($timezone)->addDay()->toDateString()) {
            return 'فردا';
        }

        return $due->format('Y-m-d');
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function formatStatus(array $task): string
    {
        $status = $task['status'] ?? null;

        if (! is_array($status)) {
            return '—';
        }

        $name = trim((string) ($status['status'] ?? ''));

        return $name !== '' ? $name : '—';
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function priorityRank(array $task): int
    {
        $priority = $task['priority'] ?? null;

        if (! is_array($priority)) {
            return 99;
        }

        $order = $priority['orderindex'] ?? $priority['id'] ?? null;

        if (is_numeric($order)) {
            return (int) $order;
        }

        return 99;
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}
