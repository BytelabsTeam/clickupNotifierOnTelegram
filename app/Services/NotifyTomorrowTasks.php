<?php

namespace App\Services;

use App\Support\TaskDoneDetector;
use Carbon\Carbon;

class NotifyTomorrowTasks
{
    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly TaskDoneDetector $taskDoneDetector,
        private readonly UserNameResolver $userNameResolver,
        private readonly TelegramUsernameResolver $telegramUsernameResolver,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    /**
     * @return array{sent: bool, people: int, tasks: int, fetched: int, due_from: ?string, due_to: string, timezone: string}
     */
    public function notify(): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $tomorrow = Carbon::now($timezone)->addDay();
        $dueBeforeMs = $tomorrow->copy()->endOfDay()->getTimestampMs() + 1;
        $rawTasks = $this->clickUpClient->getOpenTasksDueBefore($dueBeforeMs);
        $tasks = $this->filterTasksDueThroughTomorrow($rawTasks, $timezone);
        $grouped = $this->groupTasksByAssignee($tasks);

        $meta = [
            'fetched' => count($rawTasks),
            'due_from' => null,
            'due_to' => $tomorrow->copy()->endOfDay()->toIso8601String(),
            'timezone' => $timezone,
        ];

        if ($grouped === []) {
            return ['sent' => false, 'people' => 0, 'tasks' => 0, ...$meta];
        }

        $message = $this->formatMessage($grouped);
        $this->telegramNotifier->send($message);

        $taskCount = 0;
        foreach ($grouped as $person) {
            $taskCount += count($person['tasks']);
        }

        return [
            'sent' => true,
            'people' => count($grouped),
            'tasks' => $taskCount,
            ...$meta,
        ];
    }

    /**
     * Keep open tasks whose due date is on or before tomorrow in the app timezone.
     * Overdue tasks (yesterday and earlier) are included; day-after-tomorrow is excluded.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private function filterTasksDueThroughTomorrow(array $tasks, string $timezone): array
    {
        $tomorrow = Carbon::now($timezone)->addDay()->toDateString();

        return array_values(array_filter($tasks, static function (mixed $task) use ($tomorrow, $timezone): bool {
            if (! is_array($task) || ! is_numeric($task['due_date'] ?? null)) {
                return false;
            }

            $dueDate = Carbon::createFromTimestampMs((int) $task['due_date'], $timezone)->toDateString();

            return $dueDate <= $tomorrow;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array{email: ?string, display: string, tasks: list<array{name: string, tag: string, priority: int}>}>
     */
    private function groupTasksByAssignee(array $tasks): array
    {
        /** @var array<string, array{email: ?string, display: string, tasks: list<array{name: string, tag: string, priority: int}>}> $grouped */
        $grouped = [];

        foreach ($tasks as $task) {
            if (! is_array($task) || $this->taskDoneDetector->isApiTaskDone($task)) {
                continue;
            }

            $assignees = $task['assignees'] ?? [];

            if (! is_array($assignees) || $assignees === []) {
                continue;
            }

            $taskName = trim((string) ($task['name'] ?? ''));

            if ($taskName === '') {
                continue;
            }

            $projectTag = $this->clickUpClient->resolveProjectTag($task);
            $priority = $this->priorityRank($task);

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
                        'email' => $email,
                        'display' => $this->telegramUsernameResolver->formatDisplayName($displayName, $email),
                        'tasks' => [],
                    ];
                }

                $grouped[$key]['tasks'][] = [
                    'name' => $taskName,
                    'tag' => $projectTag,
                    'priority' => $priority,
                ];
            }
        }

        foreach ($grouped as &$person) {
            usort($person['tasks'], static function (array $a, array $b): int {
                return $a['priority'] <=> $b['priority'];
            });
        }
        unset($person);

        return array_values($grouped);
    }

    /**
     * @param  list<array{email: ?string, display: string, tasks: list<array{name: string, tag: string, priority: int}>}>  $grouped
     */
    private function formatMessage(array $grouped): string
    {
        $lines = ['تسک های فردا:'];

        foreach ($grouped as $person) {
            $lines[] = ''; 
            $lines[] = $person['display'].':';

            foreach ($person['tasks'] as $task) {
                $line = '- '.$task['name'];

                if ($task['tag'] !== '') {
                    $line .= ' ('.$task['tag'].')';
                }

                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

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
}
