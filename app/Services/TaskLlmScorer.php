<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TaskLlmScorer
{
    public function __construct(private readonly IranRouterClient $iranRouterClient)
    {
    }

    /**
     * @return array{is_code: bool, score: int, reason: string}
     */
    public function score(string $taskName, string $description = '', string $projectContext = ''): array
    {
        $raw = $this->iranRouterClient->chat([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You score a completed ClickUp task for a team leaderboard.',
                    'Return ONLY valid JSON: {"is_code": <bool>, "score": <int>, "reason": "<short Persian or English>"}',
                    'is_code=true when the task is software/coding work that is typically reflected in Git commits',
                    '(implement feature, fix bug in code, refactor, API, PR, deploy code, write scripts, etc.).',
                    'For is_code=true always set score to 0 (GitHub already scores that work).',
                    'is_code=false for non-coding work: design, content, research, meetings, coordination,',
                    'manual QA without code changes, marketing, support, docs that are not code, ops tickets, etc.',
                    'For non-code tasks, score from 0 to 5000 inclusive.',
                    'Be strict: near-full scores (4000-5000) only for exceptional high-impact non-code work.',
                    'Routine/small non-code tasks: 0-500. Medium: roughly 500-1500. Large but not exceptional: 1500-3000.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => implode("\n\n", array_filter([
                    "Task name:\n{$taskName}",
                    $description !== '' ? "Description:\n{$description}" : null,
                    $projectContext !== '' ? "Project context:\n{$projectContext}" : null,
                ])),
            ],
        ], 400);

        return $this->parse($raw);
    }

    /**
     * @return array{is_code: bool, score: int, reason: string}
     */
    public function parse(string $raw): array
    {
        $json = $this->extractJson($raw);

        if ($json === null) {
            Log::warning('Task LLM score parse failed', ['raw' => mb_substr($raw, 0, 400)]);

            return ['is_code' => false, 'score' => 0, 'reason' => 'parse_failed'];
        }

        $isCode = filter_var($json['is_code'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $score = $json['score'] ?? 0;

        if (! is_numeric($score)) {
            $score = 0;
        }

        $clamped = max(0, min(5000, (int) round((float) $score)));

        if ($isCode) {
            $clamped = 0;
        }

        $reason = isset($json['reason']) && is_string($json['reason'])
            ? mb_substr(trim($json['reason']), 0, 500)
            : '';

        return [
            'is_code' => $isCode,
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
