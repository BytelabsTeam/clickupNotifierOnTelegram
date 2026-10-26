<?php

namespace App\Console\Commands;

use App\Services\ProjectScoringDocService;
use Illuminate\Console\Command;

class RegenerateScoringDoc extends Command
{
    protected $signature = 'github:regenerate-scoring-doc {repo : owner/name}';

    protected $description = 'Regenerate the LLM baseline scoring document for a repository';

    public function handle(ProjectScoringDocService $docs): int
    {
        $repo = (string) $this->argument('repo');

        try {
            $doc = $docs->regenerate($repo);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Scoring doc regenerated for {$doc->repo} (".mb_strlen($doc->content).' chars).');

        return self::SUCCESS;
    }
}
