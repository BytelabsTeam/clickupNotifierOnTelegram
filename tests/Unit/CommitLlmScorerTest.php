<?php

namespace Tests\Unit;

use App\Services\CommitLlmScorer;
use App\Services\IranRouterClient;
use Mockery;
use Tests\TestCase;

class CommitLlmScorerTest extends TestCase
{
    public function test_it_parses_and_clamps_scores(): void
    {
        $scorer = app(CommitLlmScorer::class);

        $this->assertSame(3000, $scorer->parseScore('{"score": 9000, "reason": "huge"}')['score']);
        $this->assertSame(0, $scorer->parseScore('{"score": -10, "reason": "bad"}')['score']);
        $this->assertSame(1200, $scorer->parseScore("```json\n{\"score\": 1200, \"reason\": \"ok\"}\n```")['score']);
        $this->assertSame(0, $scorer->parseScore('not json')['score']);
    }

    public function test_it_scores_via_iranrouter(): void
    {
        $client = Mockery::mock(IranRouterClient::class);
        $client->shouldReceive('chat')
            ->once()
            ->andReturn('{"score": 750, "reason": "medium feature"}');

        $scorer = new CommitLlmScorer($client);

        $result = $scorer->score(
            'acme/app',
            'Baseline doc',
            'feat: checkout',
            [['filename' => 'app/Checkout.php', 'additions' => 40, 'deletions' => 2]],
        );

        $this->assertSame(750, $result['score']);
        $this->assertSame('medium feature', $result['reason']);
    }
}
