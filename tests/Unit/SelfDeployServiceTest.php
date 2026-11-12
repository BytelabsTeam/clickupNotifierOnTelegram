<?php

namespace Tests\Unit;

use App\Services\SelfDeployService;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SelfDeployServiceTest extends TestCase
{
    public function test_it_recognizes_deploy_admin_ids(): void
    {
        config(['deploy.admin_ids' => [103063134, 1080648232]]);

        $service = app(SelfDeployService::class);

        $this->assertTrue($service->isAdmin(103063134));
        $this->assertTrue($service->isAdmin(1080648232));
        $this->assertFalse($service->isAdmin(999));
        $this->assertFalse($service->isAdmin(0));
    }

    public function test_it_protects_env_storage_vendor_and_sqlite(): void
    {
        $service = app(SelfDeployService::class);

        $this->assertTrue($service->isProtectedRelativePath('.env'));
        $this->assertTrue($service->isProtectedRelativePath('.env.production'));
        $this->assertTrue($service->isProtectedRelativePath('storage/logs/laravel.log'));
        $this->assertTrue($service->isProtectedRelativePath('vendor/autoload.php'));
        $this->assertTrue($service->isProtectedRelativePath('node_modules/left-pad/index.js'));
        $this->assertTrue($service->isProtectedRelativePath('.git/config'));
        $this->assertTrue($service->isProtectedRelativePath('database/database.sqlite'));

        $this->assertTrue($service->isProtectedRelativePath('.env.example'));

        $this->assertFalse($service->isProtectedRelativePath('app/Services/Foo.php'));
        $this->assertFalse($service->isProtectedRelativePath('config/deploy.php'));
        $this->assertFalse($service->isProtectedRelativePath('database/migrations/2026_01_01_000000_create_foo.php'));
    }

    public function test_overlay_copies_files_but_skips_protected_paths(): void
    {
        $base = storage_path('framework/testing/deploy-overlay-'.uniqid('', true));
        $source = $base.'/source';
        $target = $base.'/target';

        File::ensureDirectoryExists($source.'/app');
        File::ensureDirectoryExists($source.'/storage/logs');
        File::ensureDirectoryExists($source.'/vendor');
        File::ensureDirectoryExists($source.'/database');
        File::ensureDirectoryExists($target.'/app');
        File::ensureDirectoryExists($target.'/storage/logs');
        File::ensureDirectoryExists($target.'/vendor');
        File::ensureDirectoryExists($target.'/database');

        File::put($source.'/app/New.php', 'new-code');
        File::put($source.'/storage/logs/should-not-copy.log', 'from-zip');
        File::put($source.'/vendor/pkg.php', 'from-zip-vendor');
        File::put($source.'/.env', 'SECRET=from-zip');
        File::put($source.'/database/database.sqlite', 'zip-db');
        File::put($source.'/README.md', 'readme-from-zip');

        File::put($target.'/app/Old.php', 'old-code');
        File::put($target.'/storage/logs/keep.log', 'keep-storage');
        File::put($target.'/vendor/pkg.php', 'keep-vendor');
        File::put($target.'/.env', 'SECRET=keep');
        File::put($target.'/database/database.sqlite', 'keep-db');

        try {
            $service = app(SelfDeployService::class);
            $copied = $service->overlayFromDirectory($source, $target);

            $this->assertSame(2, $copied);
            $this->assertSame('new-code', File::get($target.'/app/New.php'));
            $this->assertSame('readme-from-zip', File::get($target.'/README.md'));
            $this->assertSame('old-code', File::get($target.'/app/Old.php'));
            $this->assertSame('keep-storage', File::get($target.'/storage/logs/keep.log'));
            $this->assertFalse(File::exists($target.'/storage/logs/should-not-copy.log'));
            $this->assertSame('keep-vendor', File::get($target.'/vendor/pkg.php'));
            $this->assertSame('SECRET=keep', File::get($target.'/.env'));
            $this->assertSame('keep-db', File::get($target.'/database/database.sqlite'));
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_run_fails_fast_when_github_token_missing(): void
    {
        config([
            'github.token' => '',
            'deploy.github_repo' => 'BytelabsTeam/clickupNotifierOnTelegram',
            'deploy.github_branch' => 'main',
        ]);

        $notifier = $this->createMock(TelegramNotifier::class);
        $service = new SelfDeployService($notifier);

        $result = $service->run();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('GITHUB_TOKEN', $result['message']);
    }
}
