<?php

namespace Tests\Unit;

use App\Services\IranRouterClient;
use App\Services\TaskLlmScorer;
use Mockery;
use Tests\TestCase;

class TaskLlmScorerTest extends TestCase
{
    public function test_it_zeros_score_for_code_tasks(): void
    {
        $scorer = app(TaskLlmScorer::class);

        $result = $scorer->parse('{"is_code": true, "score": 4000, "reason": "bugfix"}');

        $this->assertTrue($result['is_code']);
        $this->assertSame(0, $result['score']);
    }

    public function test_it_clamps_non_code_scores(): void
    {
        $scorer = app(TaskLlmScorer::class);

        $result = $scorer->parse('{"is_code": false, "score": 9000, "reason": "huge"}');

        $this->assertFalse($result['is_code']);
        $this->assertSame(5000, $result['score']);
    }

    public function test_it_scores_via_iranrouter(): void
    {
        $client = Mockery::mock(IranRouterClient::class);
        $client->shouldReceive('chat')
            ->once()
            ->andReturn('{"is_code": false, "score": 800, "reason": "design"}');

        $scorer = new TaskLlmScorer($client);
        $result = $scorer->score('طراحی بنر کمپین', 'بنر اینستاگرام');

        $this->assertFalse($result['is_code']);
        $this->assertSame(800, $result['score']);
    }
}
