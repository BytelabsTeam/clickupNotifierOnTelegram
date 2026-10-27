<?php

namespace App\Console\Commands;

use App\Services\NotifyMorningCheckIn;
use Illuminate\Console\Command;

class NotifyMorningCheckInCommand extends Command
{
    protected $signature = 'telegram:morning-checkin';

    protected $description = 'Send the daily morning greeting with start-work button to the Telegram group';

    public function handle(NotifyMorningCheckIn $notifier): int
    {
        $result = $notifier->notify();

        if (! $result['sent']) {
            $this->warn('Morning check-in message was not sent (no message_id).');

            return self::FAILURE;
        }

        $this->info("Sent morning check-in for {$result['work_date']} (message_id={$result['message_id']}, mentions={$result['mentions']}).");

        return self::SUCCESS;
    }
}
