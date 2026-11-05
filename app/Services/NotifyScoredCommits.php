<?php

namespace App\Services;

use App\Models\CommitScore;
use Carbon\Carbon;

/**
 * Sends commit notifications only after the commit has a stored score.
 * Each commit is announced once (notified_at); unknown authors are skipped.
 */
class NotifyScoredCommits
{
    public function __construct(
        private readonly TeamIdentityService $identity,
        private readonly CommitMessageTranslator $translator,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    public function notify(int $limit = 50): int
    {
        $rows = CommitScore::query()
            ->whereNull('notified_at')
            ->where('score', '>', 0)
            ->orderBy('committed_at')
            ->limit($limit)
            ->get();

        $sent = 0;

        foreach ($rows as $row) {
            $member = $this->identity->resolveGithubLogin($row->login);

            if ($member === null) {
                // Never attribute a commit to a guessed person; mark it so it is not retried forever.
                $row->forceFill(['notified_at' => Carbon::now('UTC')])->save();

                continue;
            }

            $translated = $this->translator->toPersian((string) $row->message);
            $repoName = str_contains($row->repo, '/') ? substr($row->repo, strrpos($row->repo, '/') + 1) : $row->repo;

            $text = $this->telegramNotifier->formatCommitMessage($member['display'], $translated, $repoName)
                ."\n\n⭐ امتیاز: ".number_format((int) $row->score)
                .($row->reason ? "\nدلیل: {$row->reason}" : '');

            $this->telegramNotifier->send($text);

            $row->forceFill(['notified_at' => Carbon::now('UTC')])->save();
            $sent++;
        }

        return $sent;
    }
}
