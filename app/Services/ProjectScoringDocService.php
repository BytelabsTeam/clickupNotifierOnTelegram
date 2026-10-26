<?php

namespace App\Services;

use App\Models\ProjectScoringDoc;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProjectScoringDocService
{
    public function __construct(
        private readonly GitHubClient $gitHubClient,
        private readonly IranRouterClient $iranRouterClient,
    ) {
    }

    public function ensure(string $repo): ProjectScoringDoc
    {
        $repo = $this->normalizeRepo($repo);

        $existing = ProjectScoringDoc::query()->where('repo', $repo)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->generateAndStore($repo);
    }

    public function regenerate(string $repo): ProjectScoringDoc
    {
        $repo = $this->normalizeRepo($repo);

        ProjectScoringDoc::query()->where('repo', $repo)->delete();

        return $this->generateAndStore($repo);
    }

    private function generateAndStore(string $repo): ProjectScoringDoc
    {
        $parsed = $this->gitHubClient->parseRepo($repo);

        if ($parsed === null) {
            throw new RuntimeException("Invalid repo slug: {$repo}");
        }

        $context = $this->gitHubClient->fetchRepoContext($parsed['owner'], $parsed['name']);

        if (trim($context) === '') {
            $context = "Repository: {$repo}";
        }

        $content = $this->iranRouterClient->chat([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You write a short Persian baseline document for scoring GitHub commits of one software project.',
                    'Include: project purpose, tech stack, what kinds of changes are valuable, and what is routine/low-value.',
                    'Keep it under 800 words. Plain text only. No markdown fences.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => "Repo: {$repo}\n\nSource material:\n{$context}",
            ],
        ], 1200);

        if ($content === '') {
            throw new RuntimeException("Failed to generate scoring doc for {$repo}");
        }

        Log::info('Generated project scoring doc', ['repo' => $repo]);

        return ProjectScoringDoc::query()->create([
            'repo' => $repo,
            'content' => $content,
            'generated_at' => now(),
        ]);
    }

    private function normalizeRepo(string $repo): string
    {
        $parsed = $this->gitHubClient->parseRepo($repo);

        if ($parsed === null) {
            return trim($repo);
        }

        return $parsed['owner'].'/'.$parsed['name'];
    }
}
