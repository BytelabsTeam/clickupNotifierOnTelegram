<?php

namespace App\Services;

use App\Models\PunchStatToken;
use App\Models\WorkSession;
use App\Models\WorkStart;
use App\Support\JalaliCalendar;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class PunchService
{
    public const CALLBACK_OUT = 'punch:out';

    public const CALLBACK_STILL = 'punch:still';

    /** @var list<string> */
    public const STAT_ADMINS = ['megageek', 'call_of_dutty'];

    public function __construct(
        private readonly UserNameResolver $userNameResolver,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    public function isPunchCallback(string $data): bool
    {
        return $data === self::CALLBACK_OUT || $data === self::CALLBACK_STILL;
    }

    public function isStatAdmin(string $username): bool
    {
        $clean = mb_strtolower(ltrim(trim($username), '@'));

        return in_array($clean, self::STAT_ADMINS, true);
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $from
     * @return array{ok: bool, text: string, keyboard: list<list<array{text: string, callback_data: string}>>, session: ?WorkSession}
     */
    public function punchIn(array $from, string $source = 'command'): array
    {
        $timezone = $this->timezone();
        $now = Carbon::now($timezone);
        $telegramUserId = (int) ($from['id'] ?? 0);

        if ($telegramUserId <= 0) {
            return $this->plainResult(false, 'شناسه کاربر نامعتبر است.');
        }

        $open = $this->openSessionFor($telegramUserId);

        if ($open !== null) {
            $elapsed = $this->formatDuration($open->punched_in_at->diffInSeconds($now));

            return $this->plainResult(
                false,
                "قبلاً punch کردی.\nشروع: {$this->formatClock($open->punched_in_at->timezone($timezone))}\nمدت تا الان: {$elapsed}\nاول با دکمه «کار رو متوقف کردم» یا /punchout خارج شو."
            );
        }

        $username = ltrim(trim((string) ($from['username'] ?? '')), '@');
        $email = $this->emailForTelegramUsername($username);
        $displayName = $this->resolveDisplayName($from, $email, $username);

        $session = WorkSession::query()->create([
            'work_date' => $now->toDateString(),
            'telegram_user_id' => $telegramUserId,
            'telegram_username' => $username !== '' ? $username : null,
            'display_name' => $displayName,
            'email' => $email,
            'punched_in_at' => $now,
            'source' => $source,
        ]);

        $this->syncWorkStart($session);

        $text = implode("\n", [
            'شروع کار ثبت شد ✅',
            '',
            'تاریخ: '.$this->formatJalaliDate($now),
            'ساعت: '.$this->formatClock($now),
            '',
            'وقتی تموم شد دکمه پایین رو بزن.',
        ]);

        return [
            'ok' => true,
            'text' => $text,
            'keyboard' => $this->punchOutKeyboard(),
            'session' => $session,
        ];
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $from
     * @return array{ok: bool, text: string, keyboard: list<list<array{text: string, callback_data: string}>>, session: ?WorkSession, toast: string}
     */
    public function punchOut(array $from, string $source = 'command'): array
    {
        $timezone = $this->timezone();
        $now = Carbon::now($timezone);
        $telegramUserId = (int) ($from['id'] ?? 0);

        if ($telegramUserId <= 0) {
            return array_merge($this->plainResult(false, 'شناسه کاربر نامعتبر است.'), ['toast' => 'شناسه نامعتبر']);
        }

        $session = $this->openSessionFor($telegramUserId);

        if ($session === null) {
            return array_merge(
                $this->plainResult(false, 'جلسه بازی نداری. اول /punch بزن.'),
                ['toast' => 'جلسه بازی نیست']
            );
        }

        $duration = max(0, (int) $session->punched_in_at->diffInSeconds($now));
        $session->fill([
            'punched_out_at' => $now,
            'duration_seconds' => $duration,
            'source' => $source,
            'auto_closed' => false,
        ]);
        $session->save();

        $text = implode("\n", [
            'پایان کار ثبت شد ⏹',
            '',
            'شروع: '.$this->formatClock($session->punched_in_at->timezone($timezone)),
            'پایان: '.$this->formatClock($now),
            'مدت: '.$this->formatDuration($duration),
            'جمع امروز: '.$this->formatDuration($this->todaySeconds($telegramUserId, $now)),
        ]);

        return [
            'ok' => true,
            'text' => $text,
            'keyboard' => [],
            'session' => $session,
            'toast' => 'پایان کار ثبت شد ✅',
        ];
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $from
     * @return array{ok: bool, text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast: string}
     */
    public function stillWorking(array $from): array
    {
        $timezone = $this->timezone();
        $now = Carbon::now($timezone);
        $telegramUserId = (int) ($from['id'] ?? 0);

        if ($telegramUserId <= 0) {
            return [
                'ok' => false,
                'text' => 'شناسه کاربر نامعتبر است.',
                'keyboard' => [],
                'toast' => 'شناسه نامعتبر',
            ];
        }

        $session = $this->openSessionFor($telegramUserId);

        if ($session === null) {
            return [
                'ok' => false,
                'text' => 'جلسه بازی نداری.',
                'keyboard' => [],
                'toast' => 'جلسه بازی نیست',
            ];
        }

        $session->fill([
            'last_reminded_at' => $now,
        ]);
        $session->save();

        $elapsed = $this->formatDuration($session->punched_in_at->diffInSeconds($now));

        return [
            'ok' => true,
            'text' => "باشه، ادامه بده 💪\nاز شروع: {$elapsed}\nیادآوری بعدی حدود ۲ ساعت دیگه.",
            'keyboard' => $this->reminderKeyboard(),
            'toast' => 'ادامه کار ثبت شد',
        ];
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $from
     */
    public function statusText(array $from): string
    {
        $timezone = $this->timezone();
        $now = Carbon::now($timezone);
        $telegramUserId = (int) ($from['id'] ?? 0);

        if ($telegramUserId <= 0) {
            return 'شناسه کاربر نامعتبر است.';
        }

        $lines = ['وضعیت کار'];
        $open = $this->openSessionFor($telegramUserId);

        if ($open !== null) {
            $elapsed = $this->formatDuration($open->punched_in_at->diffInSeconds($now));
            $lines[] = '';
            $lines[] = '🟢 الان داری کار می‌کنی';
            $lines[] = 'شروع: '.$this->formatClock($open->punched_in_at->timezone($timezone));
            $lines[] = 'مدت تا الان: '.$elapsed;
        } else {
            $lines[] = '';
            $lines[] = '⚪ جلسه بازی نداری.';
        }

        $lines[] = '';
        $lines[] = 'جمع امروز: '.$this->formatDuration($this->todaySeconds($telegramUserId, $now));

        $sessions = WorkSession::query()
            ->where('telegram_user_id', $telegramUserId)
            ->whereDate('work_date', $now->toDateString())
            ->orderBy('punched_in_at')
            ->get();

        if ($sessions->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'جلسات امروز:';

            foreach ($sessions as $index => $session) {
                $start = $this->formatClock($session->punched_in_at->timezone($timezone));
                $end = $session->punched_out_at !== null
                    ? $this->formatClock($session->punched_out_at->timezone($timezone))
                    : 'باز';
                $dur = $session->duration_seconds !== null
                    ? $this->formatDuration((int) $session->duration_seconds)
                    : ($session->isOpen()
                        ? $this->formatDuration($session->punched_in_at->diffInSeconds($now))
                        : '—');
                $suffix = $session->auto_closed ? ' (auto)' : '';
                $lines[] = ($index + 1).") {$start} → {$end} — {$dur}{$suffix}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $from
     * @return array{ok: bool, text: string, url: ?string}
     */
    public function createStatLink(array $from): array
    {
        $username = (string) ($from['username'] ?? '');

        if (! $this->isStatAdmin($username)) {
            return [
                'ok' => false,
                'text' => 'اجازه دسترسی به آمار نداری.',
                'url' => null,
            ];
        }

        $telegramUserId = (int) ($from['id'] ?? 0);
        $token = Str::random(48);
        $expiresAt = Carbon::now($this->timezone())->addDay();

        PunchStatToken::query()->create([
            'token' => $token,
            'created_by_telegram_user_id' => $telegramUserId,
            'created_by_username' => ltrim(trim($username), '@') ?: null,
            'expires_at' => $expiresAt,
        ]);

        $url = rtrim((string) config('app.url'), '/').'/punch/stats/'.$token;

        return [
            'ok' => true,
            'text' => "لینک آمار (۲۴ ساعت معتبر):\n{$url}",
            'url' => $url,
        ];
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function punchOutKeyboard(): array
    {
        return [
            [
                [
                    'text' => 'کار رو متوقف کردم',
                    'callback_data' => self::CALLBACK_OUT,
                ],
            ],
        ];
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function reminderKeyboard(): array
    {
        return [
            [
                [
                    'text' => 'هنوز کار می‌کنم',
                    'callback_data' => self::CALLBACK_STILL,
                ],
                [
                    'text' => 'کار رو متوقف کردم',
                    'callback_data' => self::CALLBACK_OUT,
                ],
            ],
        ];
    }

    public function openSessionFor(int $telegramUserId): ?WorkSession
    {
        return WorkSession::query()
            ->open()
            ->where('telegram_user_id', $telegramUserId)
            ->orderByDesc('punched_in_at')
            ->first();
    }

    public function attachMessage(WorkSession $session, string|int $chatId, ?int $messageId): void
    {
        if ($messageId === null) {
            return;
        }

        $session->fill([
            'telegram_chat_id' => (string) $chatId,
            'telegram_message_id' => $messageId,
        ]);
        $session->save();
    }

    public function syncWorkStart(WorkSession $session): void
    {
        $existing = WorkStart::query()
            ->whereDate('work_date', $session->work_date->toDateString())
            ->where('telegram_user_id', $session->telegram_user_id)
            ->first();

        if ($existing !== null) {
            return;
        }

        try {
            WorkStart::query()->create([
                'work_date' => $session->work_date->toDateString(),
                'telegram_user_id' => $session->telegram_user_id,
                'telegram_username' => $session->telegram_username,
                'display_name' => $session->display_name,
                'email' => $session->email,
                'started_at' => $session->punched_in_at,
            ]);
        } catch (QueryException) {
            // Race with another punch/start — ignore.
        }
    }

    public function todaySeconds(int $telegramUserId, ?Carbon $now = null): int
    {
        $timezone = $this->timezone();
        $now ??= Carbon::now($timezone);
        $date = $now->toDateString();

        $sessions = WorkSession::query()
            ->where('telegram_user_id', $telegramUserId)
            ->whereDate('work_date', $date)
            ->get();

        $total = 0;

        foreach ($sessions as $session) {
            if ($session->duration_seconds !== null) {
                $total += (int) $session->duration_seconds;

                continue;
            }

            if ($session->isOpen()) {
                $total += max(0, (int) $session->punched_in_at->diffInSeconds($now));
            }
        }

        return $total;
    }

    public function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return $this->toPersianDigits((string) $hours).' ساعت و '.$this->toPersianDigits((string) $minutes).' دقیقه';
        }

        return $this->toPersianDigits((string) $minutes).' دقیقه';
    }

    public function formatClock(Carbon $time): string
    {
        return $this->toPersianDigits($time->format('H:i'));
    }

    public function formatJalaliDate(Carbon $time): string
    {
        [$jy, $jm, $jd] = JalaliCalendar::toJalali($time->copy()->timezone($this->timezone()));

        return $this->toPersianDigits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }

    public function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Tehran');
    }

    public function toPersianDigits(string $value): string
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

    /**
     * @param  array{username?: string, first_name?: string, last_name?: string}  $from
     */
    private function resolveDisplayName(array $from, ?string $email, string $username): string
    {
        if ($email !== null) {
            $mapped = $this->userNameResolver->resolve($email, $username !== '' ? $username : null);

            if ($mapped !== '' && $mapped !== 'کاربر ناشناس') {
                return $mapped;
            }
        }

        $first = trim((string) ($from['first_name'] ?? ''));
        $last = trim((string) ($from['last_name'] ?? ''));
        $full = trim($first.' '.$last);

        if ($full !== '') {
            return $full;
        }

        if ($username !== '') {
            return $username;
        }

        return 'کاربر';
    }

    private function emailForTelegramUsername(string $username): ?string
    {
        $needle = mb_strtolower(ltrim(trim($username), '@'));

        if ($needle === '') {
            return null;
        }

        foreach (config('telegram.usernames', []) as $email => $mapped) {
            if (mb_strtolower(ltrim(trim((string) $mapped), '@')) === $needle) {
                return (string) $email;
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, text: string, keyboard: list<list<array{text: string, callback_data: string}>>, session: null}
     */
    private function plainResult(bool $ok, string $text): array
    {
        return [
            'ok' => $ok,
            'text' => $text,
            'keyboard' => [],
            'session' => null,
        ];
    }
}
