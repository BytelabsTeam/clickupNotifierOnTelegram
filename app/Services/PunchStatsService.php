<?php

namespace App\Services;

use App\Models\PunchStatToken;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PunchStatsService
{
    public function __construct(
        private readonly PunchService $punchService,
    ) {
    }

    public function findValidToken(string $token): ?PunchStatToken
    {
        $row = PunchStatToken::query()->where('token', $token)->first();

        if ($row === null || ! $row->isValid()) {
            return null;
        }

        return $row;
    }

    /**
     * @return array{
     *     generated_at: string,
     *     people: list<array<string, mixed>>,
     *     today_sessions: list<array<string, mixed>>,
     *     activity_log: list<array<string, mixed>>,
     *     heatmap: array{weeks: list<list<array{date: string, seconds: int, level: int}>>, max_seconds: int}
     * }
     */
    public function dashboard(): array
    {
        $timezone = $this->punchService->timezone();
        $now = Carbon::now($timezone);
        $today = $now->toDateString();
        $weekStart = $now->copy()->startOfWeek(Carbon::SATURDAY)->startOfDay();
        $monthStart = $now->copy()->startOfMonth()->startOfDay();

        $sessions = WorkSession::query()
            ->where('punched_in_at', '>=', $now->copy()->subDays(400)->startOfDay())
            ->orderBy('punched_in_at')
            ->get();

        $peopleMap = [];

        foreach ($sessions as $session) {
            $key = (string) $session->telegram_user_id;

            if (! isset($peopleMap[$key])) {
                $peopleMap[$key] = [
                    'telegram_user_id' => (int) $session->telegram_user_id,
                    'display_name' => (string) ($session->display_name ?: $session->telegram_username ?: 'کاربر'),
                    'telegram_username' => $session->telegram_username,
                    'today_seconds' => 0,
                    'week_seconds' => 0,
                    'month_seconds' => 0,
                    'is_working' => false,
                    'current_started_at' => null,
                ];
            }

            $seconds = $this->sessionSeconds($session, $now);
            $workDate = $session->work_date->toDateString();

            if ($workDate === $today) {
                $peopleMap[$key]['today_seconds'] += $seconds;
            }

            if ($session->punched_in_at->greaterThanOrEqualTo($weekStart)) {
                $peopleMap[$key]['week_seconds'] += $seconds;
            }

            if ($session->punched_in_at->greaterThanOrEqualTo($monthStart)) {
                $peopleMap[$key]['month_seconds'] += $seconds;
            }

            if ($session->isOpen()) {
                $peopleMap[$key]['is_working'] = true;
                $peopleMap[$key]['current_started_at'] = $session->punched_in_at->timezone($timezone)->format('Y-m-d H:i');
            }

            if (($session->display_name ?: '') !== '') {
                $peopleMap[$key]['display_name'] = (string) $session->display_name;
            }
        }

        $people = array_values($peopleMap);
        usort($people, static function (array $a, array $b): int {
            return $b['today_seconds'] <=> $a['today_seconds'];
        });

        foreach ($people as &$person) {
            $person['today_label'] = $this->punchService->formatDuration((int) $person['today_seconds']);
            $person['week_label'] = $this->punchService->formatDuration((int) $person['week_seconds']);
            $person['month_label'] = $this->punchService->formatDuration((int) $person['month_seconds']);
        }
        unset($person);

        $todaySessions = WorkSession::query()
            ->whereDate('work_date', $today)
            ->orderBy('punched_in_at')
            ->get()
            ->map(fn (WorkSession $session): array => $this->sessionRow($session, $now, $timezone))
            ->all();

        $activityLog = WorkSession::query()
            ->orderByDesc('punched_in_at')
            ->limit(40)
            ->get()
            ->flatMap(function (WorkSession $session) use ($timezone): array {
                $name = (string) ($session->display_name ?: $session->telegram_username ?: 'کاربر');
                $rows = [[
                    'at' => $session->punched_in_at->timezone($timezone)->format('Y-m-d H:i'),
                    'type' => 'in',
                    'label' => 'Punch in',
                    'name' => $name,
                    'detail' => $this->punchService->formatClock($session->punched_in_at->timezone($timezone)),
                ]];

                if ($session->punched_out_at !== null) {
                    $rows[] = [
                        'at' => $session->punched_out_at->timezone($timezone)->format('Y-m-d H:i'),
                        'type' => $session->auto_closed ? 'auto' : 'out',
                        'label' => $session->auto_closed ? 'Auto out' : 'Punch out',
                        'name' => $name,
                        'detail' => $this->punchService->formatDuration((int) ($session->duration_seconds ?? 0)),
                    ];
                }

                return $rows;
            })
            ->sortByDesc('at')
            ->take(50)
            ->values()
            ->all();

        return [
            'generated_at' => $now->format('Y-m-d H:i'),
            'people' => $people,
            'today_sessions' => $todaySessions,
            'activity_log' => $activityLog,
            'heatmap' => $this->buildHeatmap($sessions, $now),
        ];
    }

    /**
     * @param  Collection<int, WorkSession>  $sessions
     * @return array{weeks: list<list<array{date: string, seconds: int, level: int}>>, max_seconds: int}
     */
    private function buildHeatmap(Collection $sessions, Carbon $now): array
    {
        $timezone = $this->punchService->timezone();
        $end = $now->copy()->startOfDay();
        $start = $end->copy()->subWeeks(52)->startOfWeek(Carbon::SATURDAY);

        $byDate = [];

        foreach ($sessions as $session) {
            $date = $session->work_date->toDateString();
            $byDate[$date] = ($byDate[$date] ?? 0) + $this->sessionSeconds($session, $now);
        }

        $max = 0;
        foreach ($byDate as $seconds) {
            $max = max($max, $seconds);
        }

        $weeks = [];
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $date = $cursor->toDateString();
                $seconds = (int) ($byDate[$date] ?? 0);
                $week[] = [
                    'date' => $date,
                    'seconds' => $seconds,
                    'level' => $this->levelFor($seconds, $max),
                ];
                $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return [
            'weeks' => $weeks,
            'max_seconds' => $max,
            'timezone' => $timezone,
        ];
    }

    private function levelFor(int $seconds, int $max): int
    {
        if ($seconds <= 0 || $max <= 0) {
            return 0;
        }

        $ratio = $seconds / $max;

        return match (true) {
            $ratio >= 0.75 => 4,
            $ratio >= 0.5 => 3,
            $ratio >= 0.25 => 2,
            default => 1,
        };
    }

    private function sessionSeconds(WorkSession $session, Carbon $now): int
    {
        if ($session->duration_seconds !== null) {
            return (int) $session->duration_seconds;
        }

        if ($session->isOpen()) {
            return max(0, (int) $session->punched_in_at->diffInSeconds($now));
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRow(WorkSession $session, Carbon $now, string $timezone): array
    {
        $seconds = $this->sessionSeconds($session, $now);

        return [
            'name' => (string) ($session->display_name ?: $session->telegram_username ?: 'کاربر'),
            'username' => $session->telegram_username,
            'in' => $this->punchService->formatClock($session->punched_in_at->timezone($timezone)),
            'out' => $session->punched_out_at !== null
                ? $this->punchService->formatClock($session->punched_out_at->timezone($timezone))
                : 'باز',
            'duration' => $this->punchService->formatDuration($seconds),
            'open' => $session->isOpen(),
            'auto_closed' => (bool) $session->auto_closed,
        ];
    }
}
