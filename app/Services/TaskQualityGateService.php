<?php

namespace App\Services;

use App\Support\TaskCallbackRef;
use Illuminate\Support\Facades\Cache;

class TaskQualityGateService
{
    public const CALLBACK_PREFIX = 'qg:';

    public function __construct(
        private readonly ClickUpClient $clickUpClient,
        private readonly TelegramNotifier $telegramNotifier,
        private readonly TaskScoreSyncService $taskScoreSyncService,
        private readonly UserNameResolver $userNameResolver,
    ) {
    }

    public function isQualityCallback(string $data): bool
    {
        return str_starts_with($data, self::CALLBACK_PREFIX);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{pass: bool, reasons: list<string>}
     */
    public function evaluate(array $task): array
    {
        $minDesc = max(0, (int) config('clickup.quality_min_desc', 20));
        $reasons = [];
        $description = $this->plainDescription($task);

        if ($minDesc > 0 && mb_strlen($description) < $minDesc) {
            $reasons[] = "توضیح کوتاه‌تر از {$minDesc} کاراکتر است";
        }

        $hasLink = $this->hasUsefulLink($task, $description);
        $checklistOk = $this->checklistComplete($task);

        if (! $hasLink && ! $checklistOk) {
            $reasons[] = 'لینک PR/URL یا چک‌لیست کامل پیدا نشد';
        }

        return [
            'pass' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{sent: bool, gated: bool}
     */
    public function notifyOrGate(
        array $task,
        ?string $email,
        ?string $username,
        bool $forceCelebrate = false,
    ): array {
        $taskId = (string) ($task['id'] ?? '');

        if ($taskId === '') {
            return ['sent' => false, 'gated' => false];
        }

        $overrideKey = 'qg:override:'.$taskId;

        if (! $forceCelebrate && ! Cache::get($overrideKey)) {
            $result = $this->evaluate($task);

            if (! $result['pass']) {
                $this->sendGateMessage($task, $result['reasons'], $email, $username);

                return ['sent' => true, 'gated' => true];
            }
        }

        $this->celebrate($task, $email, $username);

        return ['sent' => true, 'gated' => false];
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>, toast?: string, alert?: bool}
     */
    public function handleCallback(string $data, array $from): array
    {
        if (preg_match('/^qg:(review|force):([a-f0-9]{8})$/', $data, $matches) !== 1) {
            return [
                'text' => 'دکمه نامعتبر.',
                'keyboard' => [[['text' => 'منو', 'callback_data' => 'tm:home']]],
                'toast' => 'نامعتبر',
                'alert' => true,
            ];
        }

        $action = $matches[1];
        $ref = $matches[2];
        $taskId = TaskCallbackRef::resolve($ref);

        if ($taskId === null) {
            return [
                'text' => 'این دکمه منقضی شده.',
                'keyboard' => [[['text' => 'منو', 'callback_data' => 'tm:home']]],
                'toast' => 'منقضی',
                'alert' => true,
            ];
        }

        $task = $this->clickUpClient->getTask($taskId);
        $actor = $this->userNameResolver->resolve(
            $this->emailForUsername((string) ($from['username'] ?? '')),
            (string) ($from['username'] ?? '')
        );

        if ($action === 'review') {
            $listId = is_array($task['list'] ?? null) ? (string) ($task['list']['id'] ?? '') : (string) config('clickup.list_id', '');
            $status = $listId !== '' ? $this->clickUpClient->resolveReviewStatus($listId) : (string) config('clickup.review_status', 'review');
            $task = $this->clickUpClient->updateTaskStatus($taskId, $status);
            $this->clickUpClient->addTaskComment($taskId, "{$actor} تسک را از گیت کیفیت تلگرام به {$status} برگرداند.");

            return [
                'text' => "↩️ برگشت به «{$status}»\n".trim((string) ($task['name'] ?? $taskId))."\nتوسط {$actor}",
                'keyboard' => [[['text' => 'منو', 'callback_data' => 'tm:home']]],
                'toast' => 'برگشت به Review',
            ];
        }

        Cache::put('qg:override:'.$taskId, true, now()->addDay());
        [$email, $username] = $this->resolveTaskUser($task);
        $this->celebrate($task, $email, $username);

        return [
            'text' => '✅ با تأیید دستی اعلام Done شد.'."\n".trim((string) ($task['name'] ?? $taskId)),
            'keyboard' => [[['text' => 'منو', 'callback_data' => 'tm:home']]],
            'toast' => 'Done اعلام شد',
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  list<string>  $reasons
     */
    private function sendGateMessage(array $task, array $reasons, ?string $email, ?string $username): void
    {
        $taskId = (string) ($task['id'] ?? '');
        $ref = TaskCallbackRef::remember($taskId);
        $name = trim((string) ($task['name'] ?? $taskId));
        $display = $this->userNameResolver->resolve($email, $username);
        $lines = [
            '⚠️ گیت کیفیت Done',
            $name,
            $display,
            '',
            'قبل از جشن Done این‌ها کم است:',
        ];

        foreach ($reasons as $reason) {
            $lines[] = '• '.$reason;
        }

        $this->telegramNotifier->sendWithInlineKeyboard(implode("\n", $lines), [
            [
                ['text' => 'برگردون به Review', 'callback_data' => 'qg:review:'.$ref],
            ],
            [
                ['text' => 'بازم Done اعلام کن', 'callback_data' => 'qg:force:'.$ref],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function celebrate(array $task, ?string $email, ?string $username): void
    {
        $taskId = (string) ($task['id'] ?? '');
        $displayName = $this->userNameResolver->resolve($email, $username);
        $taskName = (string) ($task['name'] ?? $taskId);
        $projectTag = $this->clickUpClient->resolveProjectTag($task);
        $message = $this->telegramNotifier->formatMessage($displayName, $taskName, $projectTag);
        $media = $this->clickUpClient->extractMediaAttachments($task['attachments'] ?? []);
        $this->telegramNotifier->send($message, $media);

        try {
            $score = $this->taskScoreSyncService->scoreTaskIfNeeded($task, $email, $username);

            if ($score !== null && (int) $score->score > 0) {
                app(StreakCompetitionService::class)->maybeAnnounceOvertake($email, $username);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function plainDescription(array $task): string
    {
        $text = (string) ($task['description'] ?? $task['text_content'] ?? '');
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function hasUsefulLink(array $task, string $description): bool
    {
        if (preg_match('#https?://\S+#i', $description) === 1) {
            return true;
        }

        foreach ($task['attachments'] ?? [] as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $url = (string) ($attachment['url'] ?? $attachment['url_w_host'] ?? '');

            if ($url !== '' && preg_match('#https?://#i', $url) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function checklistComplete(array $task): bool
    {
        $checklists = $task['checklists'] ?? null;

        if (! is_array($checklists) || $checklists === []) {
            return false;
        }

        $items = 0;
        $resolved = 0;

        foreach ($checklists as $checklist) {
            if (! is_array($checklist)) {
                continue;
            }

            foreach ($checklist['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $items++;

                if (! empty($item['resolved'])) {
                    $resolved++;
                }
            }
        }

        return $items > 0 && $resolved === $items;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveTaskUser(array $task): array
    {
        $assignees = $task['assignees'] ?? [];

        if (is_array($assignees) && isset($assignees[0]) && is_array($assignees[0])) {
            return [
                isset($assignees[0]['email']) ? (string) $assignees[0]['email'] : null,
                isset($assignees[0]['username']) ? (string) $assignees[0]['username'] : null,
            ];
        }

        return [null, null];
    }

    private function emailForUsername(string $username): ?string
    {
        $needle = mb_strtolower(ltrim(trim($username), '@'));

        if ($needle === '') {
            return null;
        }

        foreach (config('telegram.usernames', []) as $email => $mapped) {
            if (mb_strtolower(ltrim(trim((string) $mapped), '@')) === $needle) {
                return (string) $email;
            }
        }

        return null;
    }
}
