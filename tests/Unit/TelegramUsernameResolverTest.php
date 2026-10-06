<?php

namespace Tests\Unit;

use App\Services\TelegramUsernameResolver;
use Tests\TestCase;

class TelegramUsernameResolverTest extends TestCase
{
    public function test_it_formats_a_bare_telegram_mention(): void
    {
        config([
            'telegram.usernames' => [
                'arefmohammad332@gmail.com' => 'MegaGeek',
            ],
        ]);

        $resolver = new TelegramUsernameResolver;

        $this->assertSame(
            '@MegaGeek',
            $resolver->formatMention('عارف', 'arefmohammad332@gmail.com')
        );
    }

    public function test_it_falls_back_to_display_name_when_username_is_missing(): void
    {
        config(['telegram.usernames' => []]);

        $resolver = new TelegramUsernameResolver;

        $this->assertSame('عارف', $resolver->formatMention('عارف', 'unknown@example.com'));
    }
}
