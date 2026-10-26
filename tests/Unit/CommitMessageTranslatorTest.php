<?php

namespace Tests\Unit;

use App\Services\CommitMessageTranslator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommitMessageTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'iranrouter.api_key' => 'ir-test-key',
            'iranrouter.base_url' => 'https://www.iranrouter.net/api/v1',
            'iranrouter.model' => 'openai/gpt-6-luna',
            'iranrouter.timeout' => 30,
        ]);
    }

    public function test_it_skips_translation_when_subject_is_already_persian(): void
    {
        Http::fake();

        $result = app(CommitMessageTranslator::class)->toPersian('رفع باگ لاگین');

        $this->assertSame('رفع باگ لاگین', $result);
        Http::assertNothingSent();
    }

    public function test_it_translates_english_subject_via_iranrouter(): void
    {
        Http::fake([
            'www.iranrouter.net/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'رفع باگ لاگین']],
                ],
            ], 200),
        ]);

        $result = app(CommitMessageTranslator::class)->toPersian("Fix login bug\n\nMore details here");

        $this->assertSame("رفع باگ لاگین\n\nMore details here", $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://www.iranrouter.net/api/v1/chat/completions'
                && ($request['model'] ?? null) === 'openai/gpt-6-luna'
                && ($request['messages'][1]['content'] ?? null) === 'Fix login bug';
        });
    }

    public function test_it_falls_back_to_original_when_translation_fails(): void
    {
        Http::fake([
            'www.iranrouter.net/api/v1/chat/completions' => Http::response('error', 500),
        ]);

        $result = app(CommitMessageTranslator::class)->toPersian('Fix login bug');

        $this->assertSame('Fix login bug', $result);
    }
}
