<?php

namespace Tests\Unit;

use App\Models\ProjectScoringDoc;
use App\Services\GitHubClient;
use App\Services\IranRouterClient;
use App\Services\ProjectScoringDocService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProjectScoringDocServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_existing_doc_without_calling_llm(): void
    {
        ProjectScoringDoc::query()->create([
            'repo' => 'acme/app',
            'content' => 'Existing baseline',
            'generated_at' => now(),
        ]);

        $gitHub = Mockery::mock(GitHubClient::class);
        $gitHub->shouldReceive('parseRepo')
            ->andReturn(['owner' => 'acme', 'name' => 'app']);
        $gitHub->shouldReceive('fetchRepoContext')->never();

        $iran = Mockery::mock(IranRouterClient::class);
        $iran->shouldReceive('chat')->never();

        $service = new ProjectScoringDocService($gitHub, $iran);
        $doc = $service->ensure('acme/app');

        $this->assertSame('Existing baseline', $doc->content);
        $this->assertSame(1, ProjectScoringDoc::query()->count());
    }

    public function test_it_generates_and_stores_doc_once(): void
    {
        $gitHub = Mockery::mock(GitHubClient::class);
        $gitHub->shouldReceive('parseRepo')
            ->andReturn(['owner' => 'acme', 'name' => 'app']);
        $gitHub->shouldReceive('fetchRepoContext')
            ->once()
            ->with('acme', 'app')
            ->andReturn("# App\nA demo project");

        $iran = Mockery::mock(IranRouterClient::class);
        $iran->shouldReceive('chat')
            ->once()
            ->andReturn('این پروژه فروشگاهی است.');

        $service = new ProjectScoringDocService($gitHub, $iran);
        $doc = $service->ensure('acme/app');

        $this->assertSame('این پروژه فروشگاهی است.', $doc->content);
        $this->assertDatabaseHas('project_scoring_docs', [
            'repo' => 'acme/app',
        ]);
    }
}
