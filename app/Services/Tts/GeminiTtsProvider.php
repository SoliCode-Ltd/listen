<?php

namespace App\Services\Tts;

use App\Contracts\TtsProviderInterface;
use App\Services\VoiceCloningService;
use App\Support\ArabicDialects;
use App\Support\EnglishAccents;
use App\Support\GeminiTtsModel;
use App\Support\GeminiVocalTags;
use App\Support\ShareableAudio;
use App\Support\TextChunker;
use App\Support\TtsStudioConfig;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gemini TTS Provider.
 *
 * Uses the Gemini model selected in TTS settings.
 * Gemini 2.5 keeps the legacy prompt. Gemini 3.8 speaks the transcript
 * verbatim and takes delivery instructions in speech metadata.
 *
 * API: Google AI Generative Language API
 */
class GeminiTtsProvider implements TtsProviderInterface
{

    /**
     * Current API key info (managed by GeminiKeyManager).
     */
    private ?array $currentKeyInfo = null;

    /** The speech model of the current request: daily limits are per key and per model. */
    private ?string $currentModel = null;

    /**
     * GeminiKeyManager instance.
     */
    private ?GeminiKeyManager $keyManager = null;

    /**
     * Get the key manager instance.
     */
    private function getKeyManager(): GeminiKeyManager
    {
        if ($this->keyManager === null) {
            $this->keyManager = app(GeminiKeyManager::class);
        }
        return $this->keyManager;
    }

    /** Gemini TTS supported voice names */
    private const VOICES = [
        'Zephyr', 'Puck', 'Charon', 'Kore', 'Fenrir', 'Leda',
        'Orus', 'Aoede', 'Callirrhoe', 'Autonoe', 'Enceladus',
        'Iapetus', 'Umbriel', 'Algieba', 'Despina', 'Erinome',
        'Gacrux', 'Laomedeia', 'Achernar', 'Achird', 'Algenib',
        'Alnilam', 'Pulcherrima', 'Rasalgethi', 'Sadachbia',
        'Sadaltager', 'Schedar', 'Sulafat', 'Vindemiatrix',
        'Zubenelgenubi',
    ];

    /**
     * Legacy/alias voice names mapped to canonical Gemini voice names.
     *
     * @var array<string, string>
     */
    private const VOICE_ALIAS_MAP = [
        // Arabic branded aliases (legacy)
        'rawaa' => 'Kore',
        'basem' => 'Puck',
        'ghada' => 'Aoede',
        'faris' => 'Charon',
        'souad' => 'Leda',
        'tariq' => 'Orus',
        'lamis' => 'Zephyr',

        // Arabic Gemini display names -> canonical voice
        'fatima'     => 'Achernar',   // female
        'nawaf'      => 'Achird',     // male
        'sultan'     => 'Algenib',    // male
        'turki'      => 'Algieba',    // male
        'faisal'     => 'Alnilam',    // male
        'khadija'    => 'Aoede',      // female
        'ruqaya'     => 'Autonoe',    // female
        'sumaya'     => 'Callirrhoe', // female
        'waleed'     => 'Charon',     // male
        'walid'      => 'Charon',     // male (transliteration variant)
        'maha'       => 'Despina',    // female
        'mansour'    => 'Enceladus',  // male
        'aisha'      => 'Erinome',    // female
        'saud'       => 'Fenrir',     // male
        'duaa'       => 'Gacrux',     // female
        'fahad'      => 'Iapetus',    // male
        'lamya'      => 'Kore',       // female
        'lubna'      => 'Laomedeia',  // female
        'noura'      => 'Leda',       // female
        'abdulaziz'  => 'Orus',       // male
        'othman'     => 'Puck',       // male
        'abeer'      => 'Pulcherrima',// female
        'mishary'    => 'Rasalgethi', // male
        'hamed'      => 'Sadachbia',  // male
        'tamim'      => 'Sadaltager', // male
        'hazem'      => 'Schedar',    // male
        'manal'      => 'Sulafat',    // female
        'raed'       => 'Umbriel',    // male
        'jumana'     => 'Vindemiatrix', // female
        'sawsan'     => 'Zephyr',     // female
        'yasser'     => 'Zubenelgenubi', // male

        // English Gemini display names -> canonical voice
        'alice'      => 'Achernar',   // female
        'oliver'     => 'Achird',     // male
        'george'     => 'Algenib',    // male
        'oscar'      => 'Algieba',    // male
        'charlie'    => 'Alnilam',    // male
        'ruby'       => 'Aoede',      // female
        'ivy'        => 'Autonoe',    // female
        'maya'       => 'Callirrhoe', // female
        'theodore'   => 'Charon',     // male
        'luna'       => 'Despina',    // female
        'arthur'     => 'Enceladus',  // male
        'willow'     => 'Erinome',    // female
        'finn'       => 'Fenrir',     // male
        'isla'       => 'Gacrux',     // female
        'miles'      => 'Iapetus',    // male
        'daisy'      => 'Kore',       // female
        'poppy'      => 'Laomedeia',  // female
        'sienna'     => 'Leda',       // female
        'nathan'     => 'Orus',       // male
        'dylan'      => 'Puck',       // male
        'freya'      => 'Pulcherrima',// female
        'ryan'       => 'Rasalgethi', // male
        'adrian'     => 'Sadachbia',  // male
        'caleb'      => 'Sadaltager', // male
        'marcus'     => 'Schedar',    // male
        'phoebe'     => 'Sulafat',    // female
        'vincent'    => 'Umbriel',    // male
        'rose'       => 'Vindemiatrix', // female
        'clara'      => 'Zephyr',     // female
        'philip'     => 'Zubenelgenubi', // male,
    ];

    /**
     * Voice-to-gender mapping aligned with Chirp3/Gemini voice catalog.
     *
     * @var array<string, string>
     */
    private const VOICE_GENDER_MAP = [
        'Achernar' => 'female',
        'Achird' => 'male',
        'Algenib' => 'male',
        'Algieba' => 'male',
        'Alnilam' => 'male',
        'Aoede' => 'female',
        'Autonoe' => 'female',
        'Callirrhoe' => 'female',
        'Charon' => 'male',
        'Despina' => 'female',
        'Enceladus' => 'male',
        'Erinome' => 'female',
        'Fenrir' => 'male',
        'Gacrux' => 'female',
        'Iapetus' => 'male',
        'Kore' => 'female',
        'Laomedeia' => 'female',
        'Leda' => 'female',
        'Orus' => 'male',
        'Puck' => 'male',
        'Pulcherrima' => 'female',
        'Rasalgethi' => 'male',
        'Sadachbia' => 'male',
        'Sadaltager' => 'male',
        'Schedar' => 'male',
        'Sulafat' => 'female',
        'Umbriel' => 'male',
        'Vindemiatrix' => 'female',
        'Zephyr' => 'female',
        'Zubenelgenubi' => 'male',
    ];

    /** @var array<string, string> */
    private const EMOTION_PROMPTS = [
        'neutral' => 'Maintain a natural, balanced emotional tone throughout.',
        'warm' => 'Infuse every word with genuine warmth and heartfelt friendliness. Sound like you truly care about the listener. Let your smile come through your voice.',
        'sad' => 'Speak with deep, aching sadness and raw vulnerability. Let your voice tremble slightly with emotion. Slow down and let sorrow fill the pauses between phrases.',
        'excited' => 'BURST with infectious, high-energy excitement! Raise your voice with genuine enthusiasm. Let your passion overflow and make the listener feel the thrill in every word.',
        'formal' => 'Deliver with impeccable formality, polished diction, and authoritative gravitas. Every syllable precise and measured. Command respect with your tone.',
        'calm' => 'Speak with serene, zen-like tranquility. Ultra-smooth, gentle delivery that soothes the listener. Let peaceful stillness fill the spaces between your words.',
        'angry' => 'Channel controlled, simmering anger with fierce intensity. Let righteous fury sharpen each word. Build tension with restrained power that could explode at any moment.',
    ];

