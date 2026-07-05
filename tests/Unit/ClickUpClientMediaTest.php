<?php

namespace Tests\Unit;

use App\Services\ClickUpClient;
use Tests\TestCase;

class ClickUpClientMediaTest extends TestCase
{
    public function test_it_extracts_image_and_video_attachments(): void
    {
        $client = app(ClickUpClient::class);

        $media = $client->extractMediaAttachments([
            [
                'url' => 'https://attachments.clickup.com/screenshot.png',
                'mimetype' => 'image/png',
                'extension' => 'png',
                'deleted' => false,
            ],
            [
                'url' => 'https://attachments.clickup.com/demo.mp4',
                'mimetype' => 'video/mp4',
                'extension' => 'mp4',
                'deleted' => false,
            ],
            [
                'url' => 'https://attachments.clickup.com/notes.txt',
                'mimetype' => 'text/plain',
                'extension' => 'txt',
                'deleted' => false,
            ],
            [
                'url' => 'https://attachments.clickup.com/deleted.png',
                'mimetype' => 'image/png',
                'extension' => 'png',
                'deleted' => true,
            ],
        ]);

        $this->assertSame([
            [
                'url' => 'https://attachments.clickup.com/screenshot.png',
                'type' => 'photo',
            ],
            [
                'url' => 'https://attachments.clickup.com/demo.mp4',
                'type' => 'video',
            ],
        ], $media);
    }
}
