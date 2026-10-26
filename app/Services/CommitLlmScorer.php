<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class CommitLlmScorer
{
    public function __construct(private readonly IranRouterClient $iranRouterClient)
    {
    }

    /**
     * @param  list<array{filename: string, additions: int, deletions: int}>  $files
     * @return array{score: int, reason: string}
     */
    public function score(string $repo, string $projectDoc, string $message, array $files): array
    {
        $diffSummary = $this->formatDiffSummary($files);

        $raw = $this->iranRouterClient->chat([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You score a single GitHub commit for developer leaderboard impact.',
                    'Return ONLY valid JSON: {"score": <int>, "reason": "<short Persian or English>"}',
                    'Score range: 0 to 5000 inclusive.',
                    'Be strict: full/near-full scores (4000-5000) only for exceptional, high-impact work (major features, hard architecture, critical fixes with broad impact).',
                    'Typical small fixes, typos, renames, minor refactors: 0-500.',
                    'Medium features or meaningful improvements: roughly 500-1500.',
                    'Large but not exceptional work: roughly 1500-3000.',
                    'Do not reward line count alone. Judge value against the project baseline document.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => implode("\n\n", [
                    "Repo: {$repo}",
                    "Project baseline:\n{$projectDoc}",
                    "Commit message:\n{$message}",
                    "Diff summary:\n{$diffSummary}",
                ]),
            ],
        ], 0.1, 400);

        return $this->parseScore($raw);
    }

    /**
     * @param  list<array{filename: string, additions: int, deletions: int}>  $files
     */
    private function formatDiffSummary(array $files): string
    {
        if ($files === []) {
            return '(no file stats)';
        }

        $lines = [];
        $shown = 0;

        foreach ($files as $file) {
            $filename = (string) ($file['filename'] ?? '');

            if ($filename === '') {
                continue;
            }

            $add = (int) ($file['additions'] ?? 0);
            $del = (int) ($file['deletions'] ?? 0);
            $lines[] = "+{$add} -{$del} {$filename}";
            $shown++;

            if ($shown >= 80) {
                $remaining = count($files) - $shown;
                if ($remaining > 0) {
                    $lines[] = "... and {$remaining} more files";
                }
                break;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{score: int, reason: string}
     */
    public function parseScore(string $raw): array
    {
        $json = $this->extractJson($raw);

        if ($json === null) {
            Log::warning('Commit LLM score parse failed', ['raw' => mb_substr($raw, 0, 400)]);

            return ['score' => 0, 'reason' => 'parse_failed'];
        }

        $score = $json['score'] ?? 0;

        if (! is_numeric($score)) {
            return ['score' => 0, 'reason' => 'invalid_score'];
        }

        $clamped = max(0, min(5000, (int) round((float) $score)));
        $reason = isset($json['reason']) && is_string($json['reason'])
            ? mb_substr(trim($json['reason']), 0, 500)
            : '';

        return [
            'score' => $clamped,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $matches) === 1) {
            $raw = $matches[1];
        } elseif (preg_match('/\{.*\}/s', $raw, $matches) === 1) {
            $raw = $matches[0];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
