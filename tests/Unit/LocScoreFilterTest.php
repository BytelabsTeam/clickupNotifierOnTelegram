<?php

namespace Tests\Unit;

use App\Services\LocScoreFilter;
use Tests\TestCase;

class LocScoreFilterTest extends TestCase
{
    public function test_it_counts_application_code_and_skips_noise(): void
    {
        $filter = new LocScoreFilter;

        $this->assertTrue($filter->counts('app/Services/GitHubClient.php'));
        $this->assertTrue($filter->counts('app/Http/Controllers/AdController.php'));
        $this->assertTrue($filter->counts('routes/web.php'));
        $this->assertTrue($filter->counts('config/clickup.php'));

        $this->assertFalse($filter->counts('resources/css/app.css'));
        $this->assertFalse($filter->counts('public/css/theme.scss'));
        $this->assertFalse($filter->counts('assets/main.css'));
        $this->assertFalse($filter->counts('vendor/laravel/framework/src/Illuminate/Support/Str.php'));
        $this->assertFalse($filter->counts('node_modules/vue/dist/vue.js'));
        $this->assertFalse($filter->counts('composer.lock'));
        $this->assertFalse($filter->counts('package-lock.json'));
        $this->assertFalse($filter->counts('config/app.php'));
        $this->assertFalse($filter->counts('resources/views/welcome.blade.php'));
        $this->assertFalse($filter->counts('database/migrations/0001_01_01_000000_create_users_table.php'));
        $this->assertFalse($filter->counts('tests/Feature/ExampleTest.php'));
        $this->assertFalse($filter->counts('public/js/app.min.js'));
    }
}
