<?php

namespace Tests\Unit;

use App\Services\CommitMessageTranslator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommitMessageTranslatorTest extends TestCase
{
    public function test_it_skips_translation_when_subject_is_already_persian(): void
    {
        Http::fake();

        $result = app(CommitMessageTranslator::class)->toPersian('رفع باگ لاگین');

        $this->assertSame('رفع باگ لاگین', $result);
        Http::assertNothingSent();
    }

    public function test_it_translates_english_subject_via_google(): void
    {
        Http::fake([
            'translate.googleapis.com/*' => Http::response([
                [['رفع باگ لاگین', 'Fix login bug', null, null, 0]],
            ], 200),
        ]);

        $result = app(CommitMessageTranslator::class)->toPersian("Fix login bug\n\nMore details here");

        $this->assertSame("رفع باگ لاگین\n\nMore details here", $result);
    }

    public function test_it_falls_back_to_original_when_translation_fails(): void
    {
        Http::fake([
            'translate.googleapis.com/*' => Http::response('error', 500),
        ]);

        $result = app(CommitMessageTranslator::class)->toPersian('Fix login bug');

        $this->assertSame('Fix login bug', $result);
    }
}
