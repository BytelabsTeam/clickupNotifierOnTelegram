<?php

namespace App\Services;

use App\Models\CommitScore;
use App\Models\TaskScore;
use App\Support\JalaliCalendar;
use Carbon\Carbon;

class ScoreBreakdownService
{
    private const MAX_ITEMS = 40;

    public function __construct(
        private readonly ScoreIdentityResolver $scoreIdentityResolver,
        private readonly UserNameResolver $userNameResolver,
        private readonly TelegramUsernameResolver $telegramUsernameResolver,
    ) {
    }

    public function formatDay(): string
    {
        $timezone = $this->timezone();
        $from = Carbon::now($timezone)->startOfDay();
        $to = Carbon::now($timezone)->endOfDay();

        return $this->format($from, $to, 'امتیازهای امروز');
    }

    public function formatWeek(): string
    {
        $timezone = $this->timezone();
        $from = Carbon::now($timezone)->startOfWeek(Carbon::SATURDAY);
        $to = $from->copy()->addWeek()->subSecond();

        return $this->format($from, $to, 'امتیازهای این هفته');
    }

    public function formatMonth(): string
    {
        $month = JalaliCalendar::currentMonth();

        return $this->format($month['from'], $month['to'], 'امتیازهای این ماه');
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function view(string $period): array
    {
        $text = match ($period) {
            'week' => $this->formatWeek(),
            'month' => $this->formatMonth(),
            default => $this->formatDay(),
        };

        return [
            'text' => $text,
            'keyboard' => [
                [
                    ['text' => 'امروز', 'callback_data' => 'tm:scores:day'],
                    ['text' => 'هفته', 'callback_data' => 'tm:scores:week'],
                    ['text' => 'ماه', 'callback_data' => 'tm:scores:month'],
                ],
                [
                    ['text' => 'منوی اصلی', 'callback_data' => 'tm:home'],
                ],
            ],
        ];
    }

    private function format(Carbon $from, Carbon $to, string $title): string
    {
        $month = JalaliCalendar::monthKey(Carbon::now($this->timezone()));
        $fromUtc = $from->copy()->utc();
        $toUtc = $to->copy()->utc();

        $commits = CommitScore::query()
            ->where('month', $month)
            ->where('score', '>', 0)
            ->whereNotNull('committed_at')
            ->where('committed_at', '>=', $fromUtc)
            ->where('committed_at', '<=', $toUtc)
            ->orderByDesc('score')
            ->orderByDesc('committed_at')
            ->get();

        $tasks = TaskScore::query()
            ->where('month', $month)
            ->where('is_code', false)
            ->where('score', '>', 0)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $fromUtc)
            ->where('completed_at', '<=', $toUtc)
            ->orderByDesc('score')
            ->orderByDesc('completed_at')
            ->get();

        if ($commits->isEmpty() && $tasks->isEmpty()) {
            return $title.":\nدر این بازه امتیازی ثبت نشده.";
        }

        $lines = [$title.':', ''];
        $total = 0;
        $shown = 0;
        $hidden = 0;

        if ($commits->isNotEmpty()) {
            $lines[] = 'کامیت‌ها ('. $commits->count().'):';

            foreach ($commits as $row) {
                $score = (int) $row->score;
                $total += $score;

                if ($shown >= self::MAX_ITEMS) {
                    $hidden++;

                    continue;
                }

                $who = $this->displayName(
                    $this->scoreIdentityResolver->resolve($row->login, $row->email, $row->name)
                );
                $message = $this->firstLine((string) $row->message);
                $repo = trim((string) $row->repo);
                $repoTag = $repo !== '' ? ' #'.$this->repoShort($repo) : '';

                $lines[] = '• '.$who.' — '.$message.' — '.number_format($score).$repoTag;
                $shown++;
            }

            $lines[] = '';
        }

        if ($tasks->isNotEmpty()) {
            $lines[] = 'تسک‌ها ('.$tasks->count().'):';

            foreach ($tasks as $row) {
                $score = (int) $row->score;
                $total += $score;

                if ($shown >= self::MAX_ITEMS) {
                    $hidden++;

                    continue;
                }

                $who = $this->displayName(
                    $this->scoreIdentityResolver->resolve(null, $row->email, $row->username ?? $row->name)
                );
                $taskName = trim((string) $row->task_name);

                if ($taskName === '') {
                    $taskName = (string) $row->task_id;
                }

                $lines[] = '• '.$who.' — '.$taskName.' — '.number_format($score);
                $shown++;
            }

            $lines[] = '';
        }

        if ($hidden > 0) {
            $lines[] = '… و '.$hidden.' مورد دیگر (فقط '.$shown.' مورد اول نشان داده شد)';
            $lines[] = '';
        }

        $lines[] = 'جمع کل: '.number_format($total).' امتیاز';

        return implode("\n", $lines);
    }

    /**
     * @param  array{key: string, email: ?string, username: ?string}  $identity
     */
    private function displayName(array $identity): string
    {
        return $this->telegramUsernameResolver->formatDisplayName(
            $this->userNameResolver->resolve($identity['email'], $identity['username']),
            $identity['email']
        );
    }

    private function firstLine(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return '(بدون پیام)';
        }

        $line = preg_split('/\R/u', $message)[0] ?? $message;
        $line = trim((string) $line);

        if (mb_strlen($line) > 80) {
            return mb_substr($line, 0, 79).'…';
        }

        return $line !== '' ? $line : '(بدون پیام)';
    }

    private function repoShort(string $repo): string
    {
        $repo = str_replace(['https://github.com/', 'http://github.com/'], '', $repo);
        $parts = explode('/', trim($repo, '/'));

        return $parts[count($parts) - 1] ?: $repo;
    }

    private function timezone(): string
    {
        return JalaliCalendar::timezone();
    }
}
