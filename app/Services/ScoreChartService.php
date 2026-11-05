<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TeamTask;
use App\Support\JalaliCalendar;
use Carbon\Carbon;

/**
 * Monthly score chart rendered as a Telegram-safe text table with bars (Tehran calendar month).
 * No external chart service is used, so no team data leaves the server.
 */
class ScoreChartService
{
    private const BAR_WIDTH = 20;

    public function __construct(private readonly TeamIdentityService $identity)
    {
    }

    public function formatMonth(?Carbon $now = null): string
    {
        $month = JalaliCalendar::currentMonth($now);
        $people = $this->monthlyTotals($month['from'], $month['to']);

        $lines = ["📈 نمودار امتیاز ماه {$month['key']} (وقت تهران)", ''];

        if ($people === []) {
            $lines[] = 'در این ماه هنوز امتیازی ثبت نشده.';

            return implode("\n", $lines);
        }

        $max = max(array_column($people, 'score'));
        $total = array_sum(array_column($people, 'score'));

        foreach ($people as $index => $person) {
            $rank = $index + 1;
            $bar = $this->bar($person['score'], $max);
            $share = $total > 0 ? round($person['score'] * 100 / $total) : 0;

            $lines[] = sprintf(
                '%d) %s  %s  %s (%s%%)',
                $rank,
                $person['name'],
                $bar,
                number_format($person['score']),
                $share
            );
        }

        $lines[] = '';
        $lines[] = 'جمع تیم: '.number_format($total);

        return implode("\n", $lines);
    }

    /**
     * @return list<array{name: string, score: int}>
     */
    public function monthlyTotals(Carbon $from, Carbon $to): array
    {
        $fromUtc = $from->copy()->utc();
        $toUtc = $to->copy()->utc();
        $totals = [];

        $commits = CommitScore::query()
            ->where('score', '>', 0)
            ->whereNotNull('committed_at')
            ->whereBetween('committed_at', [$fromUtc, $toUtc])
            ->get();

        foreach ($commits as $row) {
            $member = $this->identity->resolveGithubLogin($row->login);

            if ($member === null) {
                continue;
            }

            $totals[$member['key']] ??= ['name' => $member['display'], 'score' => 0];
            $totals[$member['key']]['score'] += (int) $row->score;
        }

        $tasks = TeamTask::query()
            ->where('is_code', false)
            ->where('score', '>', 0)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$fromUtc, $toUtc])
            ->get();

        foreach ($tasks as $task) {
            $key = 'tg:'.$task->assignee_telegram_id;
            $totals[$key] ??= ['name' => (string) ($task->assignee_name ?: '@'.$task->assignee_username), 'score' => 0];
            $totals[$key]['score'] += (int) $task->score;
        }

        $list = array_values($totals);
        usort($list, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));

        return $list;
    }

    private function bar(int $score, int $max): string
    {
        if ($max <= 0 || $score <= 0) {
            return str_repeat('░', self::BAR_WIDTH);
        }

        $filled = max(1, (int) round($score / $max * self::BAR_WIDTH));

        return str_repeat('█', $filled).str_repeat('░', self::BAR_WIDTH - $filled);
    }
}