    /**
     * Arabic dialect descriptions for style prompt building.
     * These are used to instruct Gemini to speak with the appropriate regional accent.
     */
    private const ARABIC_DIALECT_PROMPTS = [
        'ar-SA' => 'Saudi Arabic (Gulf dialect)',
        'ar-SA-HJ' => 'Hejazi Saudi Arabic (Jeddah and Mecca)',
        'ar-AE' => 'Emirati Arabic (Gulf dialect)',
        'ar-KW' => 'Kuwaiti Arabic (Gulf dialect)',
        'ar-QA' => 'Qatari Arabic (urban Doha, Gulf dialect)',
        'ar-BH' => 'Bahraini Arabic (Gulf dialect)',
        'ar-OM' => 'Omani Arabic (Gulf dialect)',
        'ar-YE' => 'Yemeni Arabic',
        'ar-EG' => 'Egyptian Arabic',
        'ar-EG-SA' => 'Saidi Egyptian Arabic (Upper Egypt)',
        'ar-LB' => 'Lebanese Arabic (Levantine)',
        'ar-SY' => 'Syrian Arabic (Levantine)',
        'ar-JO' => 'Jordanian Arabic (Levantine)',
        'ar-PS' => 'Palestinian Arabic (Levantine)',
        'ar-IQ' => 'Iraqi Arabic',
        'ar-MA' => 'Moroccan Arabic (Maghrebi)',
        'ar-DZ' => 'Algerian Arabic (Maghrebi)',
        'ar-TN' => 'Tunisian Arabic (Maghrebi)',
        'ar-LY' => 'Libyan Arabic (Maghrebi)',
        'ar-MR' => 'Mauritanian Hassaniya Arabic',
        'ar-SD' => 'Sudanese Arabic',
        'ar-XA' => 'Modern Standard Arabic (Fusha)',
        'ar-SA-SO' => 'Southern Saudi Arabic (Asir and Jazan)',
        'ar-SA-QS' => 'Qassimi Saudi Arabic (Al-Qassim, Najdi)',
        'ar-SA-EA' => 'Eastern Province Saudi Arabic (Al-Ahsa and Dammam)',
        'ar-SA-NO' => 'Northern Saudi Arabic (Hail and Al-Jouf, Bedouin)',
        'ar-OM-DH' => 'Dhofari Omani Arabic (Salalah)',
        'ar-QA-BD' => 'Qatari Bedouin Arabic (Badu, Najdi-influenced Gulf dialect)',
        'ar-SY-AL' => 'Aleppine Syrian Arabic (Aleppo)',
        'ar-JO-BD' => 'Jordanian Bedouin Arabic',
        'ar-PS-GZ' => 'Gazan Palestinian Arabic (Gaza)',
        'ar-EG-AL' => 'Alexandrian Egyptian Arabic (Alexandria)',
        'ar-EG-DL' => 'Rural Delta Egyptian Arabic (Fallahi)',
        'ar-EG-PO' => 'Port Said and Canal cities Egyptian Arabic',
        'ar-EG-BD' => 'Sinai Bedouin Arabic (Egypt)',
        'ar-IQ-BS' => 'Basrawi Iraqi Arabic (Basra, Gulf-influenced)',
        'ar-IQ-MS' => 'Maslawi Iraqi Arabic (Mosul)',
        'ar-YE-AD' => 'Adeni Yemeni Arabic (Aden)',
        'ar-YE-HD' => 'Hadrami Yemeni Arabic (Hadramawt)',
        'ar-MA-NO' => 'Northern Moroccan Arabic (Tangier and Tetouan, Jebli)',
        'ar-DZ-OR' => 'Oranian Algerian Arabic (Oran)',
        'ar-LY-BG' => 'Eastern Libyan Arabic (Benghazi, Cyrenaican)',
        'ar-LB-TR' => 'Tripolitan Lebanese Arabic (Tripoli, North Lebanon)',
        'ar-SY-DZ' => 'Deiri Syrian Arabic (Deir ez-Zor, Euphrates)',
        'ar-JO-HR' => 'Horani Arabic (Irbid and the Hauran)',
        'ar-PS-HB' => 'Khalili Palestinian Arabic (Hebron)',
        'ar-IQ-AN' => 'Anbari Iraqi Arabic (Ramadi and western Iraq, Bedouin-influenced)',
        'ar-YE-TZ' => "Ta'izzi Yemeni Arabic (Taiz)",
        'ar-MA-MK' => 'Marrakchi Moroccan Arabic (Marrakech)',
        'ar-DZ-CO' => 'Constantinois Algerian Arabic (Constantine, eastern Algeria)',
        'ar-TN-SF' => 'Sfaxi Tunisian Arabic (Sfax)',
    ];

    /** @var array<int, string> */
    private const SUPPORTED_LANGUAGE_CODES = [
        'ar-XA',
        'bn-BD',
        'de-DE',
        'en-AU',
        'en-GB',
        'en-IN',
        'en-US',
        'es-US',
        'fr-FR',
        'gu-IN',
        'hi-IN',
        'id-ID',
        'it-IT',
        'ja-JP',
        'kn-IN',
        'ko-KR',
        'ml-IN',
        'mr-IN',
        'nl-NL',
        'pl-PL',
        'pt-BR',
        'ru-RU',
        'ta-IN',
        'te-IN',
        'th-TH',
        'tr-TR',
        'vi-VN',
    ];

    /** Performance style prompts for Arabic synthesis */
    private const ARABIC_STYLE_HINTS = [
        'neutral'        => 'Read the following Arabic text naturally with clear pronunciation and a balanced, professional tone. Maintain natural Arabic speech rhythm and pacing.',
        'warm'           => 'Speak this Arabic text with genuine heartfelt warmth, like a trusted friend sharing something meaningful. Let your smile come through your voice with rich, inviting tones.',
        'sad'            => 'Read this Arabic text with deep, aching sadness. Let your voice tremble slightly with raw emotion. Slow down and let sorrow fill the pauses. Almost whisper the most painful words.',
        'excited'        => 'Read this Arabic text BURSTING with infectious, high-energy excitement! Raise your voice with genuine enthusiasm on key words. Let your passion overflow and make the listener feel the thrill!',
        'formal'         => 'Deliver this Arabic text with impeccable formality and authoritative gravitas, like an official government spokesperson. Every syllable precise, measured, and commanding respect.',
        'storytelling'   => 'Narrate this Arabic text like a master storyteller captivating an audience around a fire. Use dramatic pauses, vary your pace, whisper the tense moments, and build suspense with every sentence.',
        'ad_style'       => 'Deliver this Arabic text as a high-energy, persuasive TV ad voiceover. PUNCH each selling point with explosive enthusiasm. Create urgency and make listeners want to act NOW!',
        'news'           => 'You are an urgent Arabic news anchor on live television. Deliver with sharp, commanding authority and rapid intensity. Deep, resonant voice with zero warmth \xe2\x80\x94 pure broadcast professionalism and urgency. Punch each headline hard.',
        'calm'           => 'Speak this Arabic text with serene, zen-like tranquility, like a meditation guide. Ultra-slow, barely above a whisper. Let peaceful stillness fill the gaps between phrases. Soothing and deeply relaxing.',
        'angry'          => 'Read this Arabic text with fierce, simmering anger and passionate intensity. Let righteous fury sharpen each word. Build tension with restrained power. RAISE your voice on the strongest points.',
        'educational'    => 'You are an enthusiastic Arabic professor BURSTING with passion for teaching. Speak with clear structure and infectious energy. Emphasize key concepts. Make learning feel like the most thrilling adventure.',
        'commercial'     => 'You are SHOUTING a flash-sale Arabic commercial! EXPLOSIVE energy, wild enthusiasm! Rush through words like the deal expires in seconds. Maximum hype, maximum urgency, maximum volume!',
        'promo'          => 'Deliver this Arabic text as a powerful, cinematic promotional piece. Build from a firm opening to a thundering crescendo. Each word polished and impactful. Make the audience feel they MUST act.',
        'podcast'        => 'You are a witty, charismatic Arabic podcast host. Natural, conversational, playfully engaging the audience. Quick pace with comedic timing and strategic pauses. Build intrigue like sharing a juicy secret.',
        'assistant'      => 'You are an upbeat, cheerful Arabic AI assistant overflowing with eagerness to help. Bubbly, warm, and genuinely enthusiastic. Quick, friendly delivery with a permanent beaming smile in your voice.',
        'narrative'      => 'Narrate this Arabic text like an epic audiobook. Use dramatic expression \xe2\x80\x94 whisper for tension, raise your voice for revelations. Paint vivid images with every word. Make the listener hang on each sentence.',
        'comedic'        => 'Deliver this Arabic text with sharp wit, mischievous humor, and perfect comedic timing. Add playful sarcasm and cheeky pauses. Sound like you are trying not to laugh at your own cleverness.',
        'corporate'      => 'Speak this Arabic text with stern, polished corporate authority. Formal, no-nonsense delivery. Deep, commanding tone that inspires absolute trust. Each word lands with boardroom gravitas.',
        'conversational' => 'Speak this Arabic text naturally and spontaneously, like chatting with a close friend over coffee. Relaxed, genuine, with natural laughter moments and real human warmth bleeding through.',
        'inspirational'  => 'You are a FIERY Arabic motivational speaker at a packed stadium. Start intense and build to a SHOUTING crescendo. Voice cracking with raw passion. Commanding, relentless, like addressing an army before battle!',
        'cinematic'      => 'Deliver this Arabic text with sweeping cinematic grandeur and emotional depth. Speak with thunderous majesty, extremely slow and dramatic. Let every word resonate like a historical revelation.',
        'documentary'    => 'Narrate this Arabic text like an epic documentary. Speak with deep, authoritative gravitas and sweeping grandeur. Measured pace with dramatic weight behind every phrase. Paint the scene with your voice.',
        'sports_commentator' => 'You are an ELECTRIC Arabic sports commentator during the most intense football match! EXPLODE with energy on every play! Build tension, SCREAM the goals with raw passion! Let your voice crack with excitement! GOOOOOAL!',
        'meditation'     => 'Speak this Arabic text with profound serenity and inner peace, like a spiritual guide leading a meditation. Ultra-slow, gentle, hypnotic rhythm. Let each word float softly. Create deep tranquility and mindfulness.',
        'audiobook'      => 'Narrate this Arabic text as a professional audiobook narrator. Clear, engaging, with subtle emotional coloring. Vary pace for drama, maintain clarity, and bring characters to life with distinct vocal textures.',
        'children_story' => 'Read this Arabic text like a beloved storyteller enchanting young children. Warm, playful, with wonder in your voice. Use animated expressions, fun sound effects hints, and make every moment magical and exciting!',
        'radio_host'     => 'You are a charismatic Arabic radio host between songs. Smooth, warm, effortlessly cool delivery. Quick wit, playful energy, and that signature radio sparkle that keeps listeners tuned in.',
        'hopeful'        => 'Speak this Arabic text with genuine hope and optimism shining through. Let your voice lift with possibility and promise. Bright, uplifting tones that inspire belief in better days ahead.',
        'serious'        => 'Deliver this Arabic text with grave seriousness and weight. Deep, measured tones that convey the importance of every word. No lightness — pure gravity and significance.',
        'playful'        => 'Read this Arabic text with infectious playfulness and lighthearted joy. Bouncy, fun delivery with a mischievous twinkle. Make listeners smile with your delightful, carefree energy.',
        'mysterious'     => 'Speak this Arabic text with an air of mystery and intrigue. Low, hushed tones that hint at secrets. Slow, deliberate pacing that builds suspense and keeps listeners on edge.',
        'confident'      => 'Deliver this Arabic text with unwavering confidence and authority. Strong, assured tones that command attention. Speak like someone who knows exactly what they are talking about.',
        'movie_trailer' => 'Deliver this Arabic text like an epic movie-trailer voiceover: deep, resonant, with dramatic pauses between lines and every phrase landing with weight.',
        'poetry' => 'Recite this Arabic text like a poet performing on stage: musical rhythm, deliberate pauses at line breaks, and rich emotional emphasis on key images.',
        'bedtime_story' => 'Read this Arabic text like a parent telling a bedtime story: soft, slow, soothing, and warm, growing gentler toward the end.',
        'customer_service' => 'Speak this Arabic text like a friendly, professional customer-service agent: clear, patient, polite, and reassuring, with a smile in the voice.',
        'phone_system' => 'Speak this Arabic text like a phone-system announcement: clear, evenly paced, neutral, and polite, with every number and option easy to follow.',
        'game_narrator' => 'Narrate this Arabic text like a video-game narrator: epic, immersive, and energetic, building excitement for what comes next.',
        'asmr' => 'Speak this Arabic text in a very soft, close, intimate whisper, slow and gentle, as in an ASMR recording.',
        'event_host' => 'Host this Arabic text like a charismatic MC at a live event: loud, warm, and energetic, welcoming the crowd and building moments for applause.',
        'tour_guide' => 'Speak this Arabic text like an enthusiastic tour guide: friendly, informative, and vivid, pointing out details with curiosity.',
        'true_crime' => 'Narrate this Arabic text like a true-crime podcast host: low, measured, and suspenseful, revealing each detail carefully.',
        'luxury_brand' => 'Speak this Arabic text like a luxury-brand advertisement: smooth, slow, elegant, and refined, with understated confidence.',
    ];

