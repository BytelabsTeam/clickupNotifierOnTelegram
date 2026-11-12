<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

class SelfDeployService
{
    private const WORK_DIR = 'app/deploy';

    private const LOCK_FILE = 'deploy.lock';

    public function __construct(
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    public function isAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        /** @var list<int> $ids */
        $ids = array_map('intval', config('deploy.admin_ids', []));

        return in_array($userId, $ids, true);
    }

    public function isLocked(): bool
    {
        $lockPath = $this->lockPath();

        if (! is_file($lockPath)) {
            return false;
        }

        $handle = @fopen($lockPath, 'c+');

        if ($handle === false) {
            return true;
        }

        $locked = ! flock($handle, LOCK_EX | LOCK_NB);

        if (! $locked) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return $locked;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function run(): array
    {
        @set_time_limit(300);
        ignore_user_abort(true);

        $lockHandle = $this->acquireLock();

        if ($lockHandle === null) {
            return [
                'ok' => false,
                'message' => 'آپدیت دیگری در جریانه. کمی بعد دوباره /deploy بزن.',
            ];
        }

        $workRoot = storage_path(self::WORK_DIR);
        $zipPath = $workRoot.'/update.zip';
        $extractPath = $workRoot.'/extract';

        try {
            File::ensureDirectoryExists($workRoot);
            $this->cleanPath($extractPath);
            File::ensureDirectoryExists($extractPath);

            if (is_file($zipPath)) {
                File::delete($zipPath);
            }

            $repo = trim((string) config('deploy.github_repo', 'BytelabsTeam/clickupNotifierOnTelegram'));
            $branch = trim((string) config('deploy.github_branch', 'main'));

            if ($repo === '' || ! str_contains($repo, '/')) {
                throw new RuntimeException('DEPLOY_GITHUB_REPO نامعتبر است.');
            }

            if ($branch === '') {
                throw new RuntimeException('DEPLOY_GITHUB_BRANCH خالی است.');
            }

            $this->downloadZipball($repo, $branch, $zipPath);
            $sourceRoot = $this->extractZipball($zipPath, $extractPath);
            $copied = $this->overlayFromDirectory($sourceRoot, base_path());

            $composerStatus = $this->maybeComposerInstall();
            $migrateStatus = $this->runArtisan('migrate', ['--force' => true]);
            $clearStatus = $this->runArtisan('optimize:clear');
            $webhookStatus = $this->reregisterWebhook();

            $lines = [
                '✅ آپدیت انجام شد.',
                '',
                "ریپو: {$repo}",
                "شاخه: {$branch}",
                "فایل‌های کپی‌شده: {$copied}",
                "Composer: {$composerStatus}",
                "Migrate: {$migrateStatus}",
                "Cache clear: {$clearStatus}",
                "Webhook: {$webhookStatus}",
            ];

            return [
                'ok' => true,
                'message' => implode("\n", $lines),
            ];
        } catch (Throwable $exception) {
            report($exception);
            Log::error('Self-deploy failed: '.$exception->getMessage());

            return [
                'ok' => false,
                'message' => '❌ آپدیت ناموفق بود: '.$this->publicError($exception),
            ];
        } finally {
            $this->cleanupWorkFiles($zipPath, $extractPath);
            $this->releaseLock($lockHandle);
        }
    }

    public function reportResult(string|int $chatId, ?int $progressMessageId, array $result): void
    {
        $message = (string) ($result['message'] ?? 'نتیجه نامشخص');

        try {
            if ($progressMessageId !== null) {
                $this->telegramNotifier->edit($chatId, $progressMessageId, $message);

                return;
            }

            $this->telegramNotifier->reply($chatId, $message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Copy files from an extracted release directory onto the app root.
     * Protected paths (.env, storage, vendor, …) are never overwritten.
     *
     * @return int Number of files copied
     */
    public function overlayFromDirectory(string $sourceRoot, string $targetRoot): int
    {
        $sourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);
        $targetRoot = rtrim($targetRoot, DIRECTORY_SEPARATOR);

        if (! is_dir($sourceRoot) || ! is_dir($targetRoot)) {
            throw new RuntimeException('مسیر منبع یا مقصد overlay معتبر نیست.');
        }

        $copied = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $absolute = $item->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($sourceRoot))), '/');

            if ($relative === '' || $this->isProtectedRelativePath($relative)) {
                continue;
            }

            $destination = $targetRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if ($item->isDir()) {
                File::ensureDirectoryExists($destination);

                continue;
            }

            File::ensureDirectoryExists(dirname($destination));

            if (! @copy($absolute, $destination)) {
                throw new RuntimeException("کپی فایل ناموفق بود: {$relative}");
            }

            $copied++;
        }

