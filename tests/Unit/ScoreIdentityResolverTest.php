<?php

namespace Tests\Unit;

use App\Services\ScoreIdentityResolver;
use Tests\TestCase;

class ScoreIdentityResolverTest extends TestCase
{
    public function test_it_merges_aliased_emails_to_same_key(): void
    {
        config([
            'scoring.email_aliases' => [
                'hassan.github@gmail.com' => 'hassan.ah1381@gmail.com',
            ],
            'github.user_logins' => [
                'hassan.github@gmail.com' => 'mahdinasiri13861386',
            ],
            'clickup.user_names' => [
                'hassan.ah1381@gmail.com' => 'حسن',
            ],
        ]);

        $resolver = app(ScoreIdentityResolver::class);

        $fromGithub = $resolver->resolve('mahdinasiri13861386', 'hassan.github@gmail.com', 'Hassan');
        $fromClickUp = $resolver->resolve(null, 'hassan.ah1381@gmail.com', 'Hassan');

        $this->assertSame($fromGithub['key'], $fromClickUp['key']);
        $this->assertSame('hassan.ah1381@gmail.com', $fromGithub['email']);
        $this->assertSame('hassan.ah1381@gmail.com', $fromClickUp['email']);
    }
}
