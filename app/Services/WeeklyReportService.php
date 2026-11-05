<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\ReportRun;
use App\Models\TeamTask;
use App\Support\JalaliCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Weekly report in Tehran time. Week = Saturday..Friday (Iranian week).
 * Idempotent per week via report_runs.
 */
class WeeklyReportService
{
    public function __construct(
        private readonly TelegramNotifier $telegramNotifier,
        private readonly TeamIdentityService $identity,
    ) {
    }

    /**
     * @return array{sent: bool, period: string}
     */
    public function sendIfDue(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now(JalaliCalendar::timezone()))->copy()->timezone(JalaliCalendar::timezone());
        $start = $now->copy()->startOfWeek(Carbon::SATURDAY)->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();
        $periodKey = $start->toDateString();

        // Send once the week is over: Friday evening (Tehran) and later.
        if (! $this->isReportTime($now)) {
            return ['sent' => false, 'period' => $periodKey];
        }

        $claimed = DB::transaction(function () use ($periodKey) {
            if (ReportRun::query()->where('type', 'weekly')->where('period_key', $periodKey)->exists()) {
                return false;
            }

            ReportRun::query()->create([
                'type' => 'weekly',
                'period_key' => $periodKey,
                'sent_at' => now(),
            ]);

            return true;
        });

        if (! $claimed) {
            return ['sent' => false, 'period' => $periodKey];
        }

        $this->telegramNotifier->send($this->buildText($start, $end));

        return ['sent' => true, 'period' => $periodKey];
    }

    public function buildText(Carbon $start, Carbon $end): string
    {
        $tz = JalaliCalendar::timezone();
        $startLocal = $start->copy()->timezone($tz);
        $endLocal = $end->copy()->timezone($tz);

        $commits = CommitScore::query()
            ->where('score', '>', 0)
            ->whereNotNull('committed_at')
            ->whereBetween('committed_at', [$startLocal->copy()->utc(), $endLocal->copy()->utc()])
            ->get();

        $tasks = TeamTask::query()
            ->where('is_code', false)
            ->where('score', '>', 0)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$startLocal->copy()->utc(), $endLocal->copy()->utc()])
            ->get();

        $lines = [
            '📊 گزارش هفتگی تیم',
            "از {$startLocal->format('Y-m-d')} تا {$endLocal->format('Y-m-d')} (وقت تهران)",
            '',
        ];

        $bestCommit = $commits->sortByDesc('score')->first();
        $bestTask = $tasks->sortByDesc('score')->first();

        $lines[] = '🏆 بهترین کامیت: '.($bestCommit
            ? number_format((int) $bestCommit->score).' — '.$this->firstLine((string) $bestCommit->message)
            : 'ثبت نشده');
        $lines[] = '🏅 بهترین تسک: '.($bestTask
            ? number_format((int) $bestTask->score).' — '.$bestTask->title.' ('.($bestTask->assignee_name ?: '@'.$bestTask->assignee_username).')'
            : 'ثبت نشده');
        $lines[] = '';
        $lines[] = 'امتیاز هر نفر:';

        $totals = [];

        foreach ($commits as $row) {
            $member = $this->identity->resolveGithubLogin($row->login);

            if ($member === null) {
                continue;
            }

            $totals[$member['key']] ??= ['name' => $member['display'], 'score' => 0];
            $totals[$member['key']]['score'] += (int) $row->score;
        }

        foreach ($tasks as $row) {
            $key = 'tg:'.$row->assignee_telegram_id;
            $totals[$key] ??= ['name' => (string) ($row->assignee_name ?: '@'.$row->assignee_username), 'score' => 0];
            $totals[$key]['score'] += (int) $row->score;
        }

        uasort($totals, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        if ($totals === []) {
            $lines[] = 'امتیازی ثبت نشده.';
        }

        $rank = 1;

        foreach ($totals as $person) {
            $lines[] = $rank.') '.$person['name'].' — '.number_format($person['score']);
            $rank++;
        }

        return implode("\n", $lines);
    }

    /**
     * Friday (Tehran) from 18:00 onward; the week ends on Friday.
     */
    private function isReportTime(Carbon $now): bool
    {
        return $now->dayOfWeek === Carbon::FRIDAY && $now->hour >= 18;
    }

    private function firstLine(string $message): string
    {
        $first = trim(explode("\n", $message, 2)[0] ?? '');

        return $first === '' ? '(بدون پیام)' : mb_substr($first, 0, 120);
    }
}
