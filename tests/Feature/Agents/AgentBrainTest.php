<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\User;
use App\Services\Agents\AgentWriter;
use App\Services\Agents\GeminiClient;
use App\Services\Agents\LiveSession;
use App\Support\Agents\AgentInstructions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What an agent is told before a call, how it is written at setup, and the
 * live setup locked into its short-lived key.
 */
class AgentBrainTest extends TestCase
{
    use RefreshDatabase;

    private function agent(array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'name' => 'Fatima',
            'business_name' => 'Riyadh Dental',
            'business_type' => 'healthcare',
            'jobs' => ['answer', 'booking'],
            'language' => 'ar',
            'dialect' => 'ar-SA',
            'live_voice' => 'Achernar',
            'first_message' => 'هلا والله، معك فاطمة من عيادة الرياض، كيف أقدر أخدمك؟',
            'script' => ['identity' => 'A calm receptionist.', 'never' => ['Never quote a price for implants.'], 'steps' => ['Ask what they need.'], 'handoff' => 'When they ask for the doctor.'],
            'knowledge' => "Cleaning: 300 SAR\nOpen 9am to 9pm",
            'choices' => [['question' => 'Discount requests?', 'answer' => 'Offer the monthly offer only']],
        ], $attributes));
    }

    public function test_a_saudi_agent_speaks_saudi_and_is_told_the_egyptian_slips_to_avoid(): void
    {
        $text = AgentInstructions::build($this->agent());

        $this->assertStringContainsString('You are Fatima, the voice assistant of Riyadh Dental', $text);
        $this->assertStringContainsString('Najdi', $text);
        $this->assertStringContainsString('وش (what)', $text);
        // The voice test caught a Saudi agent saying تحب: now it is told not to.
        $this->assertStringContainsString('تحب (say تبي or تبغى)', $text);
        $this->assertStringContainsString('Never invent a price', $text);
        $this->assertStringContainsString('Never quote a price for implants.', $text);
        $this->assertStringContainsString('When they ask for the doctor.', $text);
        $this->assertStringContainsString('Discount requests? → Offer the monthly offer only', $text);
        $this->assertStringContainsString('Cleaning: 300 SAR', $text);
        $this->assertStringContainsString('هلا والله، معك فاطمة', $text);
    }

    public function test_an_egyptian_agent_avoids_gulf_words_and_a_fusha_one_speaks_fusha(): void
    {
        $egyptian = AgentInstructions::build($this->agent(['dialect' => 'ar-EG']));
        $this->assertStringContainsString('Cairene', $egyptian);
        $this->assertStringContainsString('Do not use Gulf or Levantine words', $egyptian);

        $fusha = AgentInstructions::speech($this->agent(['dialect' => 'ar-XA']));
        $this->assertStringContainsString('Modern Standard Arabic', $fusha);
        $this->assertStringNotContainsString('never Modern Standard Arabic', $fusha);

        $english = AgentInstructions::speech($this->agent(['language' => 'en', 'dialect' => null]));
        $this->assertStringContainsString('English', $english);
    }

    public function test_the_live_setup_locks_the_voice_instructions_and_tools(): void
    {
        $setup = LiveSession::setupFor($this->agent());

        $this->assertSame('models/gemini-3.8-live', $setup['model']);
        $this->assertSame(['AUDIO'], $setup['generationConfig']['responseModalities']);
        $this->assertSame('Achernar', $setup['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName']);
        $this->assertStringContainsString('Riyadh Dental', $setup['systemInstruction']['parts'][0]['text']);
        $this->assertSame(['save_contact', 'end_call'], array_column($setup['tools'][0]['functionDeclarations'], 'name'));
        $this->assertArrayHasKey('slidingWindow', $setup['contextWindowCompression']);
        // Empty objects stay objects in the JSON Google reads.
        $this->assertStringContainsString('"inputAudioTranscription":{}', json_encode($setup));
    }

    public function test_the_writer_turns_the_models_answer_into_a_clean_agent(): void
    {
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'first_message' => '  هلا والله، معك فاطمة من عيادة الرياض  ',
            'identity' => 'Calm and kind.',
            'never' => ['Never invent prices.', '', 'Never give medical advice.'],
            'steps' => ['Ask what they need.', 'Book a visit.'],
            'handoff' => 'When they ask for the doctor.',
            'knowledge' => "Cleaning: 300 SAR",
            'questions' => [
                ['question' => 'A caller asks for a discount?', 'options' => ['Offer the monthly offer', 'Say prices are fixed'], 'recommended' => 0],
                ['question' => 'Only one option?', 'options' => ['Yes'], 'recommended' => 0],
            ],
        ], JSON_UNESCAPED_UNICODE)]]]]]])]);

        $written = app(AgentWriter::class)->write($this->agent(), 'site text', 'owner notes');

        $this->assertSame('model', $written['written_by']);
        $this->assertSame('هلا والله، معك فاطمة من عيادة الرياض', $written['first_message']);
        $this->assertSame(['Never invent prices.', 'Never give medical advice.'], $written['never']);
        // A question with one option is not a choice: dropped.
        $this->assertCount(1, $written['questions']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'gemini-3.8-flash:generateContent')
            && str_contains($r['contents'][0]['parts'][0]['text'], 'owner notes')
            && $r['generationConfig']['responseMimeType'] === 'application/json');
    }

    public function test_the_writer_still_returns_an_agent_when_the_model_is_not_there(): void
    {
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'unavailable']], 503)]);

        $written = app(AgentWriter::class)->write($this->agent(['dialect' => 'ar-EG', 'name' => 'Sumaya', 'business_name' => 'Cairo Smiles']), '', 'Whitening 1,500 EGP');

        $this->assertSame('fallback', $written['written_by']);
        $this->assertStringContainsString('معاك Sumaya من Cairo Smiles', $written['first_message']);
        $this->assertStringContainsString('Whitening 1,500 EGP', $written['knowledge']);
    }

    public function test_a_live_key_is_made_with_the_setup_locked_inside(): void
    {
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/abc123'])]);
        $setup = LiveSession::setupFor($this->agent());

        $key = app(GeminiClient::class)->liveToken($setup, 600);

        $this->assertSame('auth_tokens/abc123', $key['token']);
        Http::assertSent(function ($r) {
            return str_ends_with($r->url(), '/auth_tokens')
                && $r['uses'] === 1
                && $r['bidiGenerateContentSetup']['model'] === 'models/gemini-3.8-live'
                && str_contains($r['bidiGenerateContentSetup']['systemInstruction']['parts'][0]['text'], 'Riyadh Dental')
                && $r->header('x-goog-api-key')[0] === 'test-key';
        });
    }
}
