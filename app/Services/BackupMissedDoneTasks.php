<?php

namespace App\Services;

use App\Models\CommitScore;
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

    private const GITHUB_DAY_QUEUE_KEY = 'github_backup_missed:day_queue';

    private const GITHUB_COMMIT_QUEUE_KEY = 'github_backup_missed:commit_queue';

    private const GITHUB_LAST_SEED_KEY = 'github_backup_missed:last_seed_date';

    private const LOCK_KEY = 'backup_missed:lock';

    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly GitHubClient $gitHubClient,
        private readonly TaskDoneDetector $taskDoneDetector,
        private readonly TaskScoreSyncService $taskScoreSyncService,
        private readonly CommitScoreSyncService $commitScoreSyncService,
        private readonly TelegramNotifier $telegramNotifier,
        private readonly UserNameResolver $userNameResolver,
        private readonly CommitMessageTranslator $commitMessageTranslator,
    ) {
    }

    /**
     * Process one slice of ClickUp + GitHub backlog so the cron does not hang.
     *
     * @return array{
     *     day: ?string,
     *     fetched: int,
     *     queued: int,
     *     registered: int,
     *     notified: int,
     *     remaining_days: int,
     *     remaining_tasks: int,
     *     clickup: array{
     *         day: ?string,
     *         fetched: int,
     *         queued: int,
     *         registered: int,
     *         notified: int,
     *         remaining_days: int,
     *         remaining_tasks: int
     *     },
     *     github: array{
     *         day: ?string,
     *         fetched: int,
     *         queued: int,
     *         registered: int,
     *         notified: int,
     *         remaining_days: int,
     *         remaining_commits: int
     *     }
     * }
     */
    public function run(): array
    {
        $emptySource = [
            'day' => null,
            'fetched' => 0,
            'queued' => 0,
            'registered' => 0,
            'notified' => 0,
            'remaining_days' => 0,
            'remaining_tasks' => 0,
        ];

        $emptyGithub = [
            'day' => null,
            'fetched' => 0,
            'queued' => 0,
            'registered' => 0,
            'notified' => 0,
            'remaining_days' => 0,
            'remaining_commits' => 0,
        ];

        $lock = Cache::lock(self::LOCK_KEY, 180);

        try {
            if (! $lock->get()) {
                return $this->combineResults($emptySource, $emptyGithub);
            }
        } catch (Throwable $exception) {
            Log::warning('Backup missed lock failed: '.$exception->getMessage());
        }

        try {
            $clickup = $this->runClickUp();
            $github = $this->runGitHub();

            return $this->combineResults($clickup, $github);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param  array{
     *     day: ?string,
     *     fetched: int,
     *     queued: int,
     *     registered: int,
     *     notified: int,
     *     remaining_days: int,
     *     remaining_tasks: int
     * }  $clickup
     * @param  array{
     *     day: ?string,
     *     fetched: int,
     *     queued: int,
     *     registered: int,
     *     notified: int,
     *     remaining_days: int,
     *     remaining_commits: int
     * }  $github
     * @return array{
     *     day: ?string,
     *     fetched: int,
     *     queued: int,
     *     registered: int,
     *     notified: int,
     *     remaining_days: int,
     *     remaining_tasks: int,
     *     clickup: array{
     *         day: ?string,
     *         fetched: int,
     *         queued: int,
     *         registered: int,
     *         notified: int,
     *         remaining_days: int,
     *         remaining_tasks: int
     *     },
     *     github: array{
     *         day: ?string,
     *         fetched: int,
     *         queued: int,
     *         registered: int,
     *         notified: int,
     *         remaining_days: int,
     *         remaining_commits: int
     *     }
     * }
     */
    private function combineResults(array $clickup, array $github): array
    {
        return [
            'day' => $clickup['day'] ?? $github['day'],
            'fetched' => $clickup['fetched'] + $github['fetched'],
            'queued' => $clickup['queued'] + $github['queued'],
            'registered' => $clickup['registered'] + $github['registered'],
            'notified' => $clickup['notified'] + $github['notified'],
            'remaining_days' => $clickup['remaining_days'] + $github['remaining_days'],
            'remaining_tasks' => $clickup['remaining_tasks'] + $github['remaining_commits'],
            'clickup' => $clickup,
            'github' => $github,
        ];
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
    private function runClickUp(): array
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
            Log::warning('Backup missed ClickUp skipped: '.$exception->getMessage());

            return $empty;
        }

        $this->ensureDayQueue(
            self::DAY_QUEUE_KEY,
            self::TASK_QUEUE_KEY,
            self::LAST_SEED_KEY
        );

        $day = null;
        $fetched = 0;
        $queued = 0;
        $taskQueue = $this->taskQueue();
        $maxDayFetches = max(1, (int) config('clickup.backup_lookback_days', 7));

        for ($fetch = 0; $fetch < $maxDayFetches && $taskQueue === []; $fetch++) {
            $dayQueue = $this->stringQueue(self::DAY_QUEUE_KEY);
            $nextDay = array_shift($dayQueue);
            $this->putStringQueue(self::DAY_QUEUE_KEY, $dayQueue);

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

            if ($taskId === '' || TaskScore::query()->where('task_id', $taskId)->exists()) {
                continue;
            }

            $result = $this->registerAndNotifyTask($taskId, $itemDay);

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
            'remaining_days' => count($this->stringQueue(self::DAY_QUEUE_KEY)),
            'remaining_tasks' => count($taskQueue),
        ];
    }

    /**
     * @return array{
     *     day: ?string,
     *     fetched: int,
     *     queued: int,
     *     registered: int,
     *     notified: int,
     *     remaining_days: int,
     *     remaining_commits: int
     * }
     */
    private function runGitHub(): array
    {
        $empty = [
            'day' => null,
            'fetched' => 0,
            'queued' => 0,
            'registered' => 0,
            'notified' => 0,
            'remaining_days' => 0,
            'remaining_commits' => 0,
        ];

        try {
            if ($this->gitHubClient->repos() === []) {
                return $empty;
            }

            $this->gitHubClient->requireToken();
        } catch (\RuntimeException $exception) {
            Log::warning('Backup missed GitHub skipped: '.$exception->getMessage());

            return $empty;
        }

        $this->ensureDayQueue(
            self::GITHUB_DAY_QUEUE_KEY,
            self::GITHUB_COMMIT_QUEUE_KEY,
            self::GITHUB_LAST_SEED_KEY
        );

        $day = null;
        $fetched = 0;
        $queued = 0;
        $commitQueue = $this->commitQueue();
        $maxDayFetches = max(1, (int) config('clickup.backup_lookback_days', 7));

        for ($fetch = 0; $fetch < $maxDayFetches && $commitQueue === []; $fetch++) {
            $dayQueue = $this->stringQueue(self::GITHUB_DAY_QUEUE_KEY);
            $nextDay = array_shift($dayQueue);
            $this->putStringQueue(self::GITHUB_DAY_QUEUE_KEY, $dayQueue);

            if ($nextDay === null) {
                break;
            }

            $day = $nextDay;
            [$dayFetched, $dayQueued, $commitQueue] = $this->enqueueMissingCommitsForDay($day);
            $fetched += $dayFetched;
            $queued += $dayQueued;
            $this->putCommitQueue($commitQueue);
        }

        $batchSize = max(1, (int) config('clickup.backup_tasks_per_run', 3));
        $registered = 0;
        $notified = 0;

        for ($i = 0; $i < $batchSize && $commitQueue !== []; $i++) {
            $item = array_shift($commitQueue);

            if (! is_array($item)) {
                continue;
            }

            $sha = (string) ($item['sha'] ?? '');
            $repo = (string) ($item['repo'] ?? '');
            $itemDay = (string) ($item['day'] ?? '');

            if ($sha === '' || $repo === '') {
                continue;
            }

            if (CommitScore::query()->where('sha', $sha)->where('repo', $repo)->exists()) {
                continue;
            }

            $result = $this->registerAndNotifyCommit($item, $itemDay);

            if ($result['registered']) {
                $registered++;
            }

            if ($result['notified']) {
                $notified++;
            }
        }

        $this->putCommitQueue($commitQueue);

        return [
            'day' => $day,
            'fetched' => $fetched,
            'queued' => $queued,
            'registered' => $registered,
            'notified' => $notified,
            'remaining_days' => count($this->stringQueue(self::GITHUB_DAY_QUEUE_KEY)),
            'remaining_commits' => count($commitQueue),
        ];
    }

    private function ensureDayQueue(string $dayKey, string $itemKey, string $seedKey): void
    {
        if ($this->stringQueue($dayKey) !== []) {
            return;
        }

        $items = Cache::get($itemKey, []);

        if (is_array($items) && $items !== []) {
            return;
        }

        $timezone = $this->timezone();
        $today = Carbon::now($timezone)->toDateString();
        $lastSeed = Cache::get($seedKey);

        if (is_string($lastSeed) && $lastSeed === $today) {
            return;
        }

        $lookback = max(1, (int) config('clickup.backup_lookback_days', 7));
        $days = [];

        for ($offset = $lookback; $offset >= 1; $offset--) {
            $days[] = Carbon::now($timezone)->subDays($offset)->toDateString();
        }

        $this->putStringQueue($dayKey, $days);
        Cache::put($seedKey, $today, now()->addDays(14));
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
     * @return array{0: int, 1: int, 2: list<array{sha: string, repo: string, day: string, message: string, login: ?string, email: ?string, name: ?string, committed_at: string}>}
     */
    private function enqueueMissingCommitsForDay(string $day): array
    {
        $timezone = $this->timezone();
        $from = Carbon::parse($day, $timezone)->startOfDay()->utc();
        $to = Carbon::parse($day, $timezone)->endOfDay()->utc();

        $commits = $this->gitHubClient->recentCommits($from, $to);
        $queue = [];
        $seen = [];

        foreach ($commits as $commit) {
            $sha = (string) ($commit['sha'] ?? '');
            $repo = (string) ($commit['repo'] ?? '');

            if ($sha === '' || $repo === '') {
                continue;
            }

            $key = mb_strtolower($repo).'|'.$sha;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if (CommitScore::query()->where('sha', $sha)->where('repo', $repo)->exists()) {
                continue;
            }

            $queue[] = [
                'sha' => $sha,
                'repo' => $repo,
                'day' => $day,
                'message' => (string) ($commit['message'] ?? ''),
                'login' => $commit['login'] ?? null,
                'email' => $commit['email'] ?? null,
                'name' => $commit['name'] ?? null,
                'committed_at' => (string) ($commit['committed_at'] ?? ''),
            ];
        }

        return [count($commits), count($queue), $queue];
    }

    /**
     * @return array{registered: bool, notified: bool}
     */
    private function registerAndNotifyTask(string $taskId, string $day): array
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
     * @param  array{sha: string, repo: string, day?: string, message?: string, login?: ?string, email?: ?string, name?: ?string, committed_at?: string}  $item
     * @return array{registered: bool, notified: bool}
     */
    private function registerAndNotifyCommit(array $item, string $day): array
    {
        $sha = (string) ($item['sha'] ?? '');
        $repo = (string) ($item['repo'] ?? '');

        try {
            $score = $this->commitScoreSyncService->scoreCommitIfNeeded($item);
        } catch (Throwable $exception) {
            Log::warning("Backup missed commit score failed for {$repo}@{$sha}: ".$exception->getMessage());

            return ['registered' => false, 'notified' => false];
        }

        if ($score === null) {
            return ['registered' => false, 'notified' => false];
        }

        $cacheKey = 'github_backup_missed:notified:'.mb_strtolower($repo).'|'.$sha;

        if (! Cache::add($cacheKey, true, now()->addDays(30))) {
            return ['registered' => true, 'notified' => false];
        }

        $identity = $this->commitIdentity(
            isset($item['login']) ? (string) $item['login'] : null,
            isset($item['email']) ? (string) $item['email'] : null,
            isset($item['name']) ? (string) $item['name'] : null,
        );
        $displayName = $this->userNameResolver->resolve($identity['email'], $identity['username']);
        $message = trim((string) ($item['message'] ?? $score->message ?? $sha));
        $translated = $this->commitMessageTranslator->toPersian($message);
        $repoName = str_contains($repo, '/') ? (string) substr($repo, (int) strrpos($repo, '/') + 1) : $repo;
        $dateLabel = $day !== '' ? $day : $this->resolveCommitDate($item);
        $scoreLabel = (int) $score->score;

        $lines = [
            '⚠️ کامیت قدیمی ثبت نشده',
            $displayName,
            $translated,
            '#'.$repoName,
            'تاریخ: '.$dateLabel,
        ];

        if ($scoreLabel > 0) {
            $lines[] = 'امتیاز: '.$scoreLabel;
        }

        try {
            $this->telegramNotifier->send(implode("\n", $lines));
        } catch (Throwable $exception) {
            Log::warning("Backup missed commit telegram failed for {$repo}@{$sha}: ".$exception->getMessage());

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
     * @return array{email: ?string, username: ?string}
     */
    private function commitIdentity(?string $login, ?string $email, ?string $name): array
    {
        $login = $login !== null && $login !== '' ? mb_strtolower(ltrim(trim($login), '@')) : '';
        $email = $email !== null && $email !== '' ? mb_strtolower(trim($email)) : '';
        $name = $name !== null ? trim($name) : '';

        $mappedEmail = null;

        if ($login !== '') {
            foreach (config('github.user_logins', []) as $mapped => $mappedLogin) {
                if (mb_strtolower(ltrim(trim((string) $mappedLogin), '@')) === $login) {
                    $mappedEmail = mb_strtolower((string) $mapped);
                    break;
                }
            }
        }

        return [
            'email' => $mappedEmail ?? ($email !== '' ? $email : null),
            'username' => $login !== '' ? $login : ($name !== '' ? $name : null),
        ];
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
     * @param  array{committed_at?: string}  $item
     */
    private function resolveCommitDate(array $item): string
    {
        $raw = (string) ($item['committed_at'] ?? '');

        if ($raw === '') {
            return Carbon::now($this->timezone())->toDateString();
        }

        return Carbon::parse($raw, 'UTC')->timezone($this->timezone())->toDateString();
    }

    /**
     * @return list<string>
     */
    private function stringQueue(string $key): array
    {
        $value = Cache::get($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($day): bool => is_string($day) && $day !== ''));
    }

    /**
     * @param  list<string>  $days
     */
    private function putStringQueue(string $key, array $days): void
    {
        Cache::put($key, array_values($days), now()->addDays(14));
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

    /**
     * @return list<array{sha: string, repo: string, day: string, message: string, login: ?string, email: ?string, name: ?string, committed_at: string}>
     */
    private function commitQueue(): array
    {
        $value = Cache::get(self::GITHUB_COMMIT_QUEUE_KEY, []);

        if (! is_array($value)) {
            return [];
        }

        $queue = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sha = (string) ($item['sha'] ?? '');
            $repo = (string) ($item['repo'] ?? '');

            if ($sha === '' || $repo === '') {
                continue;
            }

            $queue[] = [
                'sha' => $sha,
                'repo' => $repo,
                'day' => (string) ($item['day'] ?? ''),
                'message' => (string) ($item['message'] ?? ''),
                'login' => isset($item['login']) ? (string) $item['login'] : null,
                'email' => isset($item['email']) ? (string) $item['email'] : null,
                'name' => isset($item['name']) ? (string) $item['name'] : null,
                'committed_at' => (string) ($item['committed_at'] ?? ''),
            ];
        }

        return $queue;
    }

    /**
     * @param  list<array{sha: string, repo: string, day: string, message: string, login: ?string, email: ?string, name: ?string, committed_at: string}>  $queue
     */
    private function putCommitQueue(array $queue): void
    {
        Cache::put(self::GITHUB_COMMIT_QUEUE_KEY, array_values($queue), now()->addDays(14));
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Tehran');
    }
}
