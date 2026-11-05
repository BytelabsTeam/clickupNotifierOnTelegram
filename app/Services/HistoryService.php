<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TeamTask;
use App\Support\JalaliCalendar;
use Carbon\Carbon;

/**
 * Read-only history for every member: commits, tasks and scores by day / week / month (Tehran time).
 */
class HistoryService
{
    public function __construct(private readonly TeamIdentityService $identity)
    {
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    public function bounds(string $period, ?Carbon $now = null): array
    {
        $tz = JalaliCalendar::timezone();
        $now = ($now ?? Carbon::now($tz))->copy()->timezone($tz);

        return match ($period) {
            'week' => [
                $now->copy()->startOfWeek(Carbon::SATURDAY)->startOfDay(),
                $now->copy()->startOfWeek(Carbon::SATURDAY)->addDays(6)->endOfDay(),
                'هفته',
            ],
            'month' => [
                JalaliCalendar::currentMonth($now)['from'],
                JalaliCalendar::currentMonth($now)['to'],
                'ماه',
            ],
            default => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                'امروز',
            ],
        };
    }

    public function format(string $period, ?Carbon $now = null): string
    {
        [$from, $to, $label] = $this->bounds($period, $now);
        $tz = JalaliCalendar::timezone();
        $fromUtc = $from->copy()->utc();
        $toUtc = $to->copy()->utc();

        $commits = CommitScore::query()
            ->whereNotNull('committed_at')
            ->whereBetween('committed_at', [$fromUtc, $toUtc])
            ->orderBy('committed_at')
            ->get();

        $tasks = TeamTask::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$fromUtc, $toUtc])
            ->orderBy('completed_at')
            ->get();

        $lines = [
            "📚 تاریخچه {$label} (".$from->copy()->timezone($tz)->format('Y-m-d').' تا '.$to->copy()->timezone($tz)->format('Y-m-d').')',
            '',
            '💻 کامیت‌ها: '.$commits->count(),
        ];

        foreach ($commits->take(30) as $row) {
            $member = $this->identity->resolveGithubLogin($row->login);
            $who = $member['display'] ?? ($row->name ?: 'ناشناس');
            $score = (int) $row->score > 0 ? ' — '.number_format((int) $row->score) : '';
            $lines[] = '• '.$who.': '.$this->firstLine((string) $row->message).$score;
        }

        $lines[] = '';
        $lines[] = '📋 تسک‌های تکمیل‌شده: '.$tasks->count();

        foreach ($tasks->take(30) as $task) {
            $who = $task->assignee_name ?: '@'.$task->assignee_username;
            $score = (int) $task->score > 0 ? ' — '.number_format((int) $task->score) : '';
            $lines[] = '• '.$who.': '.$task->title.$score;
        }

        $totals = $this->totals($commits, $tasks);

        $lines[] = '';
        $lines[] = '⭐ امتیاز هر نفر:';

        if ($totals === []) {
            $lines[] = 'امتیازی ثبت نشده.';
        }

        foreach ($totals as $person) {
            $lines[] = '• '.$person['name'].' — '.number_format($person['score']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CommitScore>  $commits
     * @param  \Illuminate\Support\Collection<int, TeamTask>  $tasks
     * @return list<array{name: string, score: int}>
     */
    private function totals($commits, $tasks): array
    {
        $totals = [];

        foreach ($commits as $row) {
            $member = $this->identity->resolveGithubLogin($row->login);

            if ($member === null) {
                continue;
            }

            $totals[$member['key']] ??= ['name' => $member['display'], 'score' => 0];
            $totals[$member['key']]['score'] += (int) $row->score;
        }

        foreach ($tasks as $task) {
            $key = 'tg:'.$task->assignee_telegram_id;
            $totals[$key] ??= ['name' => (string) ($task->assignee_name ?: '@'.$task->assignee_username), 'score' => 0];
            $totals[$key]['score'] += (int) $task->score;
        }

        uasort($totals, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values($totals);
    }

    private function firstLine(string $message): string
    {
        $first = trim(explode("\n", $message, 2)[0] ?? '');

        return $first === '' ? '(بدون پیام)' : mb_substr($first, 0, 120);
    }
}