    public function name(): string
    {
        return 'gemini';
    }

    public function supportsDialogue(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return count($this->getKeyManager()->getAllKeys()) > 0;
    }

    /**
     * Get an available API key via GeminiKeyManager.
     *
     * @return array{key: string, name: string, index: int}
     * @throws \RuntimeException if no keys available
     */
    private function getAvailableKey(): array
    {
        $keyInfo = $this->getKeyManager()->getAvailableKey($this->currentModel)
            ?? ($this->getKeyManager()->keysInOrder($this->currentModel)[0] ?? null);
        if ($keyInfo === null) {
            throw new \RuntimeException('All Gemini API keys exhausted. Please add more keys or wait for rate limits to reset.');
        }
        $this->currentKeyInfo = $keyInfo;
        return $keyInfo;
    }

    /**
     * Mark current key as exhausted and try to get next available.
     *
     * @return bool True if another key is available
     */
    private function rotateToNextKey(array $tried = []): bool
    {
        if ($this->currentKeyInfo !== null) {
            $this->getKeyManager()->markKeyExhausted($this->currentKeyInfo['name']);
            $tried[] = $this->currentKeyInfo['name'];
        }

        // A key with room first; failing that, any key not tried yet for this
        // request (a cut-off key may have its quota back) before giving up.
        $keyInfo = $this->getKeyManager()->getAvailableKey($this->currentModel);
        if ($keyInfo === null || in_array($keyInfo['name'], $tried, true)) {
            $keyInfo = null;
            foreach ($this->getKeyManager()->keysInOrder($this->currentModel) as $candidate) {
                if (! in_array($candidate['name'], $tried, true)) {
                    $keyInfo = $candidate;
                    break;
                }
            }
        }
        if ($keyInfo === null) {
            return false;
        }

        $this->currentKeyInfo = $keyInfo;
        return true;
    }