        return $copied;
    }

    public function isProtectedRelativePath(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '') {
            return true;
        }

        if ($relative === '.env' || str_starts_with($relative, '.env.')) {
            return true;
        }

        if (preg_match('#^database/.*\.sqlite$#i', $relative) === 1) {
            return true;
        }

        foreach (['storage', 'vendor', 'node_modules', '.git'] as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function downloadZipball(string $repo, string $branch, string $zipPath): void
    {
        $token = config('github.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('GITHUB_TOKEN تنظیم نشده است.');
        }

        $url = "https://api.github.com/repos/{$repo}/zipball/{$branch}";

        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer '.$token,
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'clickupNotifierOnTelegram-deploy',
        ])
            ->withOptions(['sink' => $zipPath])
            ->timeout(120)
            ->get($url);

        if (! $response->successful()) {
            $status = $response->status();
            throw new RuntimeException("دانلود zipball ناموفق بود (HTTP {$status}).");
        }

        if (! is_file($zipPath) || filesize($zipPath) < 100) {
            throw new RuntimeException('فایل zip دانلودشده خالی یا نامعتبر است.');
        }
    }

    private function extractZipball(string $zipPath, string $extractPath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('باز کردن فایل zip ممکن نبود.');
        }

        try {
            if (! $zip->extractTo($extractPath)) {
                throw new RuntimeException('استخراج zip ناموفق بود.');
            }
        } finally {
            $zip->close();
        }

        $entries = array_values(array_filter(
            scandir($extractPath) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..'
        ));

        if (count($entries) !== 1) {
            throw new RuntimeException('ساختار zipball گیت‌هاب غیرمنتظره است.');
        }

        $root = $extractPath.DIRECTORY_SEPARATOR.$entries[0];

        if (! is_dir($root)) {
            throw new RuntimeException('پوشه ریشه zipball پیدا نشد.');
        }

        return $root;
    }

    private function maybeComposerInstall(): string
    {
        $composer = $this->findComposerBinary();

        if ($composer === null) {
            return 'رد شد (composer روی سرور نیست؛ vendor حفظ شد)';
        }

        $command = escapeshellarg($composer)
            .' install --no-dev --no-interaction --optimize-autoloader';

        $output = [];
        $exitCode = 0;
        exec('cd '.escapeshellarg(base_path()).' && '.$command.' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            Log::warning('Composer install during deploy failed', [
                'exit' => $exitCode,
                'output' => implode("\n", array_slice($output, -20)),
            ]);

            return 'ناموفق (exit '.$exitCode.') — vendor قبلی حفظ شده';
        }

        return 'OK';
    }

    private function findComposerBinary(): ?string
    {
        foreach (['composer', 'composer.phar'] as $candidate) {
            $resolved = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));

            if ($resolved !== '' && is_executable($resolved)) {
                return $resolved;
            }
        }

        $localPhar = base_path('composer.phar');

        if (is_file($localPhar)) {
            return PHP_BINARY.' '.$localPhar;
        }

        return null;
    }

    private function runArtisan(string $command, array $parameters = []): string
    {
        try {
            $exit = Artisan::call($command, $parameters);

            if ($exit !== 0) {
                return 'ناموفق (exit '.$exit.')';
            }

            return 'OK';
        } catch (Throwable $exception) {
            report($exception);

            return 'ناموفق: '.$this->publicError($exception);
        }
    }

    private function reregisterWebhook(): string
    {
        try {
            $this->telegramNotifier->registerBotWebhook();

            return 'OK';
        } catch (Throwable $exception) {
            report($exception);

            return 'ناموفق: '.$this->publicError($exception);
        }
    }

    /**
     * @return resource|null
     */
    private function acquireLock()
    {
        File::ensureDirectoryExists(dirname($this->lockPath()));

        $handle = @fopen($this->lockPath(), 'c+');

        if ($handle === false) {
            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function lockPath(): string
    {
        return storage_path(self::WORK_DIR.'/'.self::LOCK_FILE);
    }

    private function cleanupWorkFiles(string $zipPath, string $extractPath): void
    {
        try {
            if (is_file($zipPath)) {
                File::delete($zipPath);
            }

            $this->cleanPath($extractPath);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function cleanPath(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        } elseif (is_file($path)) {
            File::delete($path);
        }
    }

    private function publicError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return class_basename($exception);
        }

        $message = preg_replace('/ghp_[A-Za-z0-9]+/', '[token]', $message) ?? $message;
        $message = preg_replace('/github_pat_[A-Za-z0-9_]+/', '[token]', $message) ?? $message;

        return mb_substr($message, 0, 240);
    }
}
