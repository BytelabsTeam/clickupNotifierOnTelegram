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

    public function test_it_appends_score_when_missing_from_template(): void
    {
        config([
            'telegram.commit_message_template' => "{name} توسعه داد:\n{message}\n#{repo}",
        ]);

        $message = app(TelegramNotifier::class)->formatCommitMessage(
            'عارف',
            'رفع باگ لاگین',
            'MegaShop',
            1550
        );

        $this->assertSame(
            "عارف توسعه داد:\nرفع باگ لاگین\n#MegaShop\nامتیاز: 1550",
            $message
        );
    }

    public function test_it_uses_score_placeholder_in_template(): void
    {
        config([
            'telegram.commit_message_template' => "{name} توسعه داد:\n{message}\n#{repo}\nامتیاز: {score}",
        ]);

        $message = app(TelegramNotifier::class)->formatCommitMessage(
            'عارف',
            'رفع باگ لاگین',
            'MegaShop',
            900
        );

        $this->assertSame(
            "عارف توسعه داد:\nرفع باگ لاگین\n#MegaShop\nامتیاز: 900",
            $message
        );
    }
}
