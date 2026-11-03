<?php

namespace App\Console\Commands;

use App\Services\NotifyDailyHighlight;
use Illuminate\Console\Command;

class NotifyDailyHighlightCommand extends Command
{
    protected $signature = 'telegram:daily-highlight';

    protected $description = 'Send today’s highest-scored work highlight to the Telegram group';

    public function handle(NotifyDailyHighlight $notifier): int
    {
        try {
            $result = $notifier->notify();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['skipped']) {
            $this->warn('Daily highlight skipped (already sent or no scores).');

            return self::SUCCESS;
        }

        $this->info('Daily highlight sent for '.$result['work_date']);

        return self::SUCCESS;
    }
}
