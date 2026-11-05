<?php

namespace App\Services;

use App\Jobs\FinishTelegramLeaderboard;
use App\Models\TeamMember;
use App\Models\TeamTask;
use Illuminate\Support\Facades\Cache;

class TelegramBotHandler
{
    private const UPDATE_DEDUP_TTL_SECONDS = 600;

    public function __construct(
        private readonly TeamTaskService $teamTasks,
        private readonly TeamIdentityService $identity,
        private readonly TaskMenuService $taskMenu,
        private readonly HistoryService $history,
        private readonly ScoreChartService $scoreChart,
        private readonly GitHubLeaderboardService $gitHubLeaderboard,
        private readonly ScoreReportService $scoreReportService,
        private readonly NotifyMorningCheckIn $morningCheckIn,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        if (! $this->claimUpdate($update)) {
            return;
        }

        $callback = $update['callback_query'] ?? null;

        if (is_array($callback)) {
            $this->handleCallbackQuery($callback);

            return;
        }

        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '') {
            return;
        }

        // Team group is notification-only: commands there are ignored silently.
        // All member actions (tasks, menu, history, scores) happen in a private chat with the bot.
        if (($message['chat']['type'] ?? '') !== 'private') {
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
            $this->telegramNotifier->reply($chatId, 'اجازه دسترسی به این ربات را نداری.');

            return;
        }

        $threadId = $message['message_thread_id'] ?? null;
        $threadId = is_numeric($threadId) ? $threadId : null;
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $fromUsername = (string) ($from['username'] ?? '');

        $isLeaderboard = in_array($command, ['topday', 'topweek', 'topmonth'], true);

        if ($isLeaderboard) {
            $progressId = $this->telegramNotifier->reply($chatId, 'در حال محاسبه امتیاز…', $threadId);

            // ACK webhook first; Telegram retries if this request stays open too long.
            FinishTelegramLeaderboard::dispatchAfterResponse(
                $command,
                $fromUsername,
                $chatId,
                $progressId,
                $threadId,
            );

            return;
        }

        if (in_array($command, ['menu', 'mytasks'], true)) {
            $this->sendTaskMenu($chatId, $threadId, $from);

            return;
        }

        try {
            $this->recordMember($from);
            $reply = $this->responseFor($command, $fromUsername, $text, $from);
        } catch (\Throwable $exception) {
            report($exception);
            $reply = 'خطا در اجرای دستور: '.$this->publicError($exception);
        }

