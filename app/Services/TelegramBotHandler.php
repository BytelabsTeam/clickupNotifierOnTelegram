<?php

namespace App\Services;

use App\Jobs\FinishSelfDeploy;
use App\Jobs\FinishTelegramLeaderboard;
use Illuminate\Support\Facades\Cache;

class TelegramBotHandler
{
    private const UPDATE_DEDUP_TTL_SECONDS = 600;

    public function __construct(
        private readonly AssignActionService $assignActionService,
        private readonly ClickUpTaskListService $taskListService,
        private readonly GitHubLeaderboardService $gitHubLeaderboard,
        private readonly NaturalLanguageTaskService $naturalLanguageTaskService,
        private readonly NotifyMorningCheckIn $morningCheckIn,
        private readonly PersonalMorningDashboardService $personalMorningDashboardService,
        private readonly PunchService $punchService,
        private readonly ScoreBreakdownService $scoreBreakdownService,
        private readonly SelfDeployService $selfDeployService,
        private readonly StreakCompetitionService $streakCompetitionService,
        private readonly TaskMenuService $taskMenuService,
        private readonly TaskQualityGateService $taskQualityGateService,
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

        $chatId = $message['chat']['id'] ?? null;

        if ($chatId === null || $chatId === '') {
            return;
        }

        $threadId = $message['message_thread_id'] ?? null;
        $threadId = is_numeric($threadId) ? $threadId : null;
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $fromUsername = (string) ($from['username'] ?? '');
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        $command = $text !== '' ? $this->parseCommand($text) : null;

        if ($command === 'deploy') {
            $this->handleDeployCommand($message, $from, $chatId);

            return;
        }

        if (! $this->isAuthorized($message)) {
            if (($message['chat']['type'] ?? '') === 'private') {
                $this->telegramNotifier->reply($chatId, 'اجازه دسترسی به این ربات را نداری.');
            }

            return;
        }

        if (isset($message['voice']) && is_array($message['voice'])) {
            $this->telegramNotifier->reply(
                $chatId,
                "ویس را هنوز به تسک تبدیل نمی‌کنم.\nمتن بفرست یا از /task استفاده کن.\nمثال: /task فردا ریفکتور لاگین با اولویت بالا",
                $threadId
            );

            return;
        }

        if ($text === '') {
            return;
        }

        if ($command === null && $this->taskMenuService->hasPendingCompose($from)) {
            $this->handleComposeMessage($from, $text, $chatId, $threadId);

            return;
        }

        if ($command === 'task' || ($command === null && $this->naturalLanguageTaskService->looksLikeCreateIntent($text))) {
            $this->handleNaturalLanguageTask($from, $text, $chatId, $threadId);

            return;
        }

        if ($command === null) {
            return;
        }

        if (in_array($command, ['punch', 'punchout', 'status', 'stat'], true)) {
            $this->handlePunchCommand($command, $from, $chatId, $threadId);

            return;
        }

        if (in_array($command, ['menu', 'manage', 'newtask'], true)) {
            $this->replyMenuCommand($command, $from, $chatId, $threadId);

            return;
        }

        if (in_array($command, ['scoresday', 'scoresweek', 'scoresmonth'], true)) {
            $this->replyScoresCommand($command, $chatId, $threadId);

            return;
        }

        if ($command === 'streaks') {
            $view = $this->streakCompetitionService->view();
            $this->telegramNotifier->replyWithInlineKeyboard($chatId, $view['text'], $view['keyboard'], $threadId);

            return;
        }

        $isLeaderboard = in_array($command, ['topday', 'topweek', 'topmonth'], true);

        if ($isLeaderboard) {
            $progressId = $this->telegramNotifier->reply($chatId, 'در حال محاسبه امتیاز…', $threadId);

            FinishTelegramLeaderboard::dispatchAfterResponse(
                $command,
                $fromUsername,
                $chatId,
                $progressId,
                $threadId,
            );

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

    public function completeDeploy(string|int $chatId, ?int $progressMessageId): void
    {
        $result = $this->selfDeployService->run();
        $this->selfDeployService->reportResult($chatId, $progressMessageId, $result);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $from
     */
    private function handleDeployCommand(array $message, array $from, string|int $chatId): void
    {
        if (($message['chat']['type'] ?? '') !== 'private') {
            return;
        }

        $userId = (int) ($from['id'] ?? 0);

        if (! $this->selfDeployService->isAdmin($userId)) {
            $this->telegramNotifier->reply($chatId, 'اجازه دسترسی به این ربات را نداری.');

            return;
        }

        if ($this->selfDeployService->isLocked()) {
            $this->telegramNotifier->reply($chatId, 'آپدیت دیگری در جریانه. کمی بعد دوباره /deploy بزن.');

            return;
        }

        $progressId = $this->telegramNotifier->reply($chatId, 'در حال آپدیت از گیت‌هاب…');

        FinishSelfDeploy::dispatchAfterResponse($chatId, $progressId);
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

        if ($chatId === null || $chatId === '' || ! is_numeric($messageId)) {
            return;
        }

        if ($data === NotifyMorningCheckIn::CALLBACK_DATA) {
            $this->handleMorningCheckInCallback($callbackId, $from, $chatId, (int) $messageId);

            return;
        }

        if ($this->punchService->isPunchCallback($data)) {
            $this->handlePunchCallback($callbackId, $data, $from, $message, $chatId, (int) $messageId);

            return;
        }

        if ($this->taskMenuService->isMenuCallback($data)
            || $this->assignActionService->isAssignCallback($data)
            || $this->taskQualityGateService->isQualityCallback($data)
            || $this->streakCompetitionService->isCompetitionCallback($data)
        ) {
            $this->handleInteractiveCallback($callbackId, $data, $from, $message, $chatId, (int) $messageId);

            return;
        }

        $this->telegramNotifier->answerCallbackQuery($callbackId);
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $from
     */
    private function handlePunchCommand(
        string $command,
        array $from,
        string|int $chatId,
        string|int|null $threadId,
    ): void {
        try {
            if ($command === 'punch') {
                $result = $this->punchService->punchIn($from, 'command');
                $messageId = $this->telegramNotifier->replyWithInlineKeyboard(
                    $chatId,
                    $result['text'],
                    $result['keyboard'],
                    $threadId
                );

                if ($result['ok'] && $result['session'] !== null) {
                    $this->punchService->attachMessage($result['session'], $chatId, $messageId);
                }

                return;
            }

            if ($command === 'punchout') {
                $result = $this->punchService->punchOut($from, 'command');
                $this->telegramNotifier->reply($chatId, $result['text'], $threadId);

                return;
            }

            if ($command === 'status') {
                $this->telegramNotifier->reply($chatId, $this->punchService->statusText($from), $threadId);

                return;
            }

            if ($command === 'stat') {
                $result = $this->punchService->createStatLink($from);

                if (! $result['ok']) {
                    $this->telegramNotifier->reply($chatId, $result['text'], $threadId);

                    return;
                }

                $userId = (int) ($from['id'] ?? 0);

                if ($userId > 0) {
                    $this->telegramNotifier->reply($userId, $result['text']);
                }

                if ((string) $chatId !== (string) $userId) {
                    $this->telegramNotifier->reply($chatId, 'لینک آمار برات تو پیوی ارسال شد.', $threadId);
                }

                return;
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->telegramNotifier->reply(
                $chatId,
                'خطا در punch: '.$this->publicError($exception),
                $threadId
            );
        }
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $message
     */
    private function handlePunchCallback(
        string $callbackId,
        string $data,
        array $from,
        array $message,
        string|int $chatId,
        int $messageId,
    ): void {
        if (! $this->isAuthorizedCallback($message, $from)) {
            $this->telegramNotifier->answerCallbackQuery($callbackId, 'اجازه دسترسی نداری.', true);

            return;
        }

        try {
            if ($data === PunchService::CALLBACK_STILL) {
                $result = $this->punchService->stillWorking($from);
                $this->telegramNotifier->answerCallbackQuery($callbackId, $result['toast'], ! $result['ok']);
                $this->telegramNotifier->edit(
                    $chatId,
                    $messageId,
                    $result['text'],
                    ['inline_keyboard' => $result['keyboard']]
                );

                return;
            }

            $result = $this->punchService->punchOut($from, 'button');
            $this->telegramNotifier->answerCallbackQuery($callbackId, $result['toast'], ! $result['ok']);
            $this->telegramNotifier->edit(
                $chatId,
                $messageId,
                $result['text'],
                ['inline_keyboard' => []]
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->telegramNotifier->answerCallbackQuery(
                $callbackId,
                'خطا: '.$this->publicError($exception),
                true
            );
        }
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function handleMorningCheckInCallback(
        string $callbackId,
        array $from,
        string|int $chatId,
        int $messageId,
    ): void {
        $allowedChatId = (string) config('telegram.chat_id', '');

        if ($allowedChatId === '' || (string) $chatId !== $allowedChatId) {
            $this->telegramNotifier->answerCallbackQuery($callbackId, 'این دکمه فقط در گروه تنظیم‌شده کار می‌کند.', true);

            return;
        }

        try {
            $result = $this->morningCheckIn->recordStart($from);
            $toast = $result['toast'];
            $alert = false;

            if ($result['created'] && $result['work_start'] !== null) {
                $this->morningCheckIn->refreshMessage($chatId, $messageId);
                $dashboard = $this->personalMorningDashboardService->sendFor($from, $result['work_start']);

                if ($dashboard['toast'] !== null) {
                    $toast = $dashboard['toast'];
                }

                $alert = $dashboard['blocked'];
            }

            $this->telegramNotifier->answerCallbackQuery($callbackId, $toast, $alert);
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
     * @param  array<string, mixed>  $message
     */
    private function handleInteractiveCallback(
        string $callbackId,
        string $data,
        array $from,
        array $message,
        string|int $chatId,
        int $messageId,
    ): void {
        if (! $this->isAuthorizedCallback($message, $from)) {
            $this->telegramNotifier->answerCallbackQuery($callbackId, 'اجازه دسترسی نداری.', true);

            return;
        }

        try {
            $result = match (true) {
                $this->assignActionService->isAssignCallback($data) => $this->assignActionService->handleCallback($data, $from),
                $this->taskQualityGateService->isQualityCallback($data) => $this->taskQualityGateService->handleCallback($data, $from),
                $this->streakCompetitionService->isCompetitionCallback($data) => $this->streakCompetitionService->view(),
                default => $this->taskMenuService->handleCallback($data, $from),
            };

            $toast = (string) ($result['toast'] ?? '');
            $alert = (bool) ($result['alert'] ?? false);

            $this->telegramNotifier->answerCallbackQuery($callbackId, $toast, $alert);
            $this->telegramNotifier->edit(
                $chatId,
                $messageId,
                (string) $result['text'],
                ['inline_keyboard' => $result['keyboard']]
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->telegramNotifier->answerCallbackQuery(
                $callbackId,
                'خطا: '.$this->publicError($exception),
                true
            );
        }
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function handleNaturalLanguageTask(
        array $from,
        string $text,
        string|int $chatId,
        string|int|null $threadId,
    ): void {
        try {
            $result = $this->naturalLanguageTaskService->createFromText($text, $from);
        } catch (\Throwable $exception) {
            report($exception);
            $this->telegramNotifier->reply(
                $chatId,
                'خطا در ساخت تسک: '.$this->publicError($exception),
                $threadId
            );

            return;
        }

        $this->telegramNotifier->replyWithInlineKeyboard(
            $chatId,
            (string) $result['text'],
            $result['keyboard'],
            $threadId
        );
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function handleComposeMessage(
        array $from,
        string $text,
        string|int $chatId,
        string|int|null $threadId,
    ): void {
        $normalized = trim(str_replace('‌', ' ', $text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        if (in_array($normalized, ['لغو', 'cancel', '/cancel'], true)) {
            $this->taskMenuService->clearCompose($from);
            $home = $this->taskMenuService->home();
            $this->telegramNotifier->replyWithInlineKeyboard(
                $chatId,
                'ایجاد تسک لغو شد.',
                $home['keyboard'],
                $threadId
            );

            return;
        }

        try {
            $result = $this->taskMenuService->completeCreate($from, $text);
        } catch (\Throwable $exception) {
            report($exception);
            $this->telegramNotifier->reply(
                $chatId,
                'خطا در ایجاد تسک: '.$this->publicError($exception),
                $threadId
            );

            return;
        }

        $this->telegramNotifier->replyWithInlineKeyboard(
            $chatId,
            (string) $result['text'],
            $result['keyboard'],
            $threadId
        );
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function replyMenuCommand(
        string $command,
        array $from,
        string|int $chatId,
        string|int|null $threadId,
    ): void {
        try {
            $view = $command === 'newtask'
                ? $this->taskMenuService->handleCallback('tm:new', $from)
                : $this->taskMenuService->home();
        } catch (\Throwable $exception) {
            report($exception);
            $this->telegramNotifier->reply(
                $chatId,
                'خطا در منو: '.$this->publicError($exception),
                $threadId
            );

            return;
        }

        $this->telegramNotifier->replyWithInlineKeyboard(
            $chatId,
            (string) $view['text'],
            $view['keyboard'],
            $threadId
        );
    }

    private function replyScoresCommand(
        string $command,
        string|int $chatId,
        string|int|null $threadId,
    ): void {
        $period = match ($command) {
            'scoresweek' => 'week',
            'scoresmonth' => 'month',
            default => 'day',
        };

        try {
            $view = $this->scoreBreakdownService->view($period);
        } catch (\Throwable $exception) {
            report($exception);
            $this->telegramNotifier->reply(
                $chatId,
                'خطا در جزئیات امتیاز: '.$this->publicError($exception),
                $threadId
            );

            return;
        }

        $this->telegramNotifier->replyWithInlineKeyboard(
            $chatId,
            (string) $view['text'],
            $view['keyboard'],
            $threadId
        );
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
            'حضور و کار:',
            '/punch — شروع کار',
            '/punchout — پایان کار',
            '/status — وضعیت جلسه و جمع امروز',
            '/stat — لینک آمار (فقط ادمین)',
            '',
            'مدیریت با دکمه شیشه‌ای:',
            '/menu — منوی تسک‌ها، امتیاز، استریک',
            '/newtask — ایجاد تسک ساده',
            '/task متن... — ساخت تسک از جمله فارسی',
            '/streaks — استریک‌ها و قهرمان هفته',
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
            '/scoresday — جزئیات امتیاز امروز',
            '/scoresweek — جزئیات امتیاز این هفته',
            '/scoresmonth — جزئیات امتیاز این ماه',
            '/help — همین راهنما',
        ]);
    }

    private function parseCommand(string $text): ?string
    {
        if (preg_match('/^\/([a-zA-Z_]+)(?:@\w+)?(?:\s|$)/u', $text, $matches) === 1) {
            return match (strtolower($matches[1])) {
                'top_day', 'daily' => 'topday',
                'top_week', 'weekly' => 'topweek',
                'top_month', 'monthly' => 'topmonth',
                'scores_day', 'scoreday' => 'scoresday',
                'scores_week', 'scoreweek' => 'scoresweek',
                'scores_month', 'scoremonth' => 'scoresmonth',
                'new', 'create' => 'newtask',
                'manage' => 'menu',
                'streak', 'streaks' => 'streaks',
                'punch_in', 'punchin' => 'punch',
                'punch_out' => 'punchout',
                'stats' => 'stat',
                default => strtolower($matches[1]),
            };
        }

        $normalized = trim(str_replace('‌', ' ', $text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return match ($normalized) {
            'منو', 'مدیریت', 'مدیریت تسک' => 'menu',
            'تسک جدید', 'ایجاد تسک' => 'newtask',
            'استریک', 'استریک ها', 'رقابت' => 'streaks',
            'لیست', 'لیست تسک ها', 'تسک ها' => 'tasks',
            'فردا', 'تسک های فردا' => 'tomorrow',
            'امروز', 'تسک های امروز' => 'today',
            'عقب افتاده', 'عقبافتاده' => 'overdue',
            'تسک های من' => 'me',
            'برترین روز', 'برترین های روز' => 'topday',
            'برترین هفته', 'برترین های هفته' => 'topweek',
            'برترین ماه', 'برترین های ماه' => 'topmonth',
            'امتیاز امروز', 'جزئیات امتیاز امروز' => 'scoresday',
            'امتیاز هفته', 'جزئیات امتیاز هفته' => 'scoresweek',
            'امتیاز ماه', 'جزئیات امتیاز ماه' => 'scoresmonth',
            'پانچ', 'شروع کار' => 'punch',
            'خروج', 'پایان کار', 'پانچ اوت' => 'punchout',
            'وضعیت', 'وضعیت کار' => 'status',
            'آمار', 'آمار کار' => 'stat',
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

        return $this->isMappedTelegramUsername((string) ($message['from']['username'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $from
     */
    private function isAuthorizedCallback(array $message, array $from): bool
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $allowedChatId = (string) config('telegram.chat_id', '');

        if ($allowedChatId !== '' && $chatId === $allowedChatId) {
            return true;
        }

        if (($message['chat']['type'] ?? '') !== 'private') {
            return false;
        }

        return $this->isMappedTelegramUsername((string) ($from['username'] ?? ''));
    }

    private function isMappedTelegramUsername(string $username): bool
    {
        $username = mb_strtolower(ltrim(trim($username), '@'));

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
