<?php

namespace App\Services;

use App\Models\TaskScore;
use App\Support\TaskDoneDetector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupMissedDoneTasks
{
    private const DAY_QUEUE_KEY = 'clickup_backup_missed:day_queue';

    private const TASK_QUEUE_KEY = 'clickup_backup_missed:task_queue';

    private const LAST_SEED_KEY = 'clickup_backup_missed:last_seed_date';

    private const LOCK_KEY = 'clickup_backup_missed:lock';

    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly TaskDoneDetector $taskDoneDetector,
        private readonly TaskScoreSyncService $taskScoreSyncService,
        private readonly TelegramNotifier $telegramNotifier,
        private readonly UserNameResolver $userNameResolver,
    ) {
    }

    /**
     * Process one slice of the backlog: drain a few queued tasks, or fetch the next day into the queue.
     *
     * @return array{
     *     day: ?string,
     *     fetched: int,
     *     queued: int,
     *     registered: int,
     *     notified: int,
     *     remaining_days: int,
     *     remaining_tasks: int
     * }
     */
    public function run(): array
    {
        $empty = [
            'day' => null,
            'fetched' => 0,
            'queued' => 0,
            'registered' => 0,
            'notified' => 0,
            'remaining_days' => 0,
            'remaining_tasks' => 0,
        ];

        try {
            $this->clickUpClient->requireConfigured();
        } catch (\RuntimeException $exception) {
            Log::warning('Backup missed done tasks skipped: '.$exception->getMessage());

            return $empty;
        }

        $lock = Cache::lock(self::LOCK_KEY, 120);

        try {
            if (! $lock->get()) {
                return $empty;
            }
        } catch (Throwable $exception) {
            Log::warning('Backup missed done tasks lock failed: '.$exception->getMessage());
        }

        try {
            return $this->runLocked();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * @return array{
     *     day: ?string,
     *     fetched: int,
     *     queued: int,
     *     registered: int,
     *     notified: int,
     *     remaining_days: int,
     *     remaining_tasks: int
     * }
     */
    private function runLocked(): array
    {
        $this->ensureDayQueue();

        $day = null;
        $fetched = 0;
        $queued = 0;

        $taskQueue = $this->taskQueue();

        // Skip empty days in one run so the week queue does not stall, but still
        // only score a small batch of missing tasks to avoid hanging on LLM calls.
        $maxDayFetches = max(1, (int) config('clickup.backup_lookback_days', 7));

        for ($fetch = 0; $fetch < $maxDayFetches && $taskQueue === []; $fetch++) {
            $dayQueue = $this->dayQueue();
            $nextDay = array_shift($dayQueue);
            $this->putDayQueue($dayQueue);

            if ($nextDay === null) {
                break;
            }

            $day = $nextDay;
            [$dayFetched, $dayQueued, $taskQueue] = $this->enqueueMissingTasksForDay($day);
            $fetched += $dayFetched;
            $queued += $dayQueued;
            $this->putTaskQueue($taskQueue);
        }

        $batchSize = max(1, (int) config('clickup.backup_tasks_per_run', 3));
        $registered = 0;
        $notified = 0;

        for ($i = 0; $i < $batchSize && $taskQueue !== []; $i++) {
            $item = array_shift($taskQueue);

            if (! is_array($item)) {
                continue;
            }

            $taskId = (string) ($item['task_id'] ?? '');
            $itemDay = (string) ($item['day'] ?? '');

            if ($taskId === '') {
                continue;
            }

            if (TaskScore::query()->where('task_id', $taskId)->exists()) {
                continue;
            }

            $result = $this->registerAndNotify($taskId, $itemDay);

            if ($result['registered']) {
                $registered++;
            }

            if ($result['notified']) {
                $notified++;
            }
        }

        $this->putTaskQueue($taskQueue);

        return [
            'day' => $day,
            'fetched' => $fetched,
            'queued' => $queued,
            'registered' => $registered,
            'notified' => $notified,
            'remaining_days' => count($this->dayQueue()),
            'remaining_tasks' => count($taskQueue),
        ];
    }

    private function ensureDayQueue(): void
    {
        if ($this->dayQueue() !== [] || $this->taskQueue() !== []) {
            return;
        }

        $timezone = $this->timezone();
        $today = Carbon::now($timezone)->toDateString();
        $lastSeed = Cache::get(self::LAST_SEED_KEY);

        if (is_string($lastSeed) && $lastSeed === $today) {
            return;
        }

        $lookback = max(1, (int) config('clickup.backup_lookback_days', 7));
        $days = [];

        for ($offset = $lookback; $offset >= 1; $offset--) {
            $days[] = Carbon::now($timezone)->subDays($offset)->toDateString();
        }

        $this->putDayQueue($days);
        Cache::put(self::LAST_SEED_KEY, $today, now()->addDays(14));
    }

    /**
     * @return array{0: int, 1: int, 2: list<array{task_id: string, day: string}>}
     */
    private function enqueueMissingTasksForDay(string $day): array
    {
        $timezone = $this->timezone();
        $from = Carbon::parse($day, $timezone)->startOfDay();
        $to = $from->copy()->endOfDay();

        $tasks = $this->clickUpClient->getClosedTasksDoneBetween(
            $from->getTimestampMs(),
            $to->copy()->addSecond()->getTimestampMs()
        );

        $existing = TaskScore::query()
            ->whereIn('task_id', $this->taskIds($tasks))
            ->pluck('task_id')
            ->flip()
            ->all();

        $queue = [];
        $seen = [];

        foreach ($tasks as $task) {
            if (! is_array($task) || ! $this->taskDoneDetector->isApiTaskDone($task)) {
                continue;
            }

            $taskId = (string) ($task['id'] ?? '');

            if ($taskId === '' || isset($seen[$taskId]) || isset($existing[$taskId])) {
                continue;
            }

            $seen[$taskId] = true;
            $queue[] = [
                'task_id' => $taskId,
                'day' => $day,
            ];
        }

        return [count($tasks), count($queue), $queue];
    }

    /**
     * @return array{registered: bool, notified: bool}
     */
    private function registerAndNotify(string $taskId, string $day): array
    {
        $task = $this->clickUpClient->getTask($taskId);

        if ($task === [] || ! $this->taskDoneDetector->isApiTaskDone($task)) {
            return ['registered' => false, 'notified' => false];
        }

        if (TaskScore::query()->where('task_id', $taskId)->exists()) {
            return ['registered' => false, 'notified' => false];
        }

        [$email, $username] = $this->resolveTaskUser($task);

        try {
            $score = $this->taskScoreSyncService->scoreTaskIfNeeded($task, $email, $username);
        } catch (Throwable $exception) {
            Log::warning('Backup missed task score failed for '.$taskId.': '.$exception->getMessage());

            return ['registered' => false, 'notified' => false];
        }

        if ($score === null) {
            return ['registered' => false, 'notified' => false];
        }

        $cacheKey = 'clickup_backup_missed:notified:'.$taskId;

        if (! Cache::add($cacheKey, true, now()->addDays(30))) {
            return ['registered' => true, 'notified' => false];
        }

        $displayName = $this->userNameResolver->resolve($email, $username);
        $taskName = trim((string) ($task['name'] ?? $taskId));
        $dateLabel = $day !== '' ? $day : $this->resolveCompletedDate($task);
        $scoreLabel = (int) $score->score;

        $lines = [
            '⚠️ تسک قدیمی ثبت نشده',
            $taskName,
            $displayName,
            'تاریخ: '.$dateLabel,
        ];

        if ($scoreLabel > 0) {
            $lines[] = 'امتیاز: '.$scoreLabel;
        }

        try {
            $this->telegramNotifier->send(implode("\n", $lines));
        } catch (Throwable $exception) {
            Log::warning('Backup missed task telegram failed for '.$taskId.': '.$exception->getMessage());

            return ['registered' => true, 'notified' => false];
        }

        return ['registered' => true, 'notified' => true];
    }

    /**
     * @param  list<mixed>  $tasks
     * @return list<string>
     */
    private function taskIds(array $tasks): array
    {
        $ids = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $taskId = (string) ($task['id'] ?? '');

            if ($taskId !== '') {
                $ids[] = $taskId;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveTaskUser(array $task): array
    {
        $assignees = $task['assignees'] ?? [];

        if (is_array($assignees) && isset($assignees[0]) && is_array($assignees[0])) {
            return [
                isset($assignees[0]['email']) ? (string) $assignees[0]['email'] : null,
                isset($assignees[0]['username']) ? (string) $assignees[0]['username'] : null,
            ];
        }

        $creator = $task['creator'] ?? null;

        if (is_array($creator)) {
            return [
                isset($creator['email']) ? (string) $creator['email'] : null,
                isset($creator['username']) ? (string) $creator['username'] : null,
            ];
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function resolveCompletedDate(array $task): string
    {
        foreach (['date_done', 'date_closed', 'date_updated'] as $field) {
            $value = $task[$field] ?? null;

            if (is_numeric($value) && (int) $value > 0) {
                return Carbon::createFromTimestampMs((int) $value, $this->timezone())->toDateString();
            }
        }

        return Carbon::now($this->timezone())->toDateString();
    }

    /**
     * @return list<string>
     */
    private function dayQueue(): array
    {
        $value = Cache::get(self::DAY_QUEUE_KEY, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($day): bool => is_string($day) && $day !== ''));
    }

    /**
     * @param  list<string>  $days
     */
    private function putDayQueue(array $days): void
    {
        Cache::put(self::DAY_QUEUE_KEY, array_values($days), now()->addDays(14));
    }

    /**
     * @return list<array{task_id: string, day: string}>
     */
    private function taskQueue(): array
    {
        $value = Cache::get(self::TASK_QUEUE_KEY, []);

        if (! is_array($value)) {
            return [];
        }

        $queue = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $taskId = (string) ($item['task_id'] ?? '');
            $day = (string) ($item['day'] ?? '');

            if ($taskId === '') {
                continue;
            }

            $queue[] = [
                'task_id' => $taskId,
                'day' => $day,
            ];
        }

        return $queue;
    }

    /**
     * @param  list<array{task_id: string, day: string}>  $queue
     */
    private function putTaskQueue(array $queue): void
    {
        Cache::put(self::TASK_QUEUE_KEY, array_values($queue), now()->addDays(14));
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Tehran');
    }
}