    public function synthesize(string $text, string $targetLanguage, array $voiceConfig = []): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('TTS text is empty.');
        }

        $charCount = mb_strlen($text);
        $startTime = microtime(true);

        // Over the daily ceiling this throws, and routing falls back to Google if allowed.
        $guardrail = app(TtsCostGuardrail::class);
        $guardrail->assertCanSynthesize($this->name(), $charCount);

        if (!$this->isAvailable()) {
            throw new \RuntimeException('Gemini TTS API key is not configured.');
        }

        // Get an available key (throws if all exhausted)
        $model = GeminiTtsModel::resolve();
        $this->currentModel = $model;
        $this->currentKeyInfo = null;
        $keyInfo = $this->getAvailableKey();

        $speakers = $voiceConfig['speakers'] ?? null;
        $isDialogue = is_array($speakers) && count($speakers) > 1;

        // Sound tags: <laugh> for Gemini 3.8, [laugh] for the older prompt model.
        // Delivery tags ([excited]) split a single voice's script into parts,
        // each read with its own direction; in a dialogue they apply to the whole script.
        if (GeminiTtsModel::usesStructuredSpeech($model)) {
            if ($isDialogue) {
                $prepared = GeminiVocalTags::prepare($text);
                $text = $prepared['text'];
                $voiceConfig['vocal_cues'] = $prepared['cues'];
            } else {
                // A dialect lives in its words as much as its accent: the script is
                // rewritten into the chosen Arabic variety first, as for cloned voices.
                $dialect = trim((string) ($voiceConfig['dialect'] ?? ''));
                if ($dialect !== '' && $this->isArabicLanguage($targetLanguage) && config('services.gemini_tts.dialect_rewrite', true)) {
                    $text = app(VoiceCloningService::class)->speakableText($text, $dialect);
                }
                $voiceConfig['vocal_parts'] = GeminiVocalTags::parts($text);
                $text = implode(' ', array_column($voiceConfig['vocal_parts'], 'text'));
            }
            if (trim(GeminiVocalTags::strip($text)) === '') {
                throw new \RuntimeException('TTS text is empty.');
            }
        } else {
            $text = GeminiVocalTags::forLegacyModel($text);
        }

        if ($isDialogue) {
            $result = $this->synthesizeDialogue($text, $targetLanguage, $speakers, $voiceConfig, $model);
        } else {
            $result = $this->synthesizeSingle($text, $targetLanguage, $voiceConfig, $model);
        }

        // Record successful usage for current key
        // Count it against the key that answered (it may have rotated).
        $this->getKeyManager()->recordUsage(($this->currentKeyInfo ?? $keyInfo)['name'], $charCount);
        $guardrail->record($this->name(), $charCount);

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        return array_merge($result, [
            'provider' => $this->name(),
            'model' => $model,
            'latency_ms' => $latencyMs,
            'char_count' => $charCount,
            'cost_estimate_usd' => $this->estimateCost($charCount),
        ]);
    }

    /**
     * Single-speaker synthesis using Gemini TTS.
     */
    private function synthesizeSingle(string $text, string $targetLanguage, array $voiceConfig, string $model): array
    {
        $voiceName = $this->resolveGeminiVoice(
            (string) ($voiceConfig['voice_name'] ?? ''),
            $voiceConfig['gender'] ?? null,
        );
        $performanceStyle = trim((string) ($voiceConfig['performance_style'] ?? $voiceConfig['style'] ?? 'neutral'));
        $emotion = trim((string) ($voiceConfig['emotion'] ?? 'neutral'));
        $speed = (float) ($voiceConfig['speed'] ?? 1.0);
        $languageCode = $this->resolveLanguageCode(
            $targetLanguage,
            isset($voiceConfig['dialect']) && is_string($voiceConfig['dialect']) ? $voiceConfig['dialect'] : null,
        );

        // Use custom style_prompt if provided, otherwise build from dialect, emotion, style, speed, and pitch
        $customPrompt = trim((string) ($voiceConfig['style_prompt'] ?? ''));
        $dialect = isset($voiceConfig['dialect']) && is_string($voiceConfig['dialect']) ? trim($voiceConfig['dialect']) : null;
        $pitch = (float) ($voiceConfig['pitch'] ?? 0.0);
        $stylePrompt = $customPrompt !== ''
            ? $customPrompt
            : $this->buildStylePrompt(
                $performanceStyle,
                $emotion,
                $targetLanguage,
                $speed,
                $dialect,
                $pitch,
                GeminiTtsModel::usesStructuredSpeech($model),
            );
        $stylePrompt = GeminiVocalTags::withCues($stylePrompt, $voiceConfig['vocal_cues'] ?? []);
        $endpoint = $this->buildEndpoint($model);

        // Long outputs end with finishReason OTHER and no audio, so long
        // texts are read in pieces and the audio is joined.
        $maxChars = max(200, (int) config('services.gemini_tts.max_chars_per_request', 900));
        $payloads = [];
        foreach ($voiceConfig['vocal_parts'] ?? [['text' => $text, 'cues' => []]] as $part) {
            $partStyle = GeminiVocalTags::withCues($stylePrompt, $part['cues']);
            $chunks = mb_strlen($part['text']) > $maxChars ? TextChunker::split($part['text'], $maxChars, 'mb_strlen') : [$part['text']];
            foreach ($chunks as $chunk) {
                $performed = GeminiVocalTags::forPerformance($chunk, $partStyle);
                $payloads[] = $this->buildSingleSpeakerPayload($model, $performed['text'], $performed['style'], $voiceName, $languageCode);
            }
        }

        $audio = count($payloads) === 1
            ? $this->requestAudio($endpoint, $payloads[0])
            : $this->joinAudioParts($this->requestAudioParts($endpoint, $payloads));
        $filename = $this->saveAudioFile($audio['bytes'], $audio['mime_type']);

        return [
            'audio_path' => $filename,
            'duration_seconds' => $this->estimateDurationSeconds($text),
        ];
    }

    /**
     * Send the pieces in parallel batches. A piece that fails is sent again
     * on its own, with the usual retries and key rotation.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array{bytes:string,mime_type:?string}>
     */
    private function requestAudioParts(string $endpoint, array $payloads): array
    {
        $timeout = (int) config('services.gemini_tts.timeout_seconds', 60);
        $verifySsl = (bool) config('services.gemini_tts.verify_ssl', true);
        $parallel = max(1, (int) config('services.gemini_tts.max_parallel_requests', 4));
        $retryBudgetSeconds = max(0, (int) config('services.gemini_tts.retry_budget_seconds', 20));
        $keyInfo = $this->currentKeyInfo ?? $this->getAvailableKey();
        $startedAt = microtime(true);
        $parts = [];

        foreach (array_chunk($payloads, $parallel, true) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $endpoint, $timeout, $verifySsl, $keyInfo) {
                foreach ($batch as $index => $payload) {
                    $pool->as('part' . $index)
                        ->timeout($timeout)
                        ->withOptions(['verify' => $verifySsl])
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'x-goog-api-key' => $keyInfo['key'],
                        ])
                        ->post($endpoint, $payload);
                }
            });

            foreach ($batch as $index => $payload) {
                $response = $responses['part' . $index] ?? null;
                $error = $response instanceof \Throwable ? $response->getMessage() : null;

                if ($response instanceof Response && $response->ok() && is_array($response->json())) {
                    try {
                        $parts[$index] = $this->extractAudioFromResponse($response->json());
                        continue;
                    } catch (TransientGeminiException $e) {
                        $error = $e->getMessage();
                    }
                } elseif ($response instanceof Response) {
                    $error = 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 200);
                }

                $elapsed = microtime(true) - $startedAt;
                Log::warning('Gemini TTS piece failed.', [
                    'piece' => $index + 1,
                    'pieces' => count($payloads),
                    'elapsed_seconds' => round($elapsed, 1),
                    'error' => mb_substr((string) $error, 0, 300),
                ]);
                if ($elapsed >= $retryBudgetSeconds && !($response instanceof Response && $response->status() === 429)) {
                    throw new TransientGeminiException('Gemini TTS piece ' . ($index + 1) . ' of ' . count($payloads) . ' failed: ' . mb_substr((string) $error, 0, 200));
                }

                $parts[$index] = $this->requestAudio($endpoint, $payload);
            }
        }

        for ($i = 1; $i < count($payloads); $i++) {
            $this->getKeyManager()->recordUsage($keyInfo['name'], 0);
        }

        ksort($parts);

        return $parts;
    }

    /**
     * Join the pieces into one WAV with a short pause between them.
     *
     * @param  array<int, array{bytes:string,mime_type:?string}>  $parts
     * @return array{bytes:string,mime_type:string}
     */
    private function joinAudioParts(array $parts): array
    {
        $types = array_map(fn(array $part) => $this->detectAudioMimeType($part['bytes'], $part['mime_type']), $parts);
        if (count(array_unique($types)) === 1 && reset($types) === 'audio/mpeg') {
            return ['bytes' => implode('', array_column($parts, 'bytes')), 'mime_type' => 'audio/mpeg'];
        }

        $pcm = '';
        $sampleRate = null;
        $channels = 1;
        foreach (array_values($parts) as $i => $part) {
            $decoded = $this->decodePcm($part['bytes'], $part['mime_type']);
            $sampleRate ??= $decoded['sample_rate'];
            $channels = $decoded['channels'];
            if ($i > 0) {
                $pcm .= str_repeat("\0", (int) round($sampleRate * 0.3) * 2 * $channels);
            }
            $samples = $decoded['pcm'];
            $pcm .= strlen($samples) % 2 === 0 ? $samples : substr($samples, 0, -1);
        }

        return [
            'bytes' => $this->wrapPcmL16AsWav($pcm, $sampleRate ?? 24000, $channels, 16),
            'mime_type' => 'audio/wav',
        ];
    }

    /**
     * @return array{pcm:string,sample_rate:int,channels:int}
     */
    private function decodePcm(string $data, ?string $mimeTypeHint): array
    {
        $sampleRate = preg_match('/rate=(\d+)/i', (string) $mimeTypeHint, $m) ? (int) $m[1] : 24000;
        $channels = 1;

        if ($this->detectAudioMimeType($data, $mimeTypeHint) !== 'audio/wav') {
            return ['pcm' => $data, 'sample_rate' => $sampleRate, 'channels' => $channels];
        }

        $pcm = '';
        $offset = 12;
        while ($offset + 8 <= strlen($data)) {
            $id = substr($data, $offset, 4);
            $size = unpack('V', substr($data, $offset + 4, 4))[1];
            if ($id === 'fmt ' && $size >= 16) {
                $channels = unpack('v', substr($data, $offset + 10, 2))[1] ?: 1;
                $sampleRate = unpack('V', substr($data, $offset + 12, 4))[1] ?: $sampleRate;
            } elseif ($id === 'data') {
                $pcm .= substr($data, $offset + 8, $size);
            }
            $offset += 8 + $size + ($size % 2);
        }

        return ['pcm' => $pcm, 'sample_rate' => $sampleRate, 'channels' => $channels];
    }

    /**
     * Multi-speaker dialogue synthesis using Gemini TTS.
     */
    private function synthesizeDialogue(string $text, string $targetLanguage, array $speakers, array $voiceConfig, string $model): array
    {
        $endpoint = $this->buildEndpoint($model);
        $performanceStyle = trim((string) ($voiceConfig['performance_style'] ?? $voiceConfig['style'] ?? 'neutral'));
        $emotion = trim((string) ($voiceConfig['emotion'] ?? 'neutral'));
        $speed = (float) ($voiceConfig['speed'] ?? 1.0);
        $pitch = (float) ($voiceConfig['pitch'] ?? 0.0);
        $dialect = isset($voiceConfig['dialect']) && is_string($voiceConfig['dialect']) ? trim($voiceConfig['dialect']) : null;
        $languageCode = $this->resolveLanguageCode($targetLanguage, $dialect);

        $customPrompt = trim((string) ($voiceConfig['style_prompt'] ?? ''));
        $stylePrompt = $customPrompt !== ''
            ? $customPrompt
            : $this->buildStylePrompt(
                $performanceStyle,
                $emotion,
                $targetLanguage,
                $speed,
                $dialect,
                $pitch,
                GeminiTtsModel::usesStructuredSpeech($model),
            );
        $stylePrompt = GeminiVocalTags::withCues($stylePrompt, $voiceConfig['vocal_cues'] ?? []);
        if (GeminiTtsModel::usesStructuredSpeech($model)) {
            $performed = GeminiVocalTags::forPerformance($text, $stylePrompt);
            [$text, $stylePrompt] = [$performed['text'], $performed['style']];
        }
        $payload = $this->buildDialoguePayload($model, $text, $stylePrompt, $speakers, $languageCode);

        $audio = $this->requestAudio($endpoint, $payload);
        $filename = $this->saveAudioFile($audio['bytes'], $audio['mime_type']);

        return [
            'audio_path' => $filename,
            'duration_seconds' => $this->estimateDurationSeconds($text),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSingleSpeakerPayload(
        string $model,
        string $text,
        string $stylePrompt,
        string $voiceName,
        string $languageCode,
    ): array {
        if (GeminiTtsModel::usesStructuredSpeech($model)) {
            $part = ['text' => $text];
            if (trim($stylePrompt) !== '') {
                $part['speech_metadata'] = ['style' => $stylePrompt];
            }

            return [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [$part],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => [
                        'voiceConfig' => [
                            'voice' => $voiceName,
                        ],
                    ],
                ],
            ];
        }

        return [
            'contents' => [
                [
                    'parts' => [
                        ['text' => trim($stylePrompt."\n\n".$text)],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'languageCode' => $languageCode,
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => [
                            'voiceName' => $voiceName,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $speakers
     * @return array<string, mixed>
     */
    private function buildDialoguePayload(
        string $model,
        string $text,
        string $stylePrompt,
        array $speakers,
        string $languageCode,
    ): array {
        $speakerVoiceConfigs = [];
        foreach ($speakers as $speaker) {
            $speakerName = trim((string) ($speaker['name'] ?? $speaker['id'] ?? 'Speaker'));
            if ($speakerName === '') {
                $speakerName = 'Speaker';
            }

            $speakerVoiceConfigs[] = [
                'speaker' => $speakerName,
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [
                        'voiceName' => $this->resolveGeminiVoice(
                            (string) ($speaker['voice_name'] ?? $speaker['voice'] ?? ''),
                            $speaker['gender'] ?? null,
                        ),
                    ],
                ],
            ];
        }

        $parts = GeminiTtsModel::usesStructuredSpeech($model)
            ? $this->structuredDialogueParts($text, $speakers, $stylePrompt)
            : [['text' => trim($stylePrompt."\n\n".$text)]];

        $speechConfig = [
            'multiSpeakerVoiceConfig' => [
                'speakerVoiceConfigs' => $speakerVoiceConfigs,
            ],
        ];
        if (! GeminiTtsModel::usesStructuredSpeech($model)) {
            $speechConfig = ['languageCode' => $languageCode] + $speechConfig;
        }

        return [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => $speechConfig,
            ],
        ];
    }

    /**
     * Split a script into verbatim turns so Gemini 3.8 does not read speaker labels aloud.
     *
     * @param  array<int, array<string, mixed>>  $speakers
     * @return array<int, array<string, mixed>>
     */
    private function structuredDialogueParts(string $text, array $speakers, string $stylePrompt): array
    {
        $names = [];
        foreach ($speakers as $speaker) {
            $name = trim((string) ($speaker['name'] ?? $speaker['id'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $currentSpeaker = $names[0] ?? 'Speaker';
        $parts = [];
        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $spoken = $line;
            foreach ($names as $name) {
                $prefix = $name.':';
                if (str_starts_with($line, $prefix)) {
                    $currentSpeaker = $name;
                    $spoken = trim(substr($line, strlen($prefix)));
                    break;
                }
            }

            if ($spoken === '') {
                continue;
            }

            $part = ['text' => $spoken];
            $metadata = ['speaker' => $currentSpeaker];
            if (trim($stylePrompt) !== '') {
                $metadata['style'] = $stylePrompt;
            }
            $part['speech_metadata'] = $metadata;
            $parts[] = $part;
        }

        if ($parts !== []) {
            return $parts;
        }

        $part = ['text' => $text, 'speech_metadata' => ['speaker' => $currentSpeaker]];
        if (trim($stylePrompt) !== '') {
            $part['speech_metadata']['style'] = $stylePrompt;
        }

        return [$part];
    }

    /**
     * Build the Gemini API endpoint URL.
     */
    private function buildEndpoint(string $model): string
    {
        $baseUrl = trim((string) config(
            'services.gemini_tts.endpoint',
            'https://generativelanguage.googleapis.com/v1beta'
        ));

        return rtrim($baseUrl, '/').'/models/'.$model.':generateContent';
    }

    /**
     * Send the request and return its audio, retrying transient failures.
     *
     * @return array{bytes:string,mime_type:?string}
     */
    private function requestAudio(string $endpoint, array $payload): array
    {
        $retries = max(0, (int) config('services.gemini_tts.transient_retries', 2));
        // A slow failure would push the dashboard request past the web
        // server timeout if repeated; let the Google fallback take over instead.
        $retryBudgetSeconds = max(0, (int) config('services.gemini_tts.retry_budget_seconds', 20));
        $startedAt = microtime(true);

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->extractAudioFromResponse($this->callGeminiApi($endpoint, $payload));
            } catch (TransientGeminiException $e) {
                $elapsed = microtime(true) - $startedAt;
                if ($attempt >= $retries || $elapsed >= $retryBudgetSeconds) {
                    throw $e;
                }

                Log::warning('Gemini TTS transient failure, retrying.', [
                    'attempt' => $attempt + 1,
                    'elapsed_seconds' => round($elapsed, 1),
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);

                $pauseMs = max(0, (int) config('services.gemini_tts.retry_pause_ms', 600));
                if ($pauseMs > 0) {
                    usleep($pauseMs * 1000 * ($attempt + 1));
                }
            }
        }
    }

    /**
     * Call the Gemini API with automatic API-key rotation on 429 errors.
     */
    private function callGeminiApi(string $endpoint, array $payload): array
    {
        $timeout = (int) config('services.gemini_tts.timeout_seconds', 60);
        $verifySsl = (bool) config('services.gemini_tts.verify_ssl', true);
        $maxAttempts = count($this->getKeyManager()->getAllKeys());
        $attempts = 0;
        $tried = [];

        while ($attempts < $maxAttempts) {
            // Get current available key (already set in synthesize, or get a new one)
            if ($this->currentKeyInfo === null) {
                $keyInfo = $this->getAvailableKey();
            } else {
                $keyInfo = $this->currentKeyInfo;
            }
            $apiKey = $keyInfo['key'];
            $attempts++;

            try {
                $response = $this->sendWithSslFallback(
                    fn(bool $verify) => Http::timeout($timeout)
                        ->withOptions(['verify' => $verify])
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'x-goog-api-key' => $apiKey,
                        ])
                        ->post($endpoint, $payload),
                    $verifySsl,
                    'Gemini TTS API request',
                );
            } catch (\Throwable $e) {
                Log::error('Gemini TTS API call failed', [
                    'error' => $e->getMessage(),
                    'endpoint' => $endpoint,
                    'key' => $keyInfo['name'],
                ]);
                throw new \RuntimeException('Gemini TTS API call failed: ' . $e->getMessage(), 0, $e);
            }

            // A used-up daily quota stays skipped until Google resets it.
            if (GeminiKeyManager::isDailyQuotaError($response->status(), (string) $response->body())) {
                $this->getKeyManager()->markOutForToday(
                    $keyInfo['name'],
                    GeminiKeyManager::modelFromPath($endpoint, (string) ($this->currentModel ?? GeminiTtsModel::resolve())),
                );
            }

            // A key Google refuses (invalid, expired, blocked) is set aside for
            // today on this model, and the request moves to the next key.
            $badKey = GeminiKeyManager::isKeyProblem($response->status(), (string) $response->body());
            if ($badKey) {
                Log::error('Gemini key refused by Google; using the other keys.', [
                    'key' => $keyInfo['name'],
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                ]);
                $this->getKeyManager()->markOutForToday(
                    $keyInfo['name'],
                    GeminiKeyManager::modelFromPath($endpoint, (string) ($this->currentModel ?? GeminiTtsModel::resolve())),
                );
            }

            // On 429 or a refused key, rotate to next key and retry
            if (($response->status() === 429 || $badKey) && $attempts < $maxAttempts) {
                Log::warning('Gemini TTS key hit 429, rotating to next key.', [
                    'key' => $keyInfo['name'],
                    'attempts' => $attempts,
                ]);

                $tried[] = $keyInfo['name'];
                if (!$this->rotateToNextKey($tried)) {
                    break; // No more keys available
                }
                continue;
            }

            if ($response->serverError()) {
                Log::warning('Gemini TTS API returned a server error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                    'key' => $keyInfo['name'],
                ]);
                throw new TransientGeminiException('Gemini TTS request failed (HTTP ' . $response->status() . '): ' . mb_substr($response->body(), 0, 200));
            }

            if (!$response->ok()) {
                Log::error('Gemini TTS API returned error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                    'key' => $keyInfo['name'],
                ]);
                throw new \RuntimeException('Gemini TTS request failed (HTTP ' . $response->status() . '): ' . mb_substr($response->body(), 0, 200));
            }

            $json = $response->json();
            if (!is_array($json)) {
                throw new \RuntimeException('Gemini TTS returned invalid JSON response.');
            }

            return $json;
        }

        // All keys exhausted
        throw new \RuntimeException('All Gemini TTS API keys exhausted (429 rate limit). Add more keys or wait for limits to reset.');
    }

    /**
     * Extract raw audio bytes from Gemini API response.
     */
    private function extractAudioFromResponse(array $response): array
    {
        $blockReason = $response['promptFeedback']['blockReason'] ?? null;
        if (is_string($blockReason) && $blockReason !== '') {
            throw new \RuntimeException('Gemini TTS blocked the prompt: ' . $blockReason);
        }

        $candidates = $response['candidates'] ?? [];
        if (!is_array($candidates) || empty($candidates)) {
            throw new TransientGeminiException('Gemini TTS response contains no candidates.');
        }

        $finishReason = (string) ($candidates[0]['finishReason'] ?? '');
        if (in_array($finishReason, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII'], true)) {
            throw new \RuntimeException('Gemini TTS refused the text (finishReason ' . $finishReason . ').');
        }

        $parts = $candidates[0]['content']['parts'] ?? [];
        if (!is_array($parts)) {
            throw new TransientGeminiException('Gemini TTS response contains no parts (finishReason ' . ($finishReason ?: 'none') . ').');
        }

        foreach ($parts as $part) {
            if (isset($part['inlineData']['data'])) {
                $decoded = base64_decode($part['inlineData']['data'], true);
                if ($decoded !== false && $decoded !== '') {
                    $mimeType = isset($part['inlineData']['mimeType']) && is_string($part['inlineData']['mimeType'])
                        ? trim($part['inlineData']['mimeType'])
                        : null;

                    return [
                        'bytes' => $decoded,
                        'mime_type' => $mimeType,
                    ];
                }
            }
        }

        throw new TransientGeminiException('Gemini TTS response contains no audio data (finishReason ' . ($finishReason ?: 'none') . ').');
    }

    /**
     * Save raw audio bytes to disk and return the path.
     */
    private function saveAudioFile(string $audioData, ?string $mimeTypeHint = null): string
    {
        // Gemini can return raw PCM (L16); detect and wrap as WAV when needed.
        $mimeType = $this->detectAudioMimeType($audioData, $mimeTypeHint);
        if ($mimeType === 'audio/l16') {
            $audioData = $this->wrapPcmL16AsWav($audioData, 24000, 1, 16);
            $mimeType = 'audio/wav';
        }
        $packaged = ShareableAudio::package($audioData);
        $audioData = $packaged['bytes'];
        $extension = $packaged['extension'];

        $filename = 'audio/' . now()->format('Ymd_His') . '_listen.studio_' . Str::random(12) . '.' . $extension;
        $disk = Storage::disk('public');

        if (!$disk->exists('audio')) {
            $disk->makeDirectory('audio');
        }

        $disk->put($filename, $audioData);

        return $filename;
    }

    /**
     * Detect audio MIME type from raw bytes.
     */
    private function detectAudioMimeType(string $data, ?string $hint = null): string
    {
        if (strlen($data) >= 12 && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WAVE') {
            return 'audio/wav';
        }

        if ($this->looksLikeMpegAudio($data)) {
            return 'audio/mpeg';
        }

        $normalizedHint = strtolower(trim((string) $hint));
        if ($normalizedHint !== '') {
            if (str_starts_with($normalizedHint, 'audio/wav') || str_starts_with($normalizedHint, 'audio/x-wav')) {
                return 'audio/wav';
            }
            if (str_starts_with($normalizedHint, 'audio/mpeg') || str_starts_with($normalizedHint, 'audio/mp3')) {
                return 'audio/mpeg';
            }
            if (str_starts_with($normalizedHint, 'audio/l16') || str_starts_with($normalizedHint, 'audio/pcm')) {
                return 'audio/l16';
            }
        }

        // Gemini 2.5 commonly returns raw 16-bit PCM (audio/L16) in inlineData.
        return 'audio/l16';
    }

    /**
     * PCM can start with 0xFFFF, which matches a loose MPEG sync mask.
     * Require a real frame header so that sample is saved as WAV.
     */
    private function looksLikeMpegAudio(string $data): bool
    {
        if (strlen($data) >= 3 && substr($data, 0, 3) === 'ID3') {
            return true;
        }
        if (strlen($data) < 4 || ord($data[0]) !== 0xFF || (ord($data[1]) & 0xE0) !== 0xE0) {
            return false;
        }

        $version = (ord($data[1]) >> 3) & 0x03;
        $layer = (ord($data[1]) >> 1) & 0x03;
        $bitrate = (ord($data[2]) >> 4) & 0x0F;
        $sampleRate = (ord($data[2]) >> 2) & 0x03;

        return $version !== 0x01
            && $layer !== 0x00
            && $bitrate !== 0x00
            && $bitrate !== 0x0F
            && $sampleRate !== 0x03;
    }

    private function wrapPcmL16AsWav(
        string $pcmData,
        int $sampleRate = 24000,
        int $channels = 1,
        int $bitsPerSample = 16
    ): string {
        if ((strlen($pcmData) % 2) !== 0) {
            $pcmData = substr($pcmData, 0, -1);
        }

        $dataLength = strlen($pcmData);
        $byteRate = (int) ($sampleRate * $channels * ($bitsPerSample / 8));
        $blockAlign = (int) ($channels * ($bitsPerSample / 8));

        return 'RIFF'
            . pack('V', 36 + $dataLength)
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)
            . pack('v', 1)
            . pack('v', $channels)
            . pack('V', $sampleRate)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bitsPerSample)
            . 'data'
            . pack('V', $dataLength)
            . $pcmData;
    }

    /**
     * Build a comprehensive style prompt for Gemini TTS.
     *
     * Combines dialect, performance style, emotion, speed, and pitch into a single
     * instruction like: "Voice dialect: Egyptian Arabic. Emotional tone: warm, friendly."
     *
     * Note: Gemini TTS doesn't have explicit speed/pitch API parameters like Google Cloud TTS.
     * Instead, these are controlled via natural language instructions in the prompt.
     */
    private function buildStylePrompt(string $style, string $emotion, string $targetLanguage, float $speed, ?string $dialect = null, float $pitch = 0.0, bool $structuredSpeech = false): string
    {
        $style = strtolower(trim($style));
        $emotion = strtolower(trim($emotion));
        $isArabic = $this->isArabicLanguage($targetLanguage);

        // Build dialect instruction for Arabic
        $dialectInstruction = '';
        if ($isArabic && $dialect !== null && $dialect !== '') {
            $dialectName = self::ARABIC_DIALECT_PROMPTS[$dialect] ?? ArabicDialects::prompt($dialect);
            if ($dialectName !== null) {
                $dialectInstruction = "Voice dialect: {$dialectName}. Speak with authentic regional pronunciation and natural dialect characteristics. ";
                $markers = ArabicDialects::markers($dialect);
                if ($markers !== null) {
                    $dialectInstruction .= "Dialect markers to make clearly audible: {$markers} ";
                }
            } else {
                $dialectInstruction = "Voice dialect/language variant: {$dialect}. Keep pronunciation consistent with this dialect setting. ";
            }
        }

        // Build emotion description
        $emotionDesc = $this->getEmotionDescription($emotion, $isArabic);

        // Build style description
        $styleDesc = $this->getPerformanceStyleDescription($style, $isArabic);

        // Combine into comprehensive prompt
        $prompt = '';

        if ($dialectInstruction !== '') {
            $prompt .= $dialectInstruction;
        }

        if ($emotion !== 'neutral') {
            $normalizedEmotionDesc = trim((string) $emotionDesc);
            if ($normalizedEmotionDesc === '') {
                $normalizedEmotionDesc = str_replace(['_', '-'], ' ', $emotion);
            }
            $prompt .= "Emotional tone: {$normalizedEmotionDesc}. ";
        }

        if ($styleDesc !== '') {
            $prompt .= $styleDesc;
        }

        // Add speed instruction (Gemini uses prompt-based control, not API parameters)
        if ($speed <= 0.6) {
            $prompt .= ' Speak very slowly and deliberately, taking pauses between phrases.';
        } elseif ($speed < 0.85) {
            $prompt .= ' Speak at a slow, measured pace.';
        } elseif ($speed > 1.5) {
            $prompt .= ' Speak very quickly with rapid, energetic pacing.';
        } elseif ($speed > 1.2) {
            $prompt .= ' Speak at a faster pace with energetic delivery.';
        }

        // Add pitch instruction (Gemini uses prompt-based control, not API parameters)
        if ($pitch <= -10) {
            $prompt .= ' Use a very deep, low-pitched voice.';
        } elseif ($pitch < -3) {
            $prompt .= ' Use a deeper, lower-pitched voice.';
        } elseif ($pitch >= 10) {
            $prompt .= ' Use a very high-pitched, bright voice.';
        } elseif ($pitch > 3) {
            $prompt .= ' Use a higher-pitched, brighter voice.';
        }

        // Fallback if prompt is empty
        if (trim($prompt) === '') {
            $prompt = $isArabic
                ? 'Read the following Arabic text naturally with clear pronunciation and a balanced, professional tone.'
                : 'Read the following text naturally with clear pronunciation and a balanced, professional tone.';
        }

        $dialectCode = strtoupper(trim((string) $dialect));
        if ($isArabic && $dialectCode !== '' && $dialectCode !== 'AR-XA') {
            $prompt .= $dialectCode === 'AR-EG'
                ? ' Stay in Cairene Egyptian colloquial Arabic, not Modern Standard Arabic. Pronounce ج like the English g, and use Cairo rhythm, the way someone from Cairo talks to a friend. Do not switch to formal Fusha or news-anchor pronunciation.'
                : ' Stay in this regional colloquial variety. Do not switch to Modern Standard Arabic or news-anchor Fusha.';
        }

        // Gemini 3.8 does not accept languageCode on a single-speaker request.
        // The English accent has to travel in the style text, or American,
        // British, and Australian selections all sound the same.
        if ($structuredSpeech && ! $isArabic) {
            $accentName = match ($dialectCode) {
                'EN-US' => 'American English',
                'EN-GB' => 'British English',
                'EN-AU' => 'Australian English',
                default => (string) EnglishAccents::prompt($dialectCode),
            };
            if ($accentName !== '') {
                $prompt .= " Voice accent: {$accentName}. Keep this accent consistent and do not switch to a different English accent.";
            }
        }

        return trim($prompt);
    }

    /**
     * Check if the target language is Arabic (any dialect).
     */
    private function isArabicLanguage(string $targetLanguage): bool
    {
        $lang = strtolower(trim($targetLanguage));

        // Check if it starts with 'ar' (covers ar, ar-XA, ar-EG, ar-SA, etc.)
        if (str_starts_with($lang, 'ar')) {
            return true;
        }

        return false;
    }

    /**
     * Get human-readable emotion description for the style prompt.
     */
    private function getEmotionDescription(string $emotion, bool $isArabic): string
    {
        $customInstruction = trim((string) TtsStudioConfig::emotionInstruction($emotion, $isArabic ? 'ar' : 'en'));
        if ($customInstruction !== '') {
            return $customInstruction;
        }

        return match ($emotion) {
            'warm' => 'warm, friendly, and approachable',
            'sad' => 'melancholic, sorrowful, with deep emotional weight',
            'excited' => 'highly energetic, enthusiastic, and thrilling',
            'formal' => 'professional, authoritative, and dignified',
            'calm' => 'serene, peaceful, and deeply relaxing',
            'angry' => 'intense, passionate, with controlled fury',
            'joyful' => 'bright, joyful, and full of delight',
            'tender' => 'soft, tender, and caring, like speaking to someone you love',
            'empathetic' => 'understanding, gentle, and compassionate',
            'romantic' => 'intimate, soft, and romantic',
            'nostalgic' => 'wistful and nostalgic, remembering better days',
            'proud' => 'proud and dignified, with quiet triumph',
            'grateful' => 'sincerely grateful and appreciative',
            'determined' => 'firm, determined, and resolute',
            'anxious' => 'uneasy and anxious, with nervous tension',
            'fearful' => 'frightened and fearful, with a slightly trembling voice',
            'surprised' => 'genuinely surprised and amazed',
            'sarcastic' => 'dry and sarcastic, with ironic emphasis',
            'suspenseful' => 'tense and suspenseful, keeping the listener on edge',
            default => '',
        };
    }

    /**
     * Get the performance style instruction.
     */
    private function getPerformanceStyleDescription(string $style, bool $isArabic): string
    {
        // No style behaves like 'neutral' instead of producing "Performance style: ."
        if (trim($style) === '') {
            return '';
        }

        $customInstruction = trim((string) TtsStudioConfig::performanceStyleInstruction($style, $isArabic ? 'ar' : 'en'));
        if ($customInstruction !== '') {
            return $customInstruction;
        }

        if ($isArabic && isset(self::ARABIC_STYLE_HINTS[$style])) {
            return self::ARABIC_STYLE_HINTS[$style];
        }

        return match ($style) {
            'neutral'        => '',
            'warm'           => 'Speak with genuine heartfelt warmth, like a trusted friend sharing something meaningful. Let your smile come through your voice with rich, inviting tones.',
            'sad'            => 'Read with deep, aching sadness and raw vulnerability. Let your voice tremble slightly with emotion. Slow down and let sorrow fill the pauses between phrases.',
            'excited'        => 'Read BURSTING with infectious, high-energy excitement! Raise your voice with genuine enthusiasm on key words. Let your passion overflow!',
            'formal'         => 'Deliver with impeccable formality and authoritative gravitas, like an official spokesperson. Every syllable precise, measured, and commanding absolute respect.',
            'storytelling'   => 'Narrate like a master storyteller captivating an audience. Use dramatic pauses, vary your pace, whisper the tense moments, and build suspense with every sentence.',
            'ad_style'       => 'Deliver as a high-energy, persuasive TV ad voiceover! PUNCH each selling point with explosive enthusiasm. Create urgency and make listeners want to act NOW!',
            'news'           => 'You are an urgent breaking-news anchor on live television. Deliver with sharp, commanding authority and rapid intensity. Deep voice, zero warmth — pure broadcast professionalism.',
            'calm'           => 'Speak with serene, zen-like tranquility, like a meditation guide. Ultra-slow, barely above a whisper. Let peaceful stillness fill the gaps. Soothing and deeply relaxing.',
            'angry'          => 'Read with fierce, simmering anger and passionate intensity. Let righteous fury sharpen each word. Build tension with restrained power that could explode at any moment.',
            'educational'    => 'You are an enthusiastic professor BURSTING with passion for teaching. Clear, structured delivery with infectious energy. Emphasize key concepts. Make learning thrilling.',
            'commercial'     => 'You are SHOUTING a flash-sale commercial! EXPLOSIVE energy and wild enthusiasm! Rush through words like the deal expires NOW. Maximum hype, maximum urgency!',
            'promo'          => 'Deliver as a powerful, cinematic promotional piece. Build from a firm opening to a thundering crescendo. Each word polished and impactful. Make the audience feel compelled to act.',
            'podcast'        => 'You are a witty, charismatic podcast host. Natural, conversational, playfully engaging the audience. Quick pace with comedic timing and strategic pauses. Build intrigue like sharing a secret.',
            'assistant'      => 'You are an upbeat, cheerful AI assistant overflowing with eagerness to help. Bubbly, warm, genuinely enthusiastic. Quick, friendly delivery with a permanent beaming smile in your voice.',
            'narrative'      => 'Narrate like an epic audiobook. Use dramatic expression — whisper for tension, raise your voice for revelations. Paint vivid images with every word. Make listeners hang on each sentence.',
            'comedic'        => 'Deliver with sharp wit, mischievous humor, and perfect comedic timing. Add playful sarcasm and cheeky pauses. Sound like you are trying not to laugh at your own cleverness.',
            'corporate'      => 'Speak with stern, polished corporate authority. Formal, no-nonsense delivery. Deep, commanding tone that inspires absolute trust. Each word lands like a gavel in the boardroom.',
            'conversational' => 'Speak naturally and spontaneously, like chatting with a close friend over coffee. Relaxed, genuine, with natural laughter moments and real human warmth.',
            'inspirational'  => 'You are a FIERY motivational speaker at a packed stadium! Start intense and build to a SHOUTING crescendo. Voice cracking with raw passion. Commanding and relentless!',
            'cinematic'      => 'Deliver with sweeping cinematic grandeur and emotional depth. Speak with thunderous majesty, extremely slow and dramatic. Let every word resonate like a revelation.',
            'documentary'    => 'Narrate like an epic documentary with deep, authoritative gravitas and sweeping grandeur. Measured pace with dramatic weight behind every phrase. Paint the scene with your voice.',
            'sports_commentator' => 'You are an ELECTRIC sports commentator during the most intense football match! EXPLODE with energy on every play! Build tension, SCREAM the goals with raw passion! Let your voice crack with excitement! GOOOOOAL!',
            'meditation'     => 'Speak with profound serenity and inner peace, like a spiritual guide leading a meditation. Ultra-slow, gentle, hypnotic rhythm. Let each word float softly. Create deep tranquility and mindfulness.',
            'audiobook'      => 'Narrate as a professional audiobook narrator. Clear, engaging, with subtle emotional coloring. Vary pace for drama, maintain clarity, and bring characters to life with distinct vocal textures.',
            'children_story' => 'Read like a beloved storyteller enchanting young children. Warm, playful, with wonder in your voice. Use animated expressions, fun sound effects hints, and make every moment magical and exciting!',
            'radio_host'     => 'You are a charismatic radio host between songs. Smooth, warm, effortlessly cool delivery. Quick wit, playful energy, and that signature radio sparkle that keeps listeners tuned in.',
            'hopeful'        => 'Speak with genuine hope and optimism shining through. Let your voice lift with possibility and promise. Bright, uplifting tones that inspire belief in better days ahead.',
            'serious'        => 'Deliver with grave seriousness and weight. Deep, measured tones that convey the importance of every word. No lightness — pure gravity and significance.',
            'playful'        => 'Read with infectious playfulness and lighthearted joy. Bouncy, fun delivery with a mischievous twinkle. Make listeners smile with your delightful, carefree energy.',
            'mysterious'     => 'Speak with an air of mystery and intrigue. Low, hushed tones that hint at secrets. Slow, deliberate pacing that builds suspense and keeps listeners on edge.',
            'confident'      => 'Deliver with unwavering confidence and authority. Strong, assured tones that command attention. Speak like someone who knows exactly what they are talking about.',
            'movie_trailer' => 'Deliver like an epic movie-trailer voiceover: deep, resonant, with dramatic pauses between lines and every phrase landing with weight.',
            'poetry' => 'Recite like a poet performing on stage: musical rhythm, deliberate pauses at line breaks, and rich emotional emphasis on key images.',
            'bedtime_story' => 'Read like a parent telling a bedtime story: soft, slow, soothing, and warm, growing gentler toward the end.',
            'customer_service' => 'Speak like a friendly, professional customer-service agent: clear, patient, polite, and reassuring, with a smile in the voice.',
            'phone_system' => 'Speak like a phone-system announcement: clear, evenly paced, neutral, and polite, with every number and option easy to follow.',
            'game_narrator' => 'Narrate like a video-game narrator: epic, immersive, and energetic, building excitement for what comes next.',
            'asmr' => 'Speak in a very soft, close, intimate whisper, slow and gentle, as in an ASMR recording.',
            'event_host' => 'Host like a charismatic MC at a live event: loud, warm, and energetic, welcoming the crowd and building moments for applause.',
            'tour_guide' => 'Speak like an enthusiastic tour guide: friendly, informative, and vivid, pointing out details with curiosity.',
            'true_crime' => 'Narrate like a true-crime podcast host: low, measured, and suspenseful, revealing each detail carefully.',
            'luxury_brand' => 'Speak like a luxury-brand advertisement: smooth, slow, elegant, and refined, with understated confidence.',
            default          => 'Performance style: '.str_replace(['_', '-'], ' ', $style).'. Keep this style clear and consistent throughout.',
        };
    }

    /**
     * Resolve a voice name to a valid Gemini voice.
     *
     * Accepts:
     *  - Raw Gemini names (Kore, Puck, Zephyr, ...)
     *  - Branded internal_voice_id values (gemini-ar-rawaa, gemini-en-serene, ...)
     *  - Display names
     *
     * When a branded ID is provided, the actual provider_voice_id is
     * looked up from the voices table so the raw Gemini name is never
     * exposed to end-users.
     */
    private function resolveGeminiVoice(string $voiceName, mixed $expectedGender = null): string
    {
        $voiceName = trim($voiceName);
        $normalizedExpectedGender = $this->normalizeGender($expectedGender);
        $resolved = '';
        $detectedGender = null;

        // 1. Already a known raw Gemini voice name -> use directly.
        if ($voiceName !== '' && in_array($voiceName, self::VOICES, true)) {
            $resolved = $voiceName;
            $detectedGender = self::VOICE_GENDER_MAP[$resolved] ?? null;
        }

        // 2. Case-insensitive match against known voices.
        if ($resolved === '') {
            foreach (self::VOICES as $voice) {
                if (strcasecmp($voice, $voiceName) === 0) {
                    $resolved = $voice;
                    $detectedGender = self::VOICE_GENDER_MAP[$resolved] ?? null;
                    break;
                }
            }
        }

        // 3. Resolve from aliases and slug tokens (e.g. gemini-ar-walid, ar-Gemini-HD-Fenrir).
        if ($resolved === '' && $voiceName !== '') {
            $fromAlias = $this->voiceAliasToGeminiVoice($voiceName);
            if ($fromAlias !== null) {
                $resolved = $fromAlias;
                $detectedGender = self::VOICE_GENDER_MAP[$resolved] ?? null;
            }
        }

        // 4. Look up internal_voice_id/display_name from DB.
        if ($resolved === '' && $voiceName !== '') {
            $lowerVoiceName = strtolower($voiceName);
            $voiceRow = \App\Models\Voice::query()
                ->where('provider', 'gemini')
                ->where('is_active', true)
                ->where(function ($q) use ($voiceName, $lowerVoiceName) {
                    $q->where('internal_voice_id', $voiceName)
                        ->orWhere('display_name', $voiceName)
                        ->orWhereRaw('LOWER(internal_voice_id) = ?', [$lowerVoiceName])
                        ->orWhereRaw('LOWER(display_name) = ?', [$lowerVoiceName]);
                })
                ->first(['provider_voice_id', 'gender']);

            if ($voiceRow) {
                $providerVoiceId = trim((string) ($voiceRow->provider_voice_id ?? ''));
                if ($providerVoiceId !== '' && in_array($providerVoiceId, self::VOICES, true)) {
                    $resolved = $providerVoiceId;
                } else {
                    $providerVoiceToken = $this->extractGeminiVoiceToken($providerVoiceId);
                    if ($providerVoiceToken !== null) {
                        $resolved = $providerVoiceToken;
                    }
                }
                $detectedGender = $this->normalizeGender($voiceRow->gender ?? null);
            }
        }

        // 5. Default voice.
        if ($resolved === '') {
            $resolved = $this->defaultVoiceForGender($normalizedExpectedGender ?: $detectedGender);
        }

        // 6. Enforce requested gender when available.
        if ($normalizedExpectedGender !== '' && ! $this->voiceMatchesGender($resolved, $normalizedExpectedGender)) {
            $resolved = $this->defaultVoiceForGender($normalizedExpectedGender);
        }

        return $resolved;
    }

    private function resolveLanguageCode(string $targetLanguage, ?string $dialect): string
    {
        $candidate = trim((string) $dialect);
        if ($candidate !== '' && in_array($candidate, self::SUPPORTED_LANGUAGE_CODES, true)) {
            return $candidate;
        }
        // A regional accent (en-GB-SC) falls back to its country's code (en-GB).
        $base = implode('-', array_slice(explode('-', $candidate), 0, 2));
        if ($base !== $candidate && in_array($base, self::SUPPORTED_LANGUAGE_CODES, true)) {
            return $base;
        }

        $normalized = strtolower(trim($targetLanguage));
        if (str_starts_with($normalized, 'ar')) {
            return 'ar-XA';
        }

        return 'en-US';
    }

    private function normalizeGender(mixed $gender): string
    {
        $normalized = strtolower(trim((string) $gender));
        return in_array($normalized, ['male', 'female'], true) ? $normalized : '';
    }

    private function voiceMatchesGender(string $voiceName, string $gender): bool
    {
        $voiceGender = self::VOICE_GENDER_MAP[$voiceName] ?? null;
        return $voiceGender === null || $voiceGender === $gender;
    }

    private function defaultVoiceForGender(?string $gender): string
    {
        $normalized = $this->normalizeGender($gender);
        if ($normalized === 'male') {
            return 'Puck';
        }

        if ($normalized === 'female') {
            return 'Kore';
        }

        $configuredDefault = trim((string) config('services.gemini_tts.default_voice', 'Kore'));
        if ($configuredDefault !== '' && in_array($configuredDefault, self::VOICES, true)) {
            return $configuredDefault;
        }

        return 'Kore';
    }

    private function voiceAliasToGeminiVoice(string $voiceName): ?string
    {
        $normalized = strtolower(trim($voiceName));
        if ($normalized === '') {
            return null;
        }

        if (isset(self::VOICE_ALIAS_MAP[$normalized])) {
            return self::VOICE_ALIAS_MAP[$normalized];
        }

        return $this->extractGeminiVoiceToken($normalized);
    }

    private function extractGeminiVoiceToken(string $candidate): ?string
    {
        $normalized = strtolower(trim($candidate));
        if ($normalized === '') {
            return null;
        }

        foreach (self::VOICES as $voice) {
            $voiceLower = strtolower($voice);
            if ($normalized === $voiceLower || str_ends_with($normalized, '-'.$voiceLower)) {
                return $voice;
            }
        }

        return null;
    }

    private function estimateDurationSeconds(string $text): int
    {
        $wordEstimate = max(
            str_word_count(strip_tags($text)),
            (int) ceil(mb_strlen($text) / 5)
        );

        return max(3, (int) ceil($wordEstimate / 2.6));
    }

    /**
     * Estimate USD cost for Gemini TTS.
     * Gemini 2.5 Flash Preview TTS pricing (as of 2025): input text charged per character.
     */
    private function estimateCost(int $charCount): float
    {
        $ratePerMillion = (float) config('services.gemini_tts.cost_per_million_chars', 0.0);
        return round(($charCount / 1_000_000) * $ratePerMillion, 6);
    }

    private function sendWithSslFallback(callable $sender, bool $verifySsl, string $context)
    {
        try {
            return $sender($verifySsl);
        } catch (\Throwable $e) {
            if (!$this->shouldRetryWithoutSsl($e, $verifySsl)) {
                throw $e;
            }

            Log::warning($context . ' failed SSL verification on local environment; retrying once without verification.');

            return $sender(false);
        }
    }

    private function shouldRetryWithoutSsl(\Throwable $e, bool $verifySsl): bool
    {
        if (!$verifySsl || !app()->environment('local')) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 60')
            || str_contains($message, 'ssl certificate problem')
            || str_contains($message, 'unable to get local issuer certificate');
    }
}
