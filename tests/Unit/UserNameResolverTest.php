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
                'arefmohaamd332@gmail.com' => 'عارف',
            ],
            'scoring.email_aliases' => [],
        ]);

        $resolver = app(UserNameResolver::class);

        $this->assertSame(
            'عارف',
            $resolver->resolve('arefmohaamd332@gmail.com', 'Aref ClickUp')
        );
    }

    public function test_it_resolves_name_via_email_alias(): void
    {
        config([
            'clickup.user_names' => [
                'hassan.ah1381@gmail.com' => 'حسن',
            ],
            'scoring.email_aliases' => [
                'hassan.other@gmail.com' => 'hassan.ah1381@gmail.com',
            ],
        ]);

        $resolver = app(UserNameResolver::class);

        $this->assertSame('حسن', $resolver->resolve('hassan.other@gmail.com', 'x'));
    }

    public function test_it_falls_back_to_clickup_username_when_email_is_not_mapped(): void
    {
        config([
            'clickup.user_names' => [],
            'scoring.email_aliases' => [],
        ]);

        $resolver = app(UserNameResolver::class);

        $this->assertSame('Aref ClickUp', $resolver->resolve('unknown@example.com', 'Aref ClickUp'));
    }

    public function test_it_falls_back_to_clickup_username_when_email_is_missing(): void
    {
        config([
            'clickup.user_names' => [],
            'scoring.email_aliases' => [],
        ]);

        $resolver = app(UserNameResolver::class);

        $this->assertSame('Aref ClickUp', $resolver->resolve(null, 'Aref ClickUp'));
    }

    public function test_it_returns_default_label_when_no_mapping_or_username_exists(): void
    {
        config([
            'clickup.user_names' => [],
            'scoring.email_aliases' => [],
        ]);

        $resolver = app(UserNameResolver::class);

        $this->assertSame('کاربر ناشناس', $resolver->resolve(null, null));
    }
}
