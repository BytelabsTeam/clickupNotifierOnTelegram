<?php

namespace Tests\Unit;

use App\Services\ClickUpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClickUpClientProjectTagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'clickup.api_token' => 'pk_test_token',
        ]);
    }

    public function test_it_builds_project_tag_from_space_and_folder(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/space/7002367' => Http::response([
                'id' => '7002367',
                'name' => 'minishop',
            ], 200),
        ]);

        $tag = app(ClickUpClient::class)->resolveProjectTag([
            'space' => ['id' => '7002367'],
            'folder' => [
                'id' => '6992470',
                'name' => 'Telegramclient',
                'hidden' => false,
            ],
        ]);

        $this->assertSame('#minishop_Telegramclient', $tag);
    }

    public function test_it_builds_project_tag_from_space_only_when_folder_is_hidden(): void
    {
        Http::fake([
            'api.clickup.com/api/v2/space/7002367' => Http::response([
                'id' => '7002367',
                'name' => 'minishop',
            ], 200),
        ]);

        $tag = app(ClickUpClient::class)->resolveProjectTag([
            'space' => ['id' => '7002367'],
            'folder' => [
                'id' => '6992470',
                'name' => 'Telegramclient',
                'hidden' => true,
            ],
        ]);

        $this->assertSame('#minishop', $tag);
    }

    public function test_it_returns_empty_tag_when_space_is_missing(): void
    {
        $tag = app(ClickUpClient::class)->resolveProjectTag([
            'name' => 'Some task',
        ]);

        $this->assertSame('', $tag);
    }
}
