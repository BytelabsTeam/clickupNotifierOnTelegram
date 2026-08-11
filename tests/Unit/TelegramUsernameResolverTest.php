<?php

namespace Tests\Unit;

use App\Services\TelegramUsernameResolver;
use Tests\TestCase;

class TelegramUsernameResolverTest extends TestCase
{
    public function test_it_resolves_username_from_email_mapping(): void
    {
        config([
            'telegram.usernames' => [
                'user@example.com' => 'aref_telegram',
            ],
        ]);

        $resolver = new TelegramUsernameResolver;

        $this->assertSame('aref_telegram', $resolver->resolve('user@example.com'));
    }

    public function test_it_strips_leading_at_sign(): void
    {
        config([
            'telegram.usernames' => [
                'user@example.com' => '@aref_telegram',
            ],
        ]);

        $resolver = new TelegramUsernameResolver;

        $this->assertSame('aref_telegram', $resolver->resolve('user@example.com'));
    }

    public function test_it_formats_display_name_with_mention(): void
    {
        config([
            'telegram.usernames' => [
                'user@example.com' => 'aref_telegram',
            ],
        ]);

        $resolver = new TelegramUsernameResolver;

        $this->assertSame(
            'عارف (@aref_telegram)',
            $resolver->formatDisplayName('عارف', 'user@example.com')
        );
    }

    public function test_it_keeps_display_name_when_username_is_missing(): void
    {
        config(['telegram.usernames' => []]);

        $resolver = new TelegramUsernameResolver;

        $this->assertSame('عارف', $resolver->formatDisplayName('عارف', 'user@example.com'));
    }
}
