<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TaskScore;
use App\Models\WorkStart;
use App\Support\JalaliCalendar;
use App\Support\TaskCallbackRef;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;

class PersonalMorningDashboardService
{
    public function __construct(
        private readonly ClickUpTaskListService $taskListService,
        private readonly ScoreIdentityResolver $scoreIdentityResolver,
        private readonly StreakCompetitionService $streakCompetitionService,
        private readonly TelegramNotifier $telegramNotifier,
        private readonly UserNameResolver $userNameResolver,
    ) {
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{sent: bool, blocked: bool, toast: ?string}
     */
    public function sendFor(array $from, WorkStart $workStart): array
    {
        $telegramUserId = (int) ($from['id'] ?? $workStart->telegram_user_id);

        if ($telegramUserId <= 0) {
            return ['sent' => false, 'blocked' => false, 'toast' => null];
        }

        $view = $this->build($from, $workStart);

        try {
            $this->telegramNotifier->replyWithInlineKeyboard(
                $telegramUserId,
                $view['text'],
                $view['keyboard']
            );

            return ['sent' => true, 'blocked' => false, 'toast' => null];
        } catch (RequestException $exception) {
            $body = (string) ($exception->response?->body() ?? '');

            if (str_contains($body, 'bot was blocked') || str_contains($body, "chat not found") || str_contains($body, 'Forbidden')) {
                return [
                    'sent' => false,
                    'blocked' => true,
                    'toast' => 'داشبورد صبح آماده است؛ اول در چت خصوصی به ربات /start بزن.',
                ];
            }

            report($exception);

            return ['sent' => false, 'blocked' => false, 'toast' => null];
        } catch (\Throwable $exception) {
            report($exception);

            return ['sent' => false, 'blocked' => false, 'toast' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function build(array $from, WorkStart $workStart): array
    {
        $timezone = JalaliCalendar::timezone();
        $username = (string) ($from['username'] ?? $workStart->telegram_username ?? '');
        $email = $workStart->email;
        $display = trim((string) ($workStart->display_name ?: $this->userNameResolver->resolve($email, $username)));
        $clock = $workStart->started_at->timezone($timezone)->format('H:i');
        $streak = $this->streakCompetitionService->streakForTelegramUser((int) $workStart->telegram_user_id);
        $yesterdayScore = $this->yesterdayScore($email, $username);
        $todayTasks = $this->safeTasks(fn () => $this->taskListService->dueTodayTasks());
        $overdueTasks = $this->safeTasks(fn () => $this->taskListService->overdueTasks());
        $myToday = $this->filterForUser($todayTasks, $email, $username);
        $myOverdue = $this->filterForUser($overdueTasks, $email, $username);
        $priority = $this->pickPriority(array_merge($myOverdue, $myToday));

        $lines = [
            "صبح بخیر {$display} ☀️",
            "شروع کار: {$clock}",
            'استریک: '.($streak > 0 ? $streak.' روز متوالی' : 'از امروز شروع شد'),
            'امتیاز دیروز: '.number_format($yesterdayScore),
            '',
            'امروز ('.count($myToday).'):',
        ];

        if ($myToday === []) {
            $lines[] = '— تسکی با ددلاین امروز نداری.';
        } else {
            foreach (array_slice($myToday, 0, 5) as $task) {
                $lines[] = '• '.$this->taskLabel($task);
            }

            if (count($myToday) > 5) {
                $lines[] = '… و '.(count($myToday) - 5).' مورد دیگر';
            }
        }

        $lines[] = '';
        $lines[] = 'عقب‌افتاده ('.count($myOverdue).'):';

        if ($myOverdue === []) {
            $lines[] = '— عالی، عقب‌افتاده‌ای نداری.';
        } else {
            foreach (array_slice($myOverdue, 0, 5) as $task) {
                $lines[] = '• '.$this->taskLabel($task);
            }
        }

        $keyboard = [
            [
                ['text' => 'تسک‌های من', 'callback_data' => 'tm:list:my:0'],
                ['text' => 'امتیاز امروز', 'callback_data' => 'tm:scores:day'],
            ],
            [
                ['text' => 'استریک‌ها', 'callback_data' => 'comp:home'],
                ['text' => 'منو', 'callback_data' => 'tm:home'],
            ],
        ];

        if ($priority !== null) {
            $priorityName = trim((string) ($priority['name'] ?? 'اولویت'));
            $lines[] = '';
            $lines[] = 'پیشنهاد اولویت: '.$priorityName;
            $ref = TaskCallbackRef::remember((string) $priority['id']);
            array_unshift($keyboard, [[
                'text' => 'شروع اولویت',
                'callback_data' => 'tm:task:'.$ref,
            ]]);
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => $keyboard,
        ];
    }

    private function yesterdayScore(?string $email, string $username): int
    {
        $timezone = JalaliCalendar::timezone();
        $from = Carbon::now($timezone)->subDay()->startOfDay()->utc();
        $to = Carbon::now($timezone)->subDay()->endOfDay()->utc();
        $month = JalaliCalendar::monthKey(Carbon::now($timezone)->subDay());
        $identity = $this->scoreIdentityResolver->resolve(null, $email, $username);
        $total = 0;

        if ($identity['key'] === '') {
            return 0;
        }

        foreach (CommitScore::query()
            ->where('month', $month)
            ->where('score', '>', 0)
            ->whereBetween('committed_at', [$from, $to])
            ->get() as $row) {
            $rowIdentity = $this->scoreIdentityResolver->resolve($row->login, $row->email, $row->name);

            if ($rowIdentity['key'] === $identity['key']) {
                $total += (int) $row->score;
            }
        }

        foreach (TaskScore::query()
            ->where('month', $month)
            ->where('is_code', false)
            ->where('score', '>', 0)
            ->whereBetween('completed_at', [$from, $to])
            ->get() as $row) {
            $rowIdentity = $this->scoreIdentityResolver->resolve(null, $row->email, $row->username ?? $row->name);

            if ($rowIdentity['key'] === $identity['key']) {
                $total += (int) $row->score;
            }
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private function filterForUser(array $tasks, ?string $email, string $username): array
    {
        $emailNeedle = $email !== null ? mb_strtolower($email) : '';
        $usernameNeedle = mb_strtolower(ltrim(trim($username), '@'));

        return array_values(array_filter($tasks, function (array $task) use ($emailNeedle, $usernameNeedle): bool {
            $assignees = $task['assignees'] ?? [];

            if (! is_array($assignees)) {
                return false;
            }

            foreach ($assignees as $assignee) {
                if (! is_array($assignee)) {
                    continue;
                }

                $assigneeEmail = mb_strtolower((string) ($assignee['email'] ?? ''));
                $assigneeUsername = mb_strtolower(ltrim((string) ($assignee['username'] ?? ''), '@'));

                if ($emailNeedle !== '' && $assigneeEmail === $emailNeedle) {
                    return true;
                }

                if ($usernameNeedle !== '' && $assigneeUsername === $usernameNeedle) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>|null
     */
    private function pickPriority(array $tasks): ?array
    {
        if ($tasks === []) {
            return null;
        }

        usort($tasks, static function (array $a, array $b): int {
            $pa = is_array($a['priority'] ?? null) ? (int) ($a['priority']['orderindex'] ?? $a['priority']['id'] ?? 99) : 99;
            $pb = is_array($b['priority'] ?? null) ? (int) ($b['priority']['orderindex'] ?? $b['priority']['id'] ?? 99) : 99;

            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            $da = is_numeric($a['due_date'] ?? null) ? (int) $a['due_date'] : PHP_INT_MAX;
            $db = is_numeric($b['due_date'] ?? null) ? (int) $b['due_date'] : PHP_INT_MAX;

            return $da <=> $db;
        });

        $first = $tasks[0];

        return isset($first['id']) && (string) $first['id'] !== '' ? $first : null;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function taskLabel(array $task): string
    {
        $name = trim((string) ($task['name'] ?? 'تسک'));
        $status = is_array($task['status'] ?? null) ? trim((string) ($task['status']['status'] ?? '')) : '';

        return $status !== '' ? "{$name} [{$status}]" : $name;
    }

    /**
     * @param  callable(): list<array<string, mixed>>  $callback
     * @return list<array<string, mixed>>
     */
    private function safeTasks(callable $callback): array
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
