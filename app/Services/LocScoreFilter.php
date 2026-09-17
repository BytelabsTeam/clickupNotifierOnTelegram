<?php

namespace App\Services;

class LocScoreFilter
{
    /**
     * @var list<string>
     */
    private const SKIP_EXTENSIONS = [
        'css', 'scss', 'sass', 'less', 'styl', 'map',
        'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'webp',
        'woff', 'woff2', 'ttf', 'eot',
        'lock',
    ];

    /**
     * @var list<string>
     */
    private const SKIP_PREFIXES = [
        'vendor/',
        'node_modules/',
        'public/build/',
        'public/hot/',
        'public/vendor/',
        'public/css/',
        'resources/css/',
        'bootstrap/cache/',
        'storage/',
        'dist/',
        'build/',
    ];

    /**
     * Laravel skeleton / generated files that should not earn score.
     *
     * @var list<string>
     */
    private const SKIP_FILES = [
        'artisan',
        'composer.lock',
        'package-lock.json',
        'yarn.lock',
        'pnpm-lock.yaml',
        'bun.lockb',
        'phpunit.xml',
        'phpunit.xml.dist',
        'vite.config.js',
        'postcss.config.js',
        'tailwind.config.js',
        'webpack.mix.js',
        'app/http/controllers/controller.php',
        'app/providers/appserviceprovider.php',
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'config/app.php',
        'config/auth.php',
        'config/broadcasting.php',
        'config/cache.php',
        'config/concurrency.php',
        'config/cors.php',
        'config/database.php',
        'config/filesystems.php',
        'config/hashing.php',
        'config/logging.php',
        'config/mail.php',
        'config/queue.php',
        'config/services.php',
        'config/session.php',
        'config/view.php',
        'database/factories/userfactory.php',
        'database/seeders/databaseseeder.php',
        'public/index.php',
        'public/robots.txt',
        'public/.htaccess',
        'resources/js/app.js',
        'resources/js/bootstrap.js',
        'resources/views/welcome.blade.php',
        'tests/testcase.php',
        'tests/createsapplication.php',
        'tests/feature/exampletest.php',
        'tests/unit/exampletest.php',
    ];

    public function counts(string $path): bool
    {
        $path = strtolower(str_replace('\\', '/', ltrim(trim($path), '/')));

        if ($path === '') {
            return false;
        }

        $filename = basename($path);

        if (str_ends_with($filename, '.min.js') || str_ends_with($filename, '.min.css')) {
            return false;
        }

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (is_string($extension) && in_array($extension, self::SKIP_EXTENSIONS, true)) {
            return false;
        }

        if (in_array($path, self::SKIP_FILES, true) || in_array($filename, self::SKIP_FILES, true)) {
            return false;
        }

        if (str_starts_with($path, 'database/migrations/0001_01_01_')) {
            return false;
        }

        return true;
    }
}
