<?php

namespace Tests\Unit;

use App\Services\UserNameResolver;
use Tests\TestCase;

class UserNameResolverTest extends TestCase
{
    public function test_it_returns_persian_name_when_email_is_mapped(): void
    {
        config([
            'clickup.user_names' => [
                'user@example.com' => 'عارف',
            ],
        ]);

        $resolver = new UserNameResolver;

        $this->assertSame(
            'عارف',
            $resolver->resolve('user@example.com', 'Aref ClickUp')
        );
    }

    public function test_it_falls_back_to_clickup_username_when_email_is_not_mapped(): void
    {
        config(['clickup.user_names' => []]);

        $resolver = new UserNameResolver;

        $this->assertSame('Aref ClickUp', $resolver->resolve('unknown@example.com', 'Aref ClickUp'));
    }

    public function test_it_falls_back_to_clickup_username_when_email_is_missing(): void
    {
        config(['clickup.user_names' => []]);

        $resolver = new UserNameResolver;

        $this->assertSame('Aref ClickUp', $resolver->resolve(null, 'Aref ClickUp'));
    }

    public function test_it_returns_default_label_when_no_mapping_or_username_exists(): void
    {
        config(['clickup.user_names' => []]);

        $resolver = new UserNameResolver;

        $this->assertSame('کاربر ناشناس', $resolver->resolve(null, null));
    }
}
