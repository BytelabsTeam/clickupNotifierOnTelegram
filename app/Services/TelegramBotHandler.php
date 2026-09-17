<?php

namespace App\Services;

class TelegramBotHandler
{
    public function __construct(
        private readonly ClickUpTaskListService $taskListService,
        private readonly GitHubLeaderboardService $gitHubLeaderboard,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '') {
            return;
        }

        $command = $this->parseCommand($text);

        if ($command === null) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;

        if ($chatId === null || $chatId === '') {
            return;
        }

        if (! $this->isAuthorized($message)) {
            if (($message['chat']['type'] ?? '') === 'private') {
                $this->telegramNotifier->reply($chatId, 'اجازه دسترسی به این ربات را نداری.');
            }

            return;
        }

        $threadId = $message['message_thread_id'] ?? null;
        $threadId = is_numeric($threadId) ? $threadId : null;
        $fromUsername = (string) ($message['from']['username'] ?? '');
        $isLeaderboard = in_array($command, ['topday', 'topweek', 'topmonth'], true);

        if ($isLeaderboard) {
            $progressId = $this->telegramNotifier->reply($chatId, 'در حال محاسبه امتیاز…', $threadId);
            $this->finishLeaderboard($command, $fromUsername, $chatId, $progressId, $threadId);

            return;
        }

        try {
            $reply = $this->responseFor($command, $fromUsername);
        } catch (\Throwable $exception) {
            report($exception);
            $reply = 'خطا در اجرای دستور: '.$this->publicError($exception);
        }

        $this->telegramNotifier->reply($chatId, $reply, $threadId);
    }

    private function finishLeaderboard(
        string $command,
        string $fromUsername,
        string|int $chatId,
        ?int $progressId,
        string|int|null $threadId,
    ): void {
        $work = function () use ($command, $fromUsername, $chatId, $progressId, $threadId): void {
            @set_time_limit(180);
            ignore_user_abort(true);

            try {
                $reply = $this->responseFor($command, $fromUsername);
            } catch (\Throwable $exception) {
                report($exception);
                $reply = 'خطا در محاسبه امتیاز: '.$this->publicError($exception);
            }

            try {
                if ($progressId !== null) {
                    $this->telegramNotifier->edit($chatId, $progressId, $reply);

                    return;
                }

                $this->telegramNotifier->reply($chatId, $reply, $threadId);
            } catch (\Throwable $exception) {
                report($exception);
                $this->telegramNotifier->reply($chatId, $reply, $threadId);
            }
        };

        $work();
    }

    private function publicError(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return 'کمی بعد دوباره امتحان کن.';
        }

        if (mb_strlen($message) > 220) {
            return mb_substr($message, 0, 220).'…';
        }

        return $message;
    }

    private function responseFor(string $command, string $fromUsername): string
    {
        return match ($command) {
            'start', 'help' => $this->helpText(),
            'tasks' => $this->taskListService->formatOpenTasks(),
            'tomorrow' => $this->taskListService->formatDueThroughTomorrow(),
            'today' => $this->taskListService->formatDueToday(),
            'overdue' => $this->taskListService->formatOverdue(),
            'me' => $this->taskListService->formatForTelegramUsername($fromUsername),
            'topday' => $this->gitHubLeaderboard->formatTopToday(),
            'topweek' => $this->gitHubLeaderboard->formatTopWeek(),
            'topmonth' => $this->gitHubLeaderboard->formatTopMonth(),
            default => "دستور ناشناخته.\n".$this->helpText(),
        };
    }

    private function helpText(): string
    {
        return implode("\n", [
            'ربات ClickUp 👋',
            '',
            'دستورها:',
            '/tasks — لیست تسک‌های باز',
            '/today — تسک‌های امروز',
            '/tomorrow — تسک‌های امروز، فردا و عقب‌افتاده',
            '/overdue — فقط تسک‌های عقب‌افتاده',
            '/me — فقط تسک‌های خودت',
            '/topday — برترین‌های امروز',
            '/topweek — برترین‌های این هفته',
            '/topmonth — برترین‌های این ماه',
            '/help — همین راهنما',
            '',
            'بدون اسلش هم می‌تونی بنویسی: لیست، امروز، فردا، عقب افتاده، برترین روز، برترین هفته، برترین ماه',
        ]);
    }

    private function parseCommand(string $text): ?string
    {
        if (preg_match('/^\/([a-zA-Z_]+)(?:@\w+)?(?:\s|$)/u', $text, $matches) === 1) {
            return match (strtolower($matches[1])) {
                'top_day', 'daily' => 'topday',
                'top_week', 'weekly' => 'topweek',
                'top_month', 'monthly' => 'topmonth',
                default => strtolower($matches[1]),
            };
        }

        $normalized = trim(str_replace('‌', ' ', $text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return match ($normalized) {
            'لیست', 'لیست تسک ها', 'تسک ها' => 'tasks',
            'فردا', 'تسک های فردا' => 'tomorrow',
            'امروز', 'تسک های امروز' => 'today',
            'عقب افتاده', 'عقبافتاده' => 'overdue',
            'تسک های من' => 'me',
            'برترین روز', 'برترین های روز' => 'topday',
            'برترین هفته', 'برترین های هفته' => 'topweek',
            'برترین ماه', 'برترین های ماه' => 'topmonth',
            'راهنما', 'کمک' => 'help',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isAuthorized(array $message): bool
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $allowedChatId = (string) config('telegram.chat_id', '');

        if ($allowedChatId !== '' && $chatId === $allowedChatId) {
            return true;
        }

        if (($message['chat']['type'] ?? '') !== 'private') {
            return false;
        }

        $username = mb_strtolower(ltrim((string) ($message['from']['username'] ?? ''), '@'));

        if ($username === '') {
            return false;
        }

        foreach (config('telegram.usernames', []) as $mapped) {
            if (mb_strtolower(ltrim(trim((string) $mapped), '@')) === $username) {
                return true;
            }
        }

        return false;
    }
}
