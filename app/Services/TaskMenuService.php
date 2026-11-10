<?php

namespace App\Services;

use App\Support\TaskCallbackRef;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class TaskMenuService
{
    public const CALLBACK_PREFIX = 'tm:';

    private const PAGE_SIZE = 8;

    private const REF_TTL_SECONDS = 3600;

    private const COMPOSE_TTL_SECONDS = 900;

    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly ClickUpTaskListService $taskListService,
        private readonly ScoreBreakdownService $scoreBreakdownService,
        private readonly UserNameResolver $userNameResolver,
    ) {
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function home(): array
    {
        return [
            'text' => implode("\n", [
                'مدیریت تسک‌های ClickUp',
                '',
                'از دکمه‌های زیر استفاده کن:',
            ]),
            'keyboard' => [
                [
                    ['text' => 'تسک‌های من', 'callback_data' => 'tm:list:my:0'],
                    ['text' => 'تسک‌های باز', 'callback_data' => 'tm:list:open:0'],
                ],
                [
                    ['text' => 'امروز', 'callback_data' => 'tm:list:today:0'],
                    ['text' => 'عقب‌افتاده', 'callback_data' => 'tm:list:overdue:0'],
                ],
                [
                    ['text' => 'امتیاز امروز', 'callback_data' => 'tm:scores:day'],
                    ['text' => 'امتیاز هفته', 'callback_data' => 'tm:scores:week'],
                    ['text' => 'امتیاز ماه', 'callback_data' => 'tm:scores:month'],
                ],
                [
                    ['text' => 'استریک‌ها', 'callback_data' => 'comp:home'],
                    ['text' => '➕ ایجاد تسک', 'callback_data' => 'tm:new'],
                ],
            ],
        ];
    }

    public function isMenuCallback(string $data): bool
    {
        return str_starts_with($data, self::CALLBACK_PREFIX);
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast?: string, alert?: bool, compose_prompt?: string}
     */
    public function handleCallback(string $data, array $from): array
    {
        if ($data === 'tm:home') {
            return $this->home();
        }

        if ($data === 'tm:new') {
            return $this->beginCreate($from);
        }

        if ($data === 'tm:cancel') {
            $this->clearCompose($from);

            $home = $this->home();
            $home['toast'] = 'لغو شد.';

            return $home;
        }

        if (preg_match('/^tm:scores:(day|week|month)$/', $data, $matches) === 1) {
            return $this->scoreBreakdownService->view($matches[1]);
        }

        if (preg_match('/^tm:list:(my|open|today|overdue):(\d+)$/', $data, $matches) === 1) {
            return $this->taskListView($matches[1], (int) $matches[2], $from);
        }

        if (preg_match('/^tm:task:([a-f0-9]{8})$/', $data, $matches) === 1) {
            return $this->taskDetailView($matches[1]);
        }

        if (preg_match('/^tm:st:([a-f0-9]{8})$/', $data, $matches) === 1) {
            return $this->statusPickerView($matches[1]);
        }

        if (preg_match('/^tm:set:([a-f0-9]{8}):(\d+)$/', $data, $matches) === 1) {
            return $this->applyStatus($matches[1], (int) $matches[2]);
        }

        $home = $this->home();
        $home['toast'] = 'دکمه نامعتبر.';
        $home['alert'] = true;

        return $home;
    }

    /**
     * @param  array<string, mixed>  $from
     */
    public function hasPendingCompose(array $from): bool
    {
        return $this->composeState($from) !== null;
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function completeCreate(array $from, string $title): array
    {
        $state = $this->composeState($from);

        if ($state === null) {
            $home = $this->home();
            $home['text'] = 'جلسه ایجاد تسک منقضی شده. دوباره از منو شروع کن.';

            return $home;
        }

        $title = trim($title);

        if ($title === '' || mb_strlen($title) > 500) {
            return [
                'text' => 'عنوان تسک نامعتبر است. یک عنوان کوتاه بفرست یا لغو را بزن.',
                'keyboard' => [
                    [['text' => 'لغو', 'callback_data' => 'tm:cancel']],
                ],
            ];
        }

        $listId = $this->clickUpClient->defaultListId();
        $payload = ['name' => $title];

        $assigneeId = $state['assignee_id'] ?? null;

        if (is_numeric($assigneeId)) {
            $payload['assignees'] = [(int) $assigneeId];
        }

        $task = $this->clickUpClient->createTask($listId, $payload);
        $this->clearCompose($from);

        $taskId = (string) ($task['id'] ?? '');
        $taskName = trim((string) ($task['name'] ?? $title));
        $url = (string) ($task['url'] ?? '');

        $lines = [
            '✅ تسک ساخته شد:',
            $taskName,
        ];

        if ($url !== '') {
            $lines[] = $url;
        }

        $keyboard = [
            [['text' => 'منوی اصلی', 'callback_data' => 'tm:home']],
        ];

        if ($taskId !== '') {
            $ref = $this->rememberTaskId($taskId);
            array_unshift($keyboard, [['text' => 'مشاهده / تغییر وضعیت', 'callback_data' => 'tm:task:'.$ref]]);
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => $keyboard,
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast: string, compose_prompt: string}
     */
    private function beginCreate(array $from): array
    {
        $assigneeId = $this->clickUpUserIdForTelegram($from);
        $email = $this->taskListService->resolveEmailForTelegramUsername(
            (string) ($from['username'] ?? '')
        );

        $this->putCompose($from, [
            'step' => 'await_title',
            'assignee_id' => $assigneeId,
            'email' => $email,
        ]);

        $listHint = '';

        try {
            $listId = $this->clickUpClient->defaultListId();
            $listHint = "\nلیست پیش‌فرض: {$listId}";
        } catch (\Throwable) {
            // validated again on submit
        }

        return [
            'text' => 'عنوان تسک جدید را در یک پیام بفرست.'.$listHint."\nبرای انصراف «لغو» را بزن.",
            'keyboard' => [
                [['text' => 'لغو', 'callback_data' => 'tm:cancel']],
            ],
            'toast' => 'عنوان را بفرست',
            'compose_prompt' => 'await_title',
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function taskListView(string $filter, int $page, array $from): array
    {
        $tasks = $this->tasksForFilter($filter, $from);
        $total = count($tasks);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(0, min($page, $pages - 1));
        $slice = array_slice($tasks, $page * self::PAGE_SIZE, self::PAGE_SIZE);

        $title = match ($filter) {
            'my' => 'تسک‌های من',
            'today' => 'تسک‌های امروز',
            'overdue' => 'تسک‌های عقب‌افتاده',
            default => 'تسک‌های باز',
        };

        if ($total === 0) {
            return [
                'text' => $title.":\nهیچ تسکی پیدا نشد.",
                'keyboard' => [
                    [['text' => 'منوی اصلی', 'callback_data' => 'tm:home']],
                ],
            ];
        }

        $lines = [
            sprintf('%s (%d) — صفحه %d از %d', $title, $total, $page + 1, $pages),
            'روی هر تسک بزن تا جزئیات و تغییر وضعیت را ببینی.',
            '',
        ];

        $keyboard = [];

        foreach ($slice as $task) {
            if (! is_array($task)) {
                continue;
            }

            $taskId = (string) ($task['id'] ?? '');
            $name = trim((string) ($task['name'] ?? ''));

            if ($taskId === '' || $name === '') {
                continue;
            }

            $status = $this->statusName($task);
            $due = $this->shortDue($task);
            $label = $this->truncate($name, 40);

            if ($status !== '') {
                $label .= ' · '.$this->truncate($status, 12);
            }

            if ($due !== '') {
                $label .= ' · '.$due;
            }

            $ref = $this->rememberTaskId($taskId);
            $keyboard[] = [[
                'text' => $label,
                'callback_data' => 'tm:task:'.$ref,
            ]];

            $lines[] = '• '.$name.($status !== '' ? " [{$status}]" : '');
        }

        $nav = [];

        if ($page > 0) {
            $nav[] = ['text' => '« قبلی', 'callback_data' => "tm:list:{$filter}:".($page - 1)];
        }

        if ($page < $pages - 1) {
            $nav[] = ['text' => 'بعدی »', 'callback_data' => "tm:list:{$filter}:".($page + 1)];
        }

        if ($nav !== []) {
            $keyboard[] = $nav;
        }

        $keyboard[] = [
            ['text' => 'منوی اصلی', 'callback_data' => 'tm:home'],
            ['text' => '➕ ایجاد', 'callback_data' => 'tm:new'],
        ];

        return [
            'text' => implode("\n", $lines),
            'keyboard' => $keyboard,
        ];
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function taskDetailView(string $ref): array
    {
        $taskId = $this->resolveTaskId($ref);

        if ($taskId === null) {
            $home = $this->home();
            $home['text'] = "این دکمه منقضی شده.\nدوباره از منو لیست را باز کن.";

            return $home;
        }

        $task = $this->clickUpClient->getTask($taskId);
        $this->rememberTaskId($taskId, $ref);

        return $this->buildTaskDetail($task, $ref);
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function statusPickerView(string $ref): array
    {
        $taskId = $this->resolveTaskId($ref);

        if ($taskId === null) {
            $home = $this->home();
            $home['text'] = 'این دکمه منقضی شده.';

            return $home;
        }

        $task = $this->clickUpClient->getTask($taskId);
        $listId = $this->listIdFromTask($task);

        if ($listId === '') {
            return [
                'text' => 'لیست این تسک پیدا نشد.',
                'keyboard' => [
                    [['text' => 'بازگشت', 'callback_data' => 'tm:task:'.$ref]],
                ],
            ];
        }

        $statuses = $this->clickUpClient->getListStatuses($listId);
        $names = [];

        foreach ($statuses as $status) {
            $name = trim((string) ($status['status'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        $this->rememberStatuses($ref, $names);
        $current = $this->statusName($task);

        $keyboard = [];
        $row = [];

        foreach ($names as $index => $name) {
            $label = $name === $current ? '✓ '.$name : $name;
            $row[] = [
                'text' => $this->truncate($label, 28),
                'callback_data' => "tm:set:{$ref}:{$index}",
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
            ['text' => 'بازگشت', 'callback_data' => 'tm:task:'.$ref],
            ['text' => 'منوی اصلی', 'callback_data' => 'tm:home'],
        ];

        $taskName = trim((string) ($task['name'] ?? $taskId));

        return [
            'text' => "تغییر وضعیت:\n{$taskName}\nوضعیت فعلی: ".($current !== '' ? $current : '—'),
            'keyboard' => $keyboard,
        ];
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast?: string, alert?: bool}
     */
    private function applyStatus(string $ref, int $index): array
    {
        $taskId = $this->resolveTaskId($ref);
        $statuses = $this->resolveStatuses($ref);

        if ($taskId === null || $statuses === [] || ! isset($statuses[$index])) {
            $home = $this->home();
            $home['toast'] = 'منقضی شده؛ دوباره تلاش کن.';
            $home['alert'] = true;

            return $home;
        }

        $status = $statuses[$index];
        $task = $this->clickUpClient->updateTaskStatus($taskId, $status);
        $this->rememberTaskId($taskId, $ref);

        $view = $this->buildTaskDetail($task, $ref);
        $view['toast'] = 'وضعیت → '.$status;

        return $view;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function buildTaskDetail(array $task, string $ref): array
    {
        $name = trim((string) ($task['name'] ?? ''));
        $status = $this->statusName($task);
        $due = $this->shortDue($task);
        $url = (string) ($task['url'] ?? '');
        $assignees = $this->formatAssignees($task);
        $tag = $this->clickUpClient->resolveProjectTag($task);

        $lines = [
            $name !== '' ? $name : 'تسک',
            '',
            'وضعیت: '.($status !== '' ? $status : '—'),
            'ددلاین: '.($due !== '' ? $due : 'بدون ددلاین'),
            'مسئول: '.($assignees !== '' ? $assignees : '—'),
        ];

        if ($tag !== '') {
            $lines[] = 'پروژه: '.$tag;
        }

        if ($url !== '') {
            $lines[] = '';
            $lines[] = $url;
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => [
                [['text' => 'تغییر وضعیت', 'callback_data' => 'tm:st:'.$ref]],
                [
                    ['text' => 'منوی اصلی', 'callback_data' => 'tm:home'],
                    ['text' => 'تسک‌های من', 'callback_data' => 'tm:list:my:0'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @return list<array<string, mixed>>
     */
    private function tasksForFilter(string $filter, array $from): array
    {
        return match ($filter) {
            'my' => $this->taskListService->tasksForTelegramUsername((string) ($from['username'] ?? '')),
            'today' => $this->taskListService->dueTodayTasks(),
            'overdue' => $this->taskListService->overdueTasks(),
            default => $this->taskListService->openTasks(),
        };
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function statusName(array $task): string
    {
        $status = $task['status'] ?? null;

        if (! is_array($status)) {
            return '';
        }

        return trim((string) ($status['status'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function shortDue(array $task): string
    {
        if (! is_numeric($task['due_date'] ?? null)) {
            return '';
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $due = Carbon::createFromTimestampMs((int) $task['due_date'], $timezone);
        $today = Carbon::now($timezone)->toDateString();
        $dueDate = $due->toDateString();

        if ($dueDate < $today) {
            return 'عقب‌افتاده';
        }

        if ($dueDate === $today) {
            return 'امروز';
        }

        if ($dueDate === Carbon::now($timezone)->addDay()->toDateString()) {
            return 'فردا';
        }

        return $due->format('m/d');
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function formatAssignees(array $task): string
    {
        $assignees = $task['assignees'] ?? [];

        if (! is_array($assignees) || $assignees === []) {
            return '';
        }

        $names = [];

        foreach ($assignees as $assignee) {
            if (! is_array($assignee)) {
                continue;
            }

            $email = isset($assignee['email']) ? (string) $assignee['email'] : null;
            $username = isset($assignee['username']) ? (string) $assignee['username'] : null;
            $names[] = $this->userNameResolver->resolve($email, $username);
        }

        return implode('، ', array_values(array_filter($names)));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function listIdFromTask(array $task): string
    {
        $list = $task['list'] ?? null;

        if (is_array($list) && isset($list['id']) && (string) $list['id'] !== '') {
            return (string) $list['id'];
        }

        $configured = config('clickup.list_id');

        return is_string($configured) ? $configured : '';
    }

    private function rememberTaskId(string $taskId, ?string $ref = null): string
    {
        return TaskCallbackRef::remember($taskId, $ref);
    }

    private function resolveTaskId(string $ref): ?string
    {
        return TaskCallbackRef::resolve($ref);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function rememberStatuses(string $ref, array $statuses): void
    {
        TaskCallbackRef::rememberStatuses($ref, $statuses);
    }

    /**
     * @return list<string>
     */
    private function resolveStatuses(string $ref): array
    {
        return TaskCallbackRef::resolveStatuses($ref);
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array<string, mixed>|null
     */
    private function composeState(array $from): ?array
    {
        $state = Cache::get($this->composeKey($from));

        return is_array($state) ? $state : null;
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $state
     */
    private function putCompose(array $from, array $state): void
    {
        Cache::put($this->composeKey($from), $state, now()->addSeconds(self::COMPOSE_TTL_SECONDS));
    }

    /**
     * @param  array<string, mixed>  $from
     */
    public function clearCompose(array $from): void
    {
        Cache::forget($this->composeKey($from));
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function composeKey(array $from): string
    {
        $userId = (string) ($from['id'] ?? '0');

        return 'telegram:tm:compose:'.$userId;
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function clickUpUserIdForTelegram(array $from): ?int
    {
        $email = $this->taskListService->resolveEmailForTelegramUsername((string) ($from['username'] ?? ''));

        if ($email === null || $email === '') {
            return null;
        }

        $emailNeedle = mb_strtolower($email);

        try {
            foreach ($this->clickUpClient->getTeamMembers() as $member) {
                if (mb_strtolower($member['email']) === $emailNeedle) {
                    return $member['id'];
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return null;
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, max(1, $max - 1)).'…';
    }
}
