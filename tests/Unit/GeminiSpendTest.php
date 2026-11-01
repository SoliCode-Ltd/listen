<?php

namespace Tests\Unit;

use App\Support\GeminiSpend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiSpendTest extends TestCase
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function test_speech_is_priced_at_google_list_prices_and_doubles_on_1_january_2027(): void
    {
        // 1,000 text tokens in and 25,000 audio tokens out (1,000 seconds at 25 a second).
        $before = Carbon::parse('2026-12-31 23:30:00', 'America/Los_Angeles');
        $after = Carbon::parse('2027-01-01 00:30:00', 'America/Los_Angeles');

        $this->assertEqualsWithDelta((1000 * 0.50 + 25000 * 9.00) / 1e6, GeminiSpend::price('gemini-3.8-flash-tts', 1000, 25000, 0, $before), 1e-12);
        $this->assertEqualsWithDelta((1000 * 1.00 + 25000 * 18.00) / 1e6, GeminiSpend::price('gemini-3.8-flash-tts', 1000, 25000, 0, $after), 1e-12);
        // Google's own figure: $0.00225 for 10 seconds of audio (250 tokens) through 2026.
        $this->assertEqualsWithDelta(0.00225, GeminiSpend::price('gemini-3.8-flash-tts', 0, 250, 0, $before), 1e-12);
        // The billing day is Google's (Pacific): 2027-01-01 05:00 UTC is still 31 December there.
        $this->assertEqualsWithDelta(0.00225, GeminiSpend::price('gemini-3.8-flash-tts', 0, 250, 0, Carbon::parse('2027-01-01 05:00:00', 'UTC')), 1e-12);
    }

    public function test_older_and_text_models_use_their_own_prices(): void
    {
        $day = Carbon::parse('2026-11-01 12:00:00');

        $this->assertEqualsWithDelta(10.00, GeminiSpend::price('gemini-2.5-flash-preview-tts', 0, 1_000_000, 0, $day), 1e-9);
        $this->assertEqualsWithDelta(20.00, GeminiSpend::price('gemini-2.5-pro-preview-tts', 0, 1_000_000, 0, $day), 1e-9);
        $this->assertEqualsWithDelta(20.00, GeminiSpend::price('gemini-3.1-flash-tts-preview', 0, 1_000_000, 0, $day), 1e-9);
        // The cached part of a prompt is billed at the cached price; thinking counts as output.
        $this->assertEqualsWithDelta((6000 * 0.75 + 4000 * 0.075 + 2000 * 3.75) / 1e6, GeminiSpend::price('gemini-3.6-flash', 10000, 2000, 4000, $day), 1e-12);
        $this->assertEqualsWithDelta((1000 * 0.50 + 1000 * 3.00) / 1e6, GeminiSpend::price('models/gemini-3.5-flash', 1000, 1000, 0, $day), 1e-12);
        // A speech model with no price is priced as today's speech model.
        $this->assertEqualsWithDelta(9.00, GeminiSpend::price('gemini-9-flash-tts', 0, 1_000_000, 0, $day), 1e-9);

        config(['analytics.gemini_tts.free_tier' => true]);
        $this->assertSame(0.0, GeminiSpend::price('gemini-3.8-flash-tts', 1000, 25000, 0, $day));
    }

    public function test_every_answered_gemini_request_is_counted_once_at_its_model_price(): void
    {
        Carbon::setTestNow('2026-11-01 12:00:00');
        $audio = base64_encode(str_repeat("\0", 1_200_000)); // a long voice: about 1.6 MB of base64
        Http::fake([
            self::BASE.'/models/gemini-3.8-flash-tts:generateContent' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;rate=24000', 'data' => 'AAAA']]]]]],
                    'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 2500, 'totalTokenCount' => 2540,
                        'candidatesTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 2500]]], 'modelVersion' => 'gemini-3.8-flash-tts'])
                // Long replies are read from their end, where the counts are.
                ->push('{"candidates":[{"content":{"parts":[{"inlineData":{"mimeType":"audio/L16;rate=24000","data":"'.$audio.'"}}]}}],'
                    .'"usageMetadata":{"promptTokenCount":60,"candidatesTokenCount":30000,"totalTokenCount":30060},"modelVersion":"gemini-3.8-flash-tts"}')
                // An error is not billed and carries no counts.
                ->push(['error' => ['message' => 'busy']], 503),
            self::BASE.'/interactions' => Http::response([
                'outputs' => [], 'usage' => ['total_input_tokens' => 30, 'total_output_tokens' => 1000, 'total_thought_tokens' => 0, 'total_tokens' => 1030],
            ]),
            self::BASE.'/models/gemini-3.6-flash:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'وش لونك']]]]],
                'usageMetadata' => ['promptTokenCount' => 600, 'candidatesTokenCount' => 300, 'thoughtsTokenCount' => 200],
            ]),
            'https://texttospeech.googleapis.com/*' => Http::response(['usageMetadata' => ['promptTokenCount' => 999999]]),
        ]);

        // Nothing is counted while no meter is open.
        Http::post(self::BASE.'/models/gemini-3.6-flash:generateContent', []);

        $outer = GeminiSpend::start();
        Http::post(self::BASE.'/models/gemini-3.8-flash-tts:generateContent', ['contents' => []]);
        $inner = GeminiSpend::start();
        Http::pool(fn ($pool) => [
            $pool->as('a')->post(self::BASE.'/models/gemini-3.8-flash-tts:generateContent', ['contents' => []]),
            $pool->as('b')->post(self::BASE.'/interactions', ['model' => 'gemini-3.8-flash-tts', 'input' => []]),
        ]);
        $inner->stop();
        Http::post(self::BASE.'/models/gemini-3.8-flash-tts:generateContent', ['contents' => []]); // the 503
        Http::post(self::BASE.'/models/gemini-3.6-flash:generateContent', ['contents' => []]);
        Http::post('https://texttospeech.googleapis.com/v1/text:synthesize', []);
        $outer->stop();
        Http::post(self::BASE.'/models/gemini-3.6-flash:generateContent', []);

        $speech1 = (40 * 0.50 + 2500 * 9.00) / 1e6;
        $speech2 = (60 * 0.50 + 30000 * 9.00) / 1e6;
        $cloned = (30 * 0.50 + 1000 * 9.00) / 1e6;
        $rewrite = (600 * 0.75 + 500 * 3.75) / 1e6;

        $this->assertSame(4, $outer->calls());
        $this->assertSame(40 + 60 + 30 + 600, $outer->inputTokens());
        $this->assertSame(2500 + 30000 + 1000 + 500, $outer->outputTokens());
        $this->assertSame(round($speech1 + $speech2 + $cloned + $rewrite, 6), $outer->cost());
        $this->assertSame(3, $outer->summary()['models']['gemini-3.8-flash-tts']['calls']);
        $this->assertSame(1, $outer->summary()['models']['gemini-3.6-flash']['calls']);

        $this->assertSame(2, $inner->calls());
        $this->assertSame(round($speech2 + $cloned, 6), $inner->cost());
        $this->assertFalse(GeminiSpend::listening());

        // A standard voice's own per-character price is added; a Gemini result's is already counted.
        $this->assertSame(round($speech1 + $speech2 + $cloned + $rewrite + 0.0016, 6), $outer->totalWith(['provider' => 'google_cloud', 'cost_estimate_usd' => 0.0016]));
        $this->assertSame($outer->cost(), $outer->totalWith(['provider' => 'gemini', 'cost_estimate_usd' => 5.0]));
    }

    public function test_the_counts_are_read_from_either_api(): void
    {
        $this->assertSame(['input' => 12, 'output' => 70, 'cached' => 2, 'model' => 'gemini-3.6-flash'], GeminiSpend::usageFromArray([
            'usageMetadata' => ['promptTokenCount' => 10, 'toolUsePromptTokenCount' => 2, 'candidatesTokenCount' => 50, 'thoughtsTokenCount' => 20, 'cachedContentTokenCount' => 2],
            'modelVersion' => 'models/gemini-3.6-flash',
        ]));
        $this->assertSame(['input' => 7, 'output' => 42, 'cached' => 0, 'model' => null], GeminiSpend::usageFromArray([
            'usage' => ['total_input_tokens' => 7, 'total_output_tokens' => 20, 'total_thought_tokens' => 22, 'total_cached_tokens' => 0, 'total_tokens' => 49],
        ]));
        $this->assertNull(GeminiSpend::usageFromArray(['candidates' => []]));
        $this->assertNull(GeminiSpend::usageFrom('not json'));
    }
}
