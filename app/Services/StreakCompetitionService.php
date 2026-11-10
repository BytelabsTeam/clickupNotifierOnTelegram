<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TaskScore;
use App\Models\WorkStart;
use App\Support\JalaliCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class StreakCompetitionService
{
    public const CALLBACK_PREFIX = 'comp:';

    public function __construct(
        private readonly ScoreIdentityResolver $scoreIdentityResolver,
        private readonly UserNameResolver $userNameResolver,
        private readonly TelegramUsernameResolver $telegramUsernameResolver,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    public function isCompetitionCallback(string $data): bool
    {
        return str_starts_with($data, self::CALLBACK_PREFIX);
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function view(): array
    {
        $streaks = $this->teamStreaks();
        $champion = $this->weekChampion();

        $lines = ['رقابت و استریک تیم', ''];

        if ($champion !== null) {
            $lines[] = 'قهرمان این هفته: '.$champion['display'].' — '.number_format($champion['score']).' امتیاز';
            $lines[] = '';
        }

        if ($streaks === []) {
            $lines[] = 'هنوز استریکی ثبت نشده. با «شروع کار» شروع کن.';
        } else {
            $lines[] = 'استریک‌ها:';

            foreach ($streaks as $index => $row) {
                $rank = $index + 1;
                $lines[] = $rank.'. '.$row['display'].' — '.$row['streak'].' روز';
            }
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => [
                [
                    ['text' => 'امتیاز هفته', 'callback_data' => 'tm:scores:week'],
                    ['text' => 'منوی اصلی', 'callback_data' => 'tm:home'],
                ],
            ],
        ];
    }

    public function streakForTelegramUser(int $telegramUserId, ?string $asOfDate = null): int
    {
        if ($telegramUserId <= 0) {
            return 0;
        }

        $timezone = JalaliCalendar::timezone();
        $cursor = $asOfDate !== null
            ? Carbon::parse($asOfDate, $timezone)->startOfDay()
            : Carbon::now($timezone)->startOfDay();

        $dates = WorkStart::query()
            ->where('telegram_user_id', $telegramUserId)
            ->whereDate('work_date', '<=', $cursor->toDateString())
            ->orderByDesc('work_date')
            ->pluck('work_date')
            ->map(static fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->values()
            ->all();

        if ($dates === []) {
            return 0;
        }

        $streak = 0;
        $expected = $cursor->toDateString();

        foreach ($dates as $date) {
            if ($date !== $expected) {
                break;
            }

            $streak++;
            $expected = Carbon::parse($expected, $timezone)->subDay()->toDateString();
        }

        return $streak;
    }

    public function streakForEmail(?string $email, ?string $asOfDate = null): int
    {
        if ($email === null || $email === '') {
            return 0;
        }

        $row = WorkStart::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->orderByDesc('work_date')
            ->first();

        if ($row === null) {
            return 0;
        }

        return $this->streakForTelegramUser((int) $row->telegram_user_id, $asOfDate);
    }

    /**
     * @return list<array{display: string, streak: int, telegram_user_id: int}>
     */
    public function teamStreaks(): array
    {
        $timezone = JalaliCalendar::timezone();
        $today = Carbon::now($timezone)->toDateString();
        $userIds = WorkStart::query()
            ->distinct()
            ->pluck('telegram_user_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $rows = [];

        foreach ($userIds as $userId) {
            $streak = $this->streakForTelegramUser($userId, $today);

            if ($streak <= 0) {
                continue;
            }

            $latest = WorkStart::query()
                ->where('telegram_user_id', $userId)
                ->orderByDesc('work_date')
                ->first();

            $display = trim((string) ($latest?->display_name ?: $latest?->telegram_username ?: 'کاربر'));
            $rows[] = [
                'display' => $display,
                'streak' => $streak,
                'telegram_user_id' => $userId,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return $b['streak'] <=> $a['streak']
                ?: strcmp($a['display'], $b['display']);
        });

        return $rows;
    }

    /**
     * @return array{display: string, score: int, key: string}|null
     */
    public function weekChampion(): ?array
    {
        $totals = $this->scoreTotalsForCurrentWeek();

        if ($totals === []) {
            return null;
        }

        uasort($totals, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values($totals)[0] ?? null;
    }

    /**
     * Compare today's leaderboard before/after and announce one overtake in the group.
     */
    public function maybeAnnounceOvertake(?string $actorEmail = null, ?string $actorName = null): void
    {
        $after = $this->scoreTotalsForToday();
        $cacheKey = 'competition:day-ranks:'.Carbon::now(JalaliCalendar::timezone())->toDateString();
        $before = Cache::get($cacheKey);

        Cache::put($cacheKey, $this->rankMap($after), now()->endOfDay());

        if (! is_array($before) || $before === [] || $after === []) {
            return;
        }

        $afterRanks = $this->rankMap($after);
        $actorKey = $this->scoreIdentityResolver->resolve(null, $actorEmail, $actorName)['key'];

        if ($actorKey === '' || ! isset($afterRanks[$actorKey], $before[$actorKey])) {
            return;
        }

        $oldRank = (int) $before[$actorKey];
        $newRank = (int) $afterRanks[$actorKey];

        if ($newRank >= $oldRank) {
            return;
        }

        $passed = null;

        foreach ($before as $key => $rank) {
            if ((int) $rank === $newRank && $key !== $actorKey) {
                $passed = $after[$key]['display'] ?? null;
                break;
            }
        }

        if ($passed === null) {
            return;
        }

        $actorDisplay = $after[$actorKey]['display'] ?? ($actorName ?: 'کسی');
        $dedupe = 'competition:overtake:'.Carbon::now(JalaliCalendar::timezone())->toDateString().":{$actorKey}:{$newRank}";

        if (! Cache::add($dedupe, true, now()->addDay())) {
            return;
        }

        try {
            $this->telegramNotifier->send("🔥 {$actorDisplay} از {$passed} جلو زد (رتبه {$newRank} امروز).");
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<string, array{display: string, score: int, key: string}>
     */
    public function scoreTotalsForToday(): array
    {
        $timezone = JalaliCalendar::timezone();
        $from = Carbon::now($timezone)->startOfDay();
        $to = Carbon::now($timezone)->endOfDay();

        return $this->scoreTotalsBetween($from, $to);
    }

    /**
     * @return array<string, array{display: string, score: int, key: string}>
     */
    public function scoreTotalsForCurrentWeek(): array
    {
        $timezone = JalaliCalendar::timezone();
        $from = Carbon::now($timezone)->startOfWeek(Carbon::SATURDAY);
        $to = $from->copy()->addWeek()->subSecond();

        return $this->scoreTotalsBetween($from, $to);
    }

    /**
     * @return array<string, array{display: string, score: int, key: string}>
     */
    private function scoreTotalsBetween(Carbon $from, Carbon $to): array
    {
        $month = JalaliCalendar::monthKey(Carbon::now(JalaliCalendar::timezone()));
        $fromUtc = $from->copy()->utc();
        $toUtc = $to->copy()->utc();
        $totals = [];

        $commits = CommitScore::query()
            ->where('month', $month)
            ->where('score', '>', 0)
            ->whereNotNull('committed_at')
            ->where('committed_at', '>=', $fromUtc)
            ->where('committed_at', '<=', $toUtc)
            ->get();

        foreach ($commits as $row) {
            $this->addTotal(
                $totals,
                $this->scoreIdentityResolver->resolve($row->login, $row->email, $row->name),
                (int) $row->score,
            );
        }

        $tasks = TaskScore::query()
            ->where('month', $month)
            ->where('is_code', false)
            ->where('score', '>', 0)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $fromUtc)
            ->where('completed_at', '<=', $toUtc)
            ->get();

        foreach ($tasks as $row) {
            $this->addTotal(
                $totals,
                $this->scoreIdentityResolver->resolve(null, $row->email, $row->username ?? $row->name),
                (int) $row->score,
            );
        }

        return $totals;
    }

    /**
     * @param  array<string, array{display: string, score: int, key: string}>  $totals
     * @param  array{key: string, email: ?string, username: ?string}  $identity
     */
    private function addTotal(array &$totals, array $identity, int $score): void
    {
        if ($identity['key'] === '' || $score <= 0) {
            return;
        }

        $totals[$identity['key']] ??= [
            'key' => $identity['key'],
            'display' => $this->telegramUsernameResolver->formatDisplayName(
                $this->userNameResolver->resolve($identity['email'], $identity['username']),
                $identity['email']
            ),
            'score' => 0,
        ];
        $totals[$identity['key']]['score'] += $score;
    }

    /**
     * @param  array<string, array{display: string, score: int, key: string}>  $totals
     * @return array<string, int>
     */
    private function rankMap(array $totals): array
    {
        uasort($totals, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $ranks = [];
        $rank = 1;

        foreach ($totals as $key => $row) {
            $ranks[$key] = $rank;
            $rank++;
        }

        return $ranks;
    }
}
