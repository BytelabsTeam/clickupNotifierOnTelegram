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
            'iranrouter.base_url' => 'https://iranrouter.net/api/v1',
            'iranrouter.model' => 'openai/gpt-6-luna',
            'iranrouter.timeout' => 30,
        ]);
    }

    public function test_it_summarizes_persian_commit_in_rafiq_fosh_tone(): void
    {
        Http::fake([
            'iranrouter.net/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => "ببین داداش، اون باگ تخمی لاگین رو حسابی گایید و تمومش کرد. دیگه اون کصشر گیر نمی‌ده."]],
                ],
            ], 200),
        ]);

        $result = app(CommitMessageTranslator::class)->toPersian("رفع باگ لاگین\n\nجزئیات بیشتر");

        $this->assertSame(
            'ببین داداش، اون باگ تخمی لاگین رو حسابی گایید و تمومش کرد. دیگه اون کصشر گیر نمی‌ده.',
            $result
        );

        Http::assertSent(function ($request) {
            $system = (string) ($request['messages'][0]['content'] ?? '');
            $user = (string) ($request['messages'][1]['content'] ?? '');

            return $request->url() === 'https://iranrouter.net/api/v1/chat/completions'
                && ($request['model'] ?? null) === 'openai/gpt-6-luna'
                && str_contains($system, 'رفیقونه')
                && str_contains($system, 'فحش')
                && str_contains($system, 'یک پاراگراف')
                && $user === "رفع باگ لاگین\n\nجزئیات بیشتر";
        });
    }

    public function test_it_summarizes_english_commit_via_iranrouter(): void
    {
        Http::fake([
            'iranrouter.net/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => "والا رفیق، اون باگ الکسی لاگین رو جمعش کرد. جزئیاتشم گذاشته که دیگه کسی گوه نخوره."]],
                ],
            ], 200),
        ]);

        $result = app(CommitMessageTranslator::class)->toPersian("Fix login bug\n\nMore details here");

        $this->assertSame(
            'والا رفیق، اون باگ الکسی لاگین رو جمعش کرد. جزئیاتشم گذاشته که دیگه کسی گوه نخوره.',
            $result
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://iranrouter.net/api/v1/chat/completions'
                && ($request['messages'][1]['content'] ?? null) === "Fix login bug\n\nMore details here";
        });
    }

    public function test_it_collapses_multiline_llm_output_into_one_paragraph(): void
    {
        Http::fake([
            'iranrouter.net/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => "ببین داداش، کارو تموم کرد.\nجزئیاتشم گذاشت."]],
                ],
            ], 200),
        ]);

        $result = app(CommitMessageTranslator::class)->toPersian('Fix login bug');

        $this->assertSame('ببین داداش، کارو تموم کرد. جزئیاتشم گذاشت.', $result);
    }

    public function test_it_falls_back_to_subject_when_summary_fails(): void
    {
        Http::fake([
            'iranrouter.net/api/v1/chat/completions' => Http::response('error', 500),
        ]);

        $result = app(CommitMessageTranslator::class)->toPersian("Fix login bug\n\nDetails");

        $this->assertSame('Fix login bug', $result);
    }
}
