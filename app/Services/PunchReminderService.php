<?php

namespace App\Services;

use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PunchReminderService
{
    public const REMINDER_INTERVAL_SECONDS = 7200;

    public function __construct(
        private readonly PunchService $punchService,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    /**
     * @return array{reminded: int, auto_closed: int, checked_at: string}
     */
    public function run(): array
    {
        $timezone = $this->punchService->timezone();
        $now = Carbon::now($timezone);

        $autoClosed = $this->autoCloseStaleSessions($now);
        $reminded = $this->sendReminders($now);

        return [
            'reminded' => $reminded,
            'auto_closed' => $autoClosed,
            'checked_at' => $now->toIso8601String(),
        ];
    }

    private function autoCloseStaleSessions(Carbon $now): int
    {
        $today = $now->toDateString();
        $closed = 0;

        /** @var Collection<int, WorkSession> $stale */
        $stale = WorkSession::query()
            ->open()
            ->whereDate('work_date', '<', $today)
            ->orderBy('id')
            ->get();

        foreach ($stale as $session) {
            $endOfWorkDay = Carbon::parse($session->work_date->toDateString(), $this->punchService->timezone())
                ->endOfDay();

            if ($endOfWorkDay->greaterThan($now)) {
                $endOfWorkDay = $now->copy();
            }

            $duration = max(0, (int) $session->punched_in_at->diffInSeconds($endOfWorkDay));

            $session->fill([
                'punched_out_at' => $endOfWorkDay,
                'duration_seconds' => $duration,
                'auto_closed' => true,
                'source' => 'auto',
            ]);
            $session->save();
            $closed++;

            try {
                $this->telegramNotifier->reply(
                    $session->telegram_user_id,
                    "جلسه دیروزت به‌صورت خودکار بسته شد.\nشروع: {$this->punchService->formatClock($session->punched_in_at->timezone($this->punchService->timezone()))}\nپایان: {$this->punchService->formatClock($endOfWorkDay)}\nمدت: {$this->punchService->formatDuration($duration)}"
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $closed;
    }

    private function sendReminders(Carbon $now): int
    {
        $threshold = $now->copy()->subSeconds(self::REMINDER_INTERVAL_SECONDS);
        $reminded = 0;

        /** @var Collection<int, WorkSession> $sessions */
        $sessions = WorkSession::query()
            ->open()
            ->where('punched_in_at', '<=', $threshold)
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $anchor = $session->last_reminded_at ?? $session->punched_in_at;

            if ($anchor->greaterThan($threshold)) {
                continue;
            }

            $elapsed = $this->punchService->formatDuration(
                (int) $session->punched_in_at->diffInSeconds($now)
            );

            $text = implode("\n", [
                'یادآوری punch ⏰',
                '',
                'هنوز داری کار می‌کنی یا یادت رفته punchout کنی؟',
                'از شروع: '.$elapsed,
            ]);

            try {
                $messageId = $this->telegramNotifier->replyWithInlineKeyboard(
                    $session->telegram_user_id,
                    $text,
                    $this->punchService->reminderKeyboard()
                );
            } catch (\Throwable $exception) {
                report($exception);

                continue;
            }

            $session->fill([
                'last_reminded_at' => $now,
                'reminder_count' => ((int) $session->reminder_count) + 1,
            ]);

            if ($messageId !== null) {
                $session->telegram_message_id = $messageId;
                $session->telegram_chat_id = (string) $session->telegram_user_id;
            }

            $session->save();
            $reminded++;
        }

        return $reminded;
    }
}