        $this->telegramNotifier->reply($chatId, $reply, $threadId);
    }

    public function completeLeaderboard(
        string $command,
        string $fromUsername,
        string|int $chatId,
        ?int $progressId,
        string|int|null $threadId,
    ): void {
        @set_time_limit(300);
        ignore_user_abort(true);

        try {
            $reply = $this->responseFor($command, $fromUsername, '/'.$command);
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
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function handleCallbackQuery(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $message = $callback['message'] ?? null;
        $from = $callback['from'] ?? null;

        if ($callbackId === '' || ! is_array($message) || ! is_array($from)) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $chatType = (string) ($message['chat']['type'] ?? '');
        $allowedChatId = (string) config('telegram.chat_id', '');

        if ($chatId === null || $chatId === '' || ! is_numeric($messageId)) {
            return;
        }

        // Task buttons live in private chat only (personal, per member).
        if (str_starts_with($data, TaskMenuService::CALLBACK_PREFIX)) {
            if ($chatType !== 'private') {
                $this->telegramNotifier->answerCallbackQuery($callbackId, 'مدیریت تسک فقط داخل پیوی ربات انجام می‌شود.', true);

                return;
            }

            $this->handleTaskCallback($callbackId, $chatId, (int) $messageId, $from, $data);

            return;
        }

        // Morning check-in button lives in the team group only.
        if ($allowedChatId === '' || (string) $chatId !== $allowedChatId) {
            $this->telegramNotifier->answerCallbackQuery($callbackId, 'این دکمه فقط در گروه تنظیم‌شده کار می‌کند.', true);

            return;
        }

        if ($data !== NotifyMorningCheckIn::CALLBACK_DATA) {
            $this->telegramNotifier->answerCallbackQuery($callbackId);

            return;
        }

        try {
            $result = $this->morningCheckIn->recordStart($from);
            $this->telegramNotifier->answerCallbackQuery($callbackId, $result['toast']);

            if ($result['created']) {
                $this->morningCheckIn->refreshMessage($chatId, (int) $messageId);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->telegramNotifier->answerCallbackQuery(
                $callbackId,
                'خطا در ثبت: '.$this->publicError($exception),
                true
            );
        }
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function sendTaskMenu(string|int $chatId, string|int|null $threadId, array $from): void
    {
        $menu = $this->taskMenu->menuFor($from);
        $this->telegramNotifier->sendWithInlineKeyboard($menu['text'], $menu['keyboard'], $threadId);
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function handleTaskCallback(string $callbackId, mixed $chatId, int $messageId, array $from, string $data): void
    {
        $result = $this->taskMenu->handleAction($from, $data);
        $this->telegramNotifier->answerCallbackQuery($callbackId, $result['toast'], ! $result['changed']);

        // Refresh the same menu message so the member sees the updated list.
        $menu = $this->taskMenu->menuFor($from);
        $this->telegramNotifier->edit($chatId, $messageId, $menu['text'], ['inline_keyboard' => $menu['keyboard']]);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function claimUpdate(array $update): bool
    {
        $updateId = $update['update_id'] ?? null;

        if (! is_numeric($updateId)) {
            return true;
        }

        return Cache::add(
            'telegram:update:'.(int) $updateId,
            true,
            now()->addSeconds(self::UPDATE_DEDUP_TTL_SECONDS)
        );
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

    private function historyPeriod(string $rawText): string
    {
        if (preg_match('/^\/history(?:@\w+)?\s+(day|week|month|امروز|هفته|ماه)\b/ui', $rawText, $matches) === 1) {
            return match (mb_strtolower($matches[1])) {
                'week', 'هفته' => 'week',
                'month', 'ماه' => 'month',
                default => 'day',
            };
        }

        return 'day';
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function taskMenuReply(array $from): string
    {
        return $this->taskMenu->menuFor($from)['text'];
    }

    private function responseFor(string $command, string $fromUsername, string $rawText, array $from = []): string
    {
        $isAdmin = $this->isAdmin($from);

        if ($command === 'assign') {
            return $this->handleAssign($rawText, $from, $isAdmin);
        }

        return match ($command) {
            'start', 'help' => $this->helpText(),
            'tasks' => $this->teamTasks->listFor($from, $isAdmin),
            'tomorrow' => $this->teamTasks->listFor($from, $isAdmin, 'open'),
            'today' => $this->teamTasks->listFor($from, $isAdmin, 'today'),
            'overdue' => $this->teamTasks->listFor($from, $isAdmin, 'overdue'),
            'me' => $this->teamTasks->listFor($from, false),
            'newtask' => $this->handleNewTask($rawText, $from),
            'status' => $this->handleTaskStatus($rawText, $from, $isAdmin),
            'menu', 'mytasks' => $this->taskMenuReply($from),
            'history' => $this->history->format($this->historyPeriod($rawText)),
            'historyweek' => $this->history->format('week'),
            'historymonth' => $this->history->format('month'),
            'chart' => $this->scoreChart->formatMonth(),
            'team' => $isAdmin ? $this->teamTasks->listFor($from, true, 'all') : 'این دستور فقط برای ادمین اصلی است.',
            'myscore' => $this->scoreReportService->formatMyScore(
                (int) ($from['id'] ?? 0),
                $this->myscorePeriod($rawText)
            ),
            'highlight' => $this->scoreReportService->formatDailyHighlight(),
            'topday' => $this->gitHubLeaderboard->formatTopToday(),
            'topweek' => $this->gitHubLeaderboard->formatTopWeek(),
            'topmonth' => $this->gitHubLeaderboard->formatTopMonth(),
            default => "دستور ناشناخته.\n".$this->helpText(),
        };
    }

    private function myscorePeriod(string $rawText): string
    {
        if (preg_match('/^\/myscore(?:@\w+)?\s+(week|month|today|هفته|ماه|امروز)\b/ui', $rawText, $matches) === 1) {
            return match (mb_strtolower($matches[1])) {
                'week', 'هفته' => 'week',
                'month', 'ماه' => 'month',
                default => 'today',
            };
        }

        return 'today';
    }




    private function helpText(): string
    {
        return implode("\n", [
            'مدیر تسک تیم 👋',
            '',
            'دستورها:',
            '/tasks — تسک‌های باز (ادمین: کل تیم)',
            '/today — تسک‌های امروز',
            '/tomorrow — تسک‌های امروز، فردا و عقب‌افتاده',
            '/overdue — فقط تسک‌های عقب‌افتاده',
            '/me — فقط تسک‌های خودت',
            '/newtask عنوان | توضیحات اختیاری | مهلت YYYY-MM-DD — ساخت تسک برای خودت',
            '/status ID doing|done|todo — تغییر وضعیت تسک خودت',
            '/myscore — امتیاز خودت (امروز / هفته / ماه)',
            '/highlight — خفن‌ترین کار امروز',
            '/topday — برترین‌های امروز',
            '/topweek — برترین‌های این هفته',
            '/topmonth — برترین‌های این ماه',
            '/chart — نمودار امتیاز ماه شمسی',
            '/help — همین راهنما',
            '',
            'ادمین:',
            '/team — همه تسک‌های تیم',
            '/assign ID @username — واگذاری تسک به عضوی که قبلاً /start زده',
            '/fixscore TASK_ID 1500 دلیل',
            '/uncode TASK_ID [امتیاز] [دلیل]',
            '/markcode TASK_ID',
            '',
            'بدون اسلش هم می‌تونی بنویسی: لیست، امروز، فردا، عقب افتاده، امتیاز من، خفن امروز، برترین روز',
        ]);
    }

    private function parseCommand(string $text): ?string
    {
        if (preg_match('/^\/([a-zA-Z_]+)(?:@\w+)?(?:\s|$)/u', $text, $matches) === 1) {
            return match (strtolower($matches[1])) {
                'top_day', 'daily' => 'topday',
                'top_week', 'weekly' => 'topweek',
                'top_month', 'monthly' => 'topmonth',
                'my_score', 'score' => 'myscore',
                'new_task', 'addtask' => 'newtask',
                'daily_highlight', 'bestday' => 'highlight',
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
            'امتیاز من', 'امتیازم' => 'myscore',
            'خفن امروز', 'هایلایت' => 'highlight',
            'برترین روز', 'برترین های روز' => 'topday',
            'برترین هفته', 'برترین های هفته' => 'topweek',
            'برترین ماه', 'برترین های ماه' => 'topmonth',
            'راهنما', 'کمک' => 'help',
            'نمودار', 'نمودار ماه' => 'chart',
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

        $telegramId = (string) ($message['from']['id'] ?? '');

        return $telegramId !== '' && $this->identity->isMember((int) $telegramId);
    }

    /** @param array<string, mixed> $from */
    private function isAdmin(array $from): bool
    {
        $telegramId = (int) ($from['id'] ?? 0);

        return $telegramId > 0 && $this->identity->isAdmin($telegramId);
    }

    /** @param array<string, mixed> $from */
    private function handleNewTask(string $rawText, array $from): string
    {
        if (preg_match('/^\/newtask(?:@\w+)?\s+(.+)$/su', $rawText, $matches) !== 1) {
            return "فرمت:\n/newtask عنوان | توضیحات اختیاری | مهلت YYYY-MM-DD";
        }

        $parts = array_map('trim', explode('|', $matches[1], 3));

        try {
            $task = $this->teamTasks->create($from, $parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? null);

            return "تسک #{$task->id} ساخته شد: {$task->title}";
        } catch (\Throwable $exception) {
            return 'ساخت تسک ناموفق بود: '.$this->publicError($exception);
        }
    }

    /** @param array<string, mixed> $from */
    private function handleTaskStatus(string $rawText, array $from, bool $admin): string
    {
        if (preg_match('/^\/status(?:@\w+)?\s+(\d+)\s+(todo|doing|done)$/iu', $rawText, $matches) !== 1) {
            return "فرمت:\n/status ID todo|doing|done";
        }

        try {
            return $this->teamTasks->setStatus($from, $admin, (int) $matches[1], match (strtolower($matches[2])) {
                'doing' => 'in_progress',
                default => strtolower($matches[2]),
            });
        } catch (\Throwable $exception) {
            return 'تغییر وضعیت ناموفق بود: '.$this->publicError($exception);
        }
    }

    /** @param array<string, mixed> $from */
    private function handleAssign(string $rawText, array $from, bool $admin): string
    {
        if (! $admin) {
            return 'این دستور فقط برای ادمین اصلی است.';
        }

        if (preg_match('/^\/assign(?:@\w+)?\s+(\d+)\s+@?(\w+)$/u', $rawText, $matches) !== 1) {
            return "فرمت:\n/assign ID @username";
        }

        $member = TeamMember::query()
            ->where('is_active', true)
            ->whereNotNull('telegram_user_id')
            ->whereRaw('LOWER(telegram_username) = ?', [mb_strtolower($matches[2])])
            ->first();

        if ($member === null) {
            return 'این یوزرنیم در لیست اعضای فعال تیم ثبت نشده یا هنوز آیدی تلگرامش مشخص نیست.';
        }

        return $this->teamTasks->assign((int) $matches[1], [
            'id' => $member->telegram_user_id,
            'username' => $member->telegram_username,
            'first_name' => $member->display_name,
        ]);
    }

    /** @param array<string, mixed> $from */
    private function recordMember(array $from): void
    {
        $telegramId = (int) ($from['id'] ?? 0);

        if ($telegramId <= 0) {
            return;
        }

        $displayName = trim(implode(' ', array_filter([
            (string) ($from['first_name'] ?? ''),
            (string) ($from['last_name'] ?? ''),
        ])));

        // Only refresh metadata for already-registered members. Unknown senders are never auto-enrolled.
        TeamMember::query()
            ->where('telegram_user_id', $telegramId)
            ->update([
                'telegram_username' => isset($from['username']) ? (string) $from['username'] : null,
                'last_seen_at' => now(),
            ]);
    }
}
