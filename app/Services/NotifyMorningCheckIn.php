<?php

namespace App\Services;

use App\Models\MorningCheckinMessage;
use App\Models\TeamMember;
use App\Models\WorkStart;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class NotifyMorningCheckIn
{
    public const CALLBACK_DATA = 'work_start';

    public function __construct(
        private readonly TeamIdentityService $identity,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    /**
     * @return array{sent: bool, message_id: ?int, work_date: string, mentions: int, timezone: string}
     */
    public function notify(): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Tehran');
        $workDate = Carbon::now($timezone)->toDateString();
        $message = $this->formatMessage($workDate);
        $messageId = $this->telegramNotifier->sendWithInlineKeyboard(
            $message,
            $this->inlineKeyboard()
        );

        if ($messageId !== null) {
            MorningCheckinMessage::query()->updateOrCreate(
                ['work_date' => $workDate],
                [
                    'chat_id' => (string) config('telegram.chat_id'),
                    'message_id' => $messageId,
                ]
            );
        }

        return [
            'sent' => $messageId !== null,
            'message_id' => $messageId,
            'work_date' => $workDate,
            'mentions' => count($this->mentionUsernames()),
            'timezone' => $timezone,
        ];
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $from
     * @return array{created: bool, work_start: ?WorkStart, toast: string}
     */
    public function recordStart(array $from): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Tehran');
        $now = Carbon::now($timezone);
        $workDate = $now->toDateString();
        $telegramUserId = (int) ($from['id'] ?? 0);

        if ($telegramUserId <= 0) {
            return [
                'created' => false,
                'work_start' => null,
                'toast' => 'شناسه کاربر نامعتبر است.',
            ];
        }

        $existing = WorkStart::query()
            ->whereDate('work_date', $workDate)
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if ($existing !== null) {
            return [
                'created' => false,
                'work_start' => $existing,
                'toast' => 'قبلاً ثبت شده: '.$this->formatClock($existing->started_at->timezone($timezone)),
            ];
        }

        $username = ltrim(trim((string) ($from['username'] ?? '')), '@');
        $member = TeamMember::query()->where('telegram_user_id', $telegramUserId)->first();

        if ($member === null || ! $member->is_active) {
            return [
                'created' => false,
                'work_start' => null,
                'toast' => 'این حساب عضو فعال تیم نیست.',
            ];
        }

        $displayName = $member->display_name;

        try {
            $workStart = WorkStart::query()->create([
                'work_date' => $workDate,
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $username !== '' ? $username : null,
                'display_name' => $displayName,
                'email' => null,
                'started_at' => $now,
            ]);
        } catch (QueryException) {
            $existing = WorkStart::query()
                ->whereDate('work_date', $workDate)
                ->where('telegram_user_id', $telegramUserId)
                ->first();

            return [
                'created' => false,
                'work_start' => $existing,
                'toast' => $existing !== null
                    ? 'قبلاً ثبت شده: '.$this->formatClock($existing->started_at->timezone($timezone))
                    : 'ثبت نشد؛ دوباره امتحان کن.',
            ];
        }

        return [
            'created' => true,
            'work_start' => $workStart,
            'toast' => 'شروع کار ثبت شد ✅',
        ];
    }

    public function refreshMessage(string|int $chatId, int $messageId, ?string $workDate = null): void
    {
        $timezone = (string) config('app.timezone', 'Asia/Tehran');
        $workDate ??= Carbon::now($timezone)->toDateString();

        $this->telegramNotifier->edit(
            $chatId,
            $messageId,
            $this->formatMessage($workDate),
            ['inline_keyboard' => $this->inlineKeyboard()]
        );
    }

    public function formatMessage(string $workDate): string
    {
        $lines = ['صبح بخیر ☀️'];

        $mentions = $this->mentionUsernames();

        if ($mentions !== []) {
            $lines[] = '';
            $lines[] = implode(' ', array_map(
                static fn (string $username): string => '@'.$username,
                $mentions
            ));
        }

        $starts = $this->startsForDate($workDate);

        if ($starts->isNotEmpty()) {
            $timezone = (string) config('app.timezone', 'Asia/Tehran');
            $lines[] = '';
            $lines[] = 'شروع‌کرده‌ها:';

            foreach ($starts as $start) {
                $name = trim((string) ($start->display_name ?: $start->telegram_username ?: 'کاربر'));
                $clock = $this->formatClock($start->started_at->timezone($timezone));
                $lines[] = "{$name} — {$clock}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function inlineKeyboard(): array
    {
        return [
            [
                [
                    'text' => 'شروع کار',
                    'callback_data' => self::CALLBACK_DATA,
                ],
            ],
        ];
    }

    /**
     * @return Collection<int, WorkStart>
     */
    private function startsForDate(string $workDate): Collection
    {
        return WorkStart::query()
            ->whereDate('work_date', $workDate)
            ->orderBy('started_at')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function mentionUsernames(): array
    {
        return TeamMember::query()
            ->where('is_active', true)
            ->whereNotNull('telegram_username')
            ->pluck('telegram_username')
            ->map(fn (string $username): string => ltrim(trim($username), '@'))
            ->filter()
            ->unique(fn (string $username): string => mb_strtolower($username))
            ->values()
            ->all();
    }

    private function formatClock(Carbon $time): string
    {
        return $this->toPersianDigits($time->format('H:i'));
    }

    private function toPersianDigits(string $value): string
    {
        return strtr($value, [
            '0' => '۰',
            '1' => '۱',
            '2' => '۲',
            '3' => '۳',
            '4' => '۴',
            '5' => '۵',
            '6' => '۶',
            '7' => '۷',
            '8' => '۸',
            '9' => '۹',
        ]);
    }
}
