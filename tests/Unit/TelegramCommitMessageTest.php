<?php

namespace Tests\Unit;

use App\Services\TelegramNotifier;
use Tests\TestCase;

class TelegramCommitMessageTest extends TestCase
{
    public function test_it_formats_commit_message(): void
    {
        config([
            'telegram.commit_message_template' => "{name} توسعه داد:\n{message}\n#{repo}",
        ]);

        $message = app(TelegramNotifier::class)->formatCommitMessage(
            'عارف',
            'رفع باگ لاگین',
            'MegaShop'
        );

        $this->assertSame("عارف توسعه داد:\nرفع باگ لاگین\n#MegaShop", $message);
    }
}
