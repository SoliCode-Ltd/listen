<?php

namespace App\Services\Tts;

use App\Contracts\TtsProviderInterface;
use App\Services\VoiceCloningService;
use App\Support\ArabicDialects;
use App\Support\SpeechLanguages;
use App\Support\EnglishAccents;
use App\Support\GeminiTtsModel;
use App\Support\SpeakerVoices;
use App\Support\GeminiVocalTags;
use App\Support\JoinPauses;
use App\Support\PaceAndPitch;
use App\Support\PieceCheck;
use App\Support\ShareableAudio;
use App\Support\SpeechJoin;
use App\Support\TextChunker;
use App\Support\TtsStudioConfig;
use App\Support\UsageEstimator;
use App\Support\VoiceTier;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gemini TTS Provider.
 *
 * Uses Gemini's latest speech model (GeminiTtsModel). It speaks the
 * transcript verbatim and takes delivery instructions in speech metadata.
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
        // Old names and spellings of speakers (a speaker's own name finds them in SpeakerVoices).
        'walid' => 'Charon',
        'hazem' => 'Schedar',
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
        'ar-XA-NW' => 'Modern Standard Arabic in the style of a TV news bulletin',
        'ar-XA-CL' => 'Classical Arabic (heritage literary Fusha with full case endings)',
        'ar-XA-WH' => 'Educated pan-Arab spoken Arabic ("White Arabic"), between Fusha and colloquial',
        'ar-SA-TH' => 'Tihami Saudi Arabic (Red Sea coast, Al-Qunfudhah and Jazan lowlands)',
        'ar-AE-NO' => 'Northern Emirati Arabic (Ras Al Khaimah and Fujairah)',
        'ar-AE-BD' => 'Emirati Bedouin Arabic (Al Ain and the desert tribes)',
        'ar-KW-BD' => 'Kuwaiti Bedouin Arabic (Najdi-influenced)',
        'ar-BH-BA' => 'Baharna Bahraini Arabic (the villages of Bahrain)',
        'ar-OM-IN' => 'Interior Omani Arabic (Nizwa and Al Batinah)',
        'ar-LB-SO' => 'Southern Lebanese Arabic (Tyre, Nabatieh and the South)',
        'ar-LB-BK' => 'Bekaa Lebanese Arabic (Zahle and Baalbek)',
        'ar-LB-MT' => 'Mount Lebanon Arabic (Chouf and Metn, with a full ق)',
        'ar-SY-HM' => 'Homsi and Hamwi Syrian Arabic (Homs and Hama)',
        'ar-SY-CO' => 'Coastal Syrian Arabic (Latakia and Tartus, with a full ق)',
        'ar-SY-SW' => 'Suwayda Syrian Arabic (Jabal al-Arab, with a full ق)',
        'ar-JO-SO' => 'Southern Jordanian Arabic (Karak, Tafilah and Ma\'an)',
        'ar-PS-FL' => 'Rural Palestinian Arabic (Fallahi, the villages)',
        'ar-PS-NB' => 'Nablusi Palestinian Arabic (Nablus)',
        'ar-EG-SH' => 'Modern Cairo street Arabic (youth slang, sha\'bi neighbourhoods)',
        'ar-EG-NU' => 'Aswan Egyptian Arabic (Nubian-influenced, Upper Egypt)',
        'ar-SD-WE' => 'Western Sudanese Arabic (Darfur and Kordofan, Baggara)',
        'ar-SD-EA' => 'Eastern Sudanese Arabic (Kassala and Port Sudan)',
        'ar-IQ-NJ' => 'Najafi Iraqi Arabic (Najaf and Karbala, Middle Euphrates)',
        'ar-IQ-SO' => 'Southern rural Iraqi Arabic (Nasiriyah, Amarah and the marshes)',
        'ar-YE-TH' => 'Tihami Yemeni Arabic (Hodeidah and the Red Sea coast)',
        'ar-MA-FS' => 'Fassi Moroccan Arabic (Fes, old city speech)',
        'ar-MA-OR' => 'Eastern Moroccan Arabic (Oujda)',
        'ar-MA-SA' => 'Saharan Hassaniya Arabic (Laayoune and the Moroccan south)',
        'ar-DZ-AL' => 'Algiers Algerian Arabic (Dziri, the capital)',
        'ar-DZ-TL' => 'Tlemcen Algerian Arabic (soft urban Andalusian-rooted speech)',
        'ar-DZ-SA' => 'Saharan Algerian Arabic (Ouargla, Djelfa and the south)',
        'ar-TN-SA' => 'Sahel Tunisian Arabic (Sousse and Monastir)',
        'ar-TN-SO' => 'Southern Tunisian Arabic (Gabes and Medenine, Bedouin-influenced)',
        'ar-LY-FZ' => 'Fezzani Libyan Arabic (Sabha and the south)',
        'ar-XA-KH' => 'Modern Standard Arabic in a rousing oratory style (speeches and sermons)',
        'ar-XA-ED' => 'Simple, slow and very clear Modern Standard Arabic for learners and children',
        'ar-XA-DR' => 'Theatrical Modern Standard Arabic of historical drama and dubbing',
        'ar-SA-FF' => 'Faifi Saudi Arabic (Jabal Faifa, Jazan mountains)',
        'ar-QA-NO' => 'Northern Qatari Arabic (Al Khor and Al Shamal)',
        'ar-QA-WH' => 'Contemporary Qatari Arabic (the polished Doha speech of media and offices)',
        'ar-QA-OL' => 'Old urban Qatari Arabic (the old quarters of Doha and Al Wakrah)',
        'ar-OM-BD' => 'Omani Bedouin Arabic (the desert tribes of Oman)',
        'ar-LB-ZG' => 'Zgharta Lebanese Arabic (Zgharta and Ehden, North Lebanon)',
        'ar-LB-AM' => 'Lebanese Arabic with an Armenian-Lebanese accent (Bourj Hammoud, Beirut)',
        'ar-SY-SH' => 'Shawi Bedouin Arabic (Raqqa and the Euphrates steppe)',
        'ar-SY-JZ' => 'Jazrawi Syrian Arabic (Hasakah and Qamishli, Mesopotamian)',
        'ar-PS-NG' => 'Negev Bedouin Arabic (the Bedouin of the Naqab)',
        'ar-EG-AR' => 'Upper-class Cairene Arabic (Zamalek and Garden City, refined and soft)',
        'ar-EG-SR' => 'Sharqawi Egyptian Arabic (Sharqia, deep rural Delta)',
        'ar-EG-WH' => 'Western Desert oases Arabic (Siwa, Farafra and Bahariya)',
        'ar-SD-NO' => 'Northern Sudanese Arabic (along the Nile, Nubian-influenced)',
        'ar-TD' => 'Chadian Shuwa Arabic (Chad and western Sudan)',
        'ar-SS' => 'Juba Arabic (the Arabic creole of South Sudan)',
        'ar-IQ-OB' => 'Old Baghdadi qeltu Arabic (the old communities of Baghdad)',
        'ar-IQ-FY' => 'Feyli Iraqi Arabic (Baghdad and Wasit, Kurdish-influenced)',
        'ar-IQ-KK' => 'Kirkuki Iraqi Arabic (Kirkuk, Turkmen and Kurdish influenced)',
        'ar-IR' => 'Ahwazi Arabic (Khuzestan, close to southern Iraqi)',
        'ar-TR-MR' => 'Mardin Anatolian Arabic (qeltu Mesopotamian Arabic in Turkey)',
        'ar-TR-UR' => 'Urfa Arabic (Bedouin-influenced Arabic of Şanlıurfa and Harran)',
        'ar-TR-HT' => 'Hatay Arabic (Antakya and Iskenderun, Levantine)',
        'ar-YE-SQ' => 'Socotra Arabic (Yemeni Arabic of the island of Socotra)',
        'ar-MA-SS' => 'Souss Moroccan Arabic (Agadir, Amazigh-influenced Darija)',
        'ar-DZ-JJ' => 'Jijel Algerian Arabic (ق said as k)',
        'ar-DZ-ST' => 'Sétif Algerian Arabic (the High Plateaus)',
        'ar-DZ-SF' => 'Souf Algerian Arabic (El Oued, close to southern Tunisian)',
        'ar-DZ-MO' => 'Mostaganem Algerian Arabic (western coast)',
        'ar-DZ-NL' => 'Naili Algerian Arabic (Djelfa, Bou Saâda and Laghouat, Bedouin)',
        'ar-DZ-YT' => 'Algerian youth street Arabic (fast, mixed with French)',
        'ar-TN-KR' => 'Kairouan Tunisian Arabic (the interior)',
        'ar-TN-BZ' => 'Bizerte Tunisian Arabic (northern coast)',
        'ar-TN-KF' => 'Le Kef Tunisian Arabic (north-west, Bedouin-influenced)',
        'ar-SA-NB' => 'Najdi Bedouin Saudi Arabic (the desert tribes of central Arabia)',
        'ar-SA-NJ' => 'Najrani Saudi Arabic (Najran, southern Saudi Arabia)',
        'ar-SA-QT' => 'Qatifi Saudi Arabic (Qatif, Eastern Province)',
        'ar-OM-MS' => 'Shihhi Arabic of the Musandam peninsula (northern Oman)',
        'ar-OM-SR' => 'Coastal Omani Arabic of Sur and the Sharqiyah coast',
        'ar-LB-AK' => 'Akkari Lebanese Arabic (Akkar, northern Lebanon)',
        'ar-SY-DR' => 'Horani Syrian Arabic (Daraa and the Hauran plain)',
        'ar-SY-ID' => 'Idlibi Syrian Arabic (Idlib, north-west Syria)',
        'ar-SY-QL' => 'Qalamouni Syrian Arabic (the Qalamoun mountains north of Damascus)',
        'ar-JO-SL' => 'Salti Jordanian Arabic (As-Salt, central Jordan)',
        'ar-PS-GL' => 'Galilean Palestinian Arabic (Nazareth and the Galilee)',
        'ar-EG-ME' => 'Middle Egyptian Arabic (Minya and Beni Suef)',
        'ar-EG-MT' => 'Western Desert Bedouin Arabic of Marsa Matrouh (close to eastern Libyan)',
        'ar-EG-FY' => 'Fayoumi Egyptian Arabic (the Fayoum oasis)',
        'ar-NG' => 'Nigerian Shuwa Arabic (Borno, north-east Nigeria)',
        'ar-IQ-TK' => 'Tikriti Iraqi Arabic (Tikrit and Salah ad-Din)',
        'ar-YE-IB' => 'Ibbi Yemeni Arabic (Ibb, the green highlands)',
        'ar-YE-YF' => 'Yafi\'i Yemeni Arabic (Yafa, southern highlands)',
        'ar-YE-SD' => 'Northern Yemeni Arabic of Saada',
        'ar-YE-MR' => 'Bedouin Yemeni Arabic of Ma\'rib and the eastern desert',
        'ar-MA-AR' => 'Rural Aroubi Moroccan Arabic (the Atlantic plains, Doukkala and Chaouia)',
        'ar-MA-DR' => 'Drawi Moroccan Arabic (the Draa valley, Zagora)',
        'ar-DZ-AN' => 'Annabi Algerian Arabic (Annaba, north-east coast)',
        'ar-TN-DJ' => 'Djerbi Tunisian Arabic (the island of Djerba)',
        'ar-LY-MS' => 'Misrati Libyan Arabic (Misrata)',
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
        'social_media' => 'Speak this Arabic text like a popular social-media creator talking to camera: fast, punchy, upbeat and personal, hooking the listener in the first seconds.',
        'tutorial' => 'Speak this Arabic text like a friendly tutorial presenter: clear, step by step and patient, emphasising each action and number so it is easy to follow.',
        'stand_up' => 'Perform this Arabic text like a stand-up comedian on stage: conversational and confident, with build-ups and punchlines landing after a short beat.',
        'horror' => 'Narrate this Arabic text like a horror storyteller: low, slow and chilling, with long tense pauses and a hushed voice before each scare.',
        'dubbing' => 'Perform this Arabic text like a drama dubbing artist: expressive and theatrical, with rich articulation and every line acted with clear emotion.',
        'oratory' => 'Deliver this Arabic text like a powerful orator giving a speech: resonant, rhythmic and rousing, with strong emphasis and deliberate pauses for effect.',
        'reverent' => 'Read this Arabic text in a calm, reverent and spiritual tone, with careful, precise articulation of every letter and gentle pauses, suited to supplications and devotional texts.',
        'cartoon' => 'Perform this Arabic text like an animated cartoon character: bright, bouncy and exaggerated, with playful pitch jumps and lots of energy.',
        'announcer' => 'Speak this Arabic text like a public announcement at an airport or station: clear, calm, evenly paced and polite, with every name and number distinct.',
        'interview' => 'Speak this Arabic text like someone being interviewed: natural, thoughtful and articulate, answering with conversational pauses.',
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
     * @throws GeminiKeysOutException if no keys available
     */
    private function getAvailableKey(): array
    {
        $keyInfo = $this->getKeyManager()->getAvailableKey($this->currentModel)
            ?? ($this->getKeyManager()->keysInOrder($this->currentModel)[0] ?? null);
        if ($keyInfo === null) {
            throw new GeminiKeysOutException('All Gemini API keys exhausted. Please add more keys or wait for rate limits to reset.');
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

        // Over the daily ceiling this throws: the HD request then fails, and its credits are given back.
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

        // Sound tags are written <laugh>. Delivery tags ([excited]) split a single
        // voice's script into parts, each read with its own direction; in a
        // dialogue each directs its own turn.
        if ($isDialogue) {
            // Each line is rewritten into its speaker's dialect, as a single voice's script is.
            if ($this->isArabicLanguage($targetLanguage) && config('services.gemini_tts.dialect_rewrite', true)) {
                $text = $this->dialogueInDialects($text, $speakers, trim((string) ($voiceConfig['dialect'] ?? '')));
            }
            // Moods stay where they are written: structuredDialogueParts() gives each to its turn.
            $text = GeminiVocalTags::tagSounds($text);
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

        // The member's own style_prompt (then the dialect and speed they picked), otherwise
        // a direction built from dialect, emotion, style, speed, and pitch.
        $customPrompt = trim((string) ($voiceConfig['style_prompt'] ?? ''));
        $dialect = isset($voiceConfig['dialect']) && is_string($voiceConfig['dialect']) ? trim($voiceConfig['dialect']) : null;
        $pitch = (float) ($voiceConfig['pitch'] ?? 0.0);
        $stylePrompt = $customPrompt !== ''
            ? $this->ownDirection($customPrompt, $targetLanguage, $speed, $dialect)
            : $this->buildStylePrompt(
                $performanceStyle,
                $emotion,
                $targetLanguage,
                $speed,
                $dialect,
                $pitch,
            );
        $stylePrompt = self::asSpeaker((string) ($voiceConfig['voice_name'] ?? ''), $stylePrompt);
        $stylePrompt = GeminiVocalTags::withCues($stylePrompt, $voiceConfig['vocal_cues'] ?? []);
        $endpoint = $this->buildEndpoint($model);

        // Long outputs end with finishReason OTHER and no audio, so long
        // texts are read in pieces and the audio is joined. A child is read in
        // shorter pieces, each with the child's direction, so the voice never
        // has long enough to drift back to a grown-up's, and a piece pitched
        // lower than a child's voice is taken again (PieceCheck).
        $maxChars = max(200, (int) config('services.gemini_tts.max_chars_per_request', 900));
        $child = self::isChild((string) ($voiceConfig['voice_name'] ?? ''));
        if ($child) {
            $maxChars = min($maxChars, max(200, (int) config('services.gemini_tts.child_max_chars_per_request', 300)));
        }
        $floor = $child ? ['floor' => (float) config('services.gemini_tts.child_min_pitch', 220)] : [];
        $pieces = [];
        foreach ($voiceConfig['vocal_parts'] ?? [['text' => $text, 'cues' => []]] as $partIndex => $part) {
            $partStyle = GeminiVocalTags::withCues($stylePrompt, $part['cues']);
            // Read a round at a time (piecesAtOnce()): earlier rounds stay full, the last one shares the rest.
            $chunks = mb_strlen($part['text']) > $maxChars
                ? TextChunker::pieces($part['text'], $maxChars, 'mb_strlen', $this->piecesAtOnce(count($this->keysForRound())))
                : [['text' => $part['text'], 'gap' => '']];
            foreach ($chunks as $chunk) {
                $pieces[] = [(int) $partIndex, $chunk, $partStyle];
            }
        }

        $payloads = [];
        $groups = [];
        $pauses = [];
        $texts = [];
        $inPart = array_count_values(array_column($pieces, 0));
        $seen = [];
        foreach ($pieces as [$partIndex, $chunk, $partStyle]) {
            // The pieces one stretch of the script (one direction) is cut into are told they
            // are one reading; a mood tag's own part changes the delivery on purpose.
            $n = $seen[$partIndex] = ($seen[$partIndex] ?? -1) + 1;
            $style = $inPart[$partIndex] > 1 ? self::continuing($partStyle, $n, $inPart[$partIndex]) : $partStyle;
            $performed = GeminiVocalTags::forPerformance($chunk['text'], $style);
            $payloads[] = $this->buildSingleSpeakerPayload($performed['text'], $performed['style'], $voiceName);
            // The part of the script it reads ([whispering] …): each part keeps its own level.
            $groups[] = $partIndex;
            // The pause after it, from where its text ends: a sentence, a comma, a line, or mid-sentence.
            $pauses[] = JoinPauses::after($chunk['text'], $chunk['gap']);
            // What its take is checked against (PieceCheck): the seconds its text needs, and its part.
            $texts[] = self::pieceText($chunk['text'], $speed, $voiceConfig) + ['part' => $partIndex] + $floor;
        }

        $checked = null;
        if (count($payloads) === 1) {
            $audio = $this->requestAudio($endpoint, $payloads[0]);
            $filename = $this->saveAudioFile($audio['bytes'], $audio['mime_type']);
        } else {
            $checked = PieceCheck::emptyReport();
            $texts = config('services.gemini_tts.check_pieces', true) ? $texts : [];
            $filename = $this->saveJoinedAudio($this->joinAudioParts($this->requestAudioParts($endpoint, $payloads, $texts, $checked), $groups, $pauses));
            if ($checked['retaken'] > 0) {
                Log::info('Gemini TTS: pieces of a long voice were taken again.', $checked);
            }
            // Kept with the voice (Server deploy speed-check): how many pieces went out
            // at once, and the most memory the process took, against live's fixed limit.
            $checked['pieces_at_once'] = $this->piecesAtOnce(count($this->keysForRound()));
            $checked['peak_memory_mb'] = (int) round(memory_get_peak_usage(true) / 1048576);
        }

        return [
            'audio_path' => $filename,
            'duration_seconds' => $this->estimateDurationSeconds($text),
        ] + ($checked !== null ? ['pieces_check' => $checked] : []);
    }

    /**
     * Piece $n of the $count a long stretch of text (one direction) is cut
     * into is told it is one part of a single reading by one speaker: the
     * same voice, accent, pace, energy and tone as the rest, carrying on from
     * the part before and into the next. It is part of the direction, which
     * the voice never reads aloud.
     */
    public static function continuing(string $style, int $n, int $count): string
    {
        $line = 'This is part '.($n + 1).' of '.$count.' of one continuous recording, read in one sitting by the same speaker: '
            .'keep exactly the same voice, accent, pace, energy and tone as in the rest of it.';
        if ($n > 0) {
            $line .= ' It carries straight on from the part before it, so do not start it as a new opening.';
        }
        if ($n < $count - 1) {
            $line .= ' The recording goes on after it, so do not close it as an ending.';
        }

        return trim($style) === '' ? $line : trim($style)."\n".$line;
    }

    /**
     * What a piece's take is checked against (PieceCheck): the seconds its
     * text needs on a premium voice at this pace and with these settings, and
     * whether it asks for pauses.
     *
     * @param  array<string, mixed>  $voiceConfig
     * @return array{expected: float, pauses: bool}
     */
    public static function pieceText(string $text, float $speed, array $voiceConfig): array
    {
        return [
            'expected' => UsageEstimator::rawSeconds(UsageEstimator::features($text), VoiceTier::HD, $speed > 0 ? $speed : 1.0, $voiceConfig),
            'pauses' => preg_match('/<(?:short|long) pause>|\[(?:short|long) pause\]/u', $text) === 1,
        ];
    }

    /**
     * Send the pieces in parallel rounds, and hand each one over, in order,
     * as soon as its round is in: the join writes it to its file and lets it
     * go, so a long text is never held whole. A round is spread over the keys
     * with room (piecesAtOnce()), so the longest studio text goes out at once
     * instead of four pieces at a time on one key. A piece that fails is sent
     * again on its own, with the usual retries; one that hit its key's limit
     * (or a key Google refused) is sent on another key, not the same one
     * again. The retry budget runs from the start of each round, so a long
     * text's earlier rounds never use it up for a later piece.
     *
     * Each round's pieces are checked before they are handed over
     * (PieceCheck, when $texts says what each one's text needs): one that
     * stands out is taken once more and the better take is kept; $report
     * counts them.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @param  array<int, array{expected: float, pauses: bool, part: int, floor?: float}>  $texts
     * @param  array{pieces: int, retaken: int, kept_retake: int, reasons: array<string, int>}|null  $report
     * @return \Generator<int, array{bytes:string,mime_type:?string}>
     */
    private function requestAudioParts(string $endpoint, array $payloads, array $texts = [], ?array &$report = null): \Generator
    {
        $timeout = (int) config('services.gemini_tts.timeout_seconds', 60);
        $verifySsl = (bool) config('services.gemini_tts.verify_ssl', true);
        $retryBudgetSeconds = max(0, (int) config('services.gemini_tts.retry_budget_seconds', 20));
        $answeredBy = [];
        $history = ['paces' => [], 'pitches' => []];
        $report ??= PieceCheck::emptyReport();

        for ($done = 0, $total = count($payloads); $done < $total; $done += count($batch)) {
            // A round goes out over every key with room, the least used first, up to
            // max_parallel_requests pieces on each: each key has its own limits.
            $keys = $this->keysForRound();
            $batch = array_slice($payloads, $done, $this->piecesAtOnce(count($keys)), true);
            $keyOf = [];
            foreach (array_keys($batch) as $n => $index) {
                $keyOf[$index] = $keys[$n % count($keys)];
            }
            $ready = [];
            // Each reply (3-4 MB of base64 a minute of audio) is written to its own file as it
            // comes and read back one at a time, so a round of twelve is not held whole.
            $sinks = [];
            foreach (array_keys($batch) as $index) {
                $sinks[$index] = tempnam(sys_get_temp_dir(), 'listen-piece-') ?: null;
            }
            $startedAt = microtime(true);
            $responses = Http::pool(function (Pool $pool) use ($batch, $endpoint, $timeout, $verifySsl, $keyOf, $sinks) {
                foreach ($batch as $index => $payload) {
                    $pool->as('part' . $index)
                        ->timeout($timeout)
                        ->withOptions(['verify' => $verifySsl] + ($sinks[$index] !== null ? ['sink' => $sinks[$index]] : []))
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'x-goog-api-key' => $keyOf[$index]['key'],
                        ])
                        ->post($endpoint, $payload);
                }
            });

            try {
                foreach ($batch as $index => $payload) {
                    $keyInfo = $keyOf[$index];
                    // Each reply is let go once its audio is out.
                    $response = $responses['part' . $index] ?? null;
                    unset($responses['part' . $index]);
                    $error = $response instanceof \Throwable ? $response->getMessage() : null;
                    $part = null;

                    if ($response instanceof Response && $response->ok() && is_array($response->json())) {
                        try {
                            $part = $this->extractAudioFromResponse($response->json());
                            $answeredBy[$index] = $keyInfo['name'];
                        } catch (TransientGeminiException $e) {
                            $error = $e->getMessage();
                        }
                        if ($part !== null) {
                            $response = null;
                            $ready[$index] = $part;
                            $part = null;

                            continue;
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
                    // Only the key was at fault: the piece is sent on another key, whatever the time.
                    $keyAtFault = $response instanceof Response && $this->setKeyAside($response, $keyInfo, $endpoint);
                    if (! $keyAtFault && $elapsed >= $retryBudgetSeconds) {
                        throw new TransientGeminiException('Gemini TTS piece ' . ($index + 1) . ' of ' . count($payloads) . ' failed: ' . mb_substr((string) $error, 0, 200));
                    }

                    $response = null;
                    $ready[$index] = $this->requestAudio($endpoint, $payload);
                    $answeredBy[$index] = ($this->currentKeyInfo ?? $keyInfo)['name'];
                }
            } finally {
                unset($responses, $response);
                foreach (array_filter($sinks) as $sink) {
                    @unlink($sink);
                }
            }

            // The round's takes, checked against their texts and the voice so far:
            // one that stands out is taken once more, and the better one is kept.
            if ($texts !== []) {
                $ready = PieceCheck::review($ready, array_intersect_key($texts, $ready), function (int $index) use ($endpoint, $payloads, &$answeredBy): array {
                    $again = $this->requestAudio($endpoint, $payloads[$index]);
                    $answeredBy['retake-'.$index] = ($this->currentKeyInfo ?? $this->getAvailableKey())['name'];

                    return $again;
                }, $history, $report);
            }
            foreach ($ready as $index => $part) {
                unset($ready[$index]);
                yield $index => $part;
            }
            $part = null;
            // The client's pooled requests hold themselves in reference cycles: without
            // this, every batch's replies (3-4 MB of base64 each) stay in memory until
            // the whole text is read, and an hour of them is too much for live.
            gc_collect_cycles();
        }

        // One request for each piece, counted on the key that answered it; synthesize()
        // counts one request of the key in use at the end, with the characters.
        $counted = array_search(($this->currentKeyInfo ?? [])['name'] ?? null, $answeredBy, true);
        if ($counted !== false) {
            unset($answeredBy[$counted]);
        }
        foreach ($answeredBy as $keyName) {
            $this->getKeyManager()->recordUsage($keyName, 0);
        }
    }

    /**
     * A piece came back with its key's limit (429) or with the key refused: the
     * key is set aside (for the minute, for the day after a daily quota or a
     * refusal, or for an hour after its spending cap) and the next request goes
     * to another key, when there is one. False for any other error.
     */
    private function setKeyAside(Response $response, array $keyInfo, string $endpoint): bool
    {
        $status = $response->status();
        $body = (string) $response->body();
        $badKey = GeminiKeyManager::isKeyProblem($status, $body);
        $spendingCap = GeminiKeyManager::isSpendingCapError($status, $body);
        if ($status !== 429 && ! $badKey && ! $spendingCap) {
            return false;
        }

        if ($badKey || GeminiKeyManager::isDailyQuotaError($status, $body)) {
            $this->getKeyManager()->markOutForToday(
                $keyInfo['name'],
                GeminiKeyManager::modelFromPath($endpoint, (string) ($this->currentModel ?? GeminiTtsModel::resolve())),
            );
        }
        if ($spendingCap) {
            $this->getKeyManager()->markOutForSpendingCap($keyInfo['name']);
        }

        // The key in use moves on from it; a round's other key at its minute's
        // limit is set aside for the minute, so the next round skips it.
        if (($this->currentKeyInfo['name'] ?? null) === $keyInfo['name']) {
            if (count($this->getKeyManager()->getAllKeys()) > 1) {
                $this->rotateToNextKey();
            }
        } elseif ($status === 429 && ! $spendingCap && ! GeminiKeyManager::isDailyQuotaError($status, $body)) {
            $this->getKeyManager()->markKeyExhausted($keyInfo['name']);
        }

        return true;
    }

    /**
     * The keys a round of pieces goes out on: every key with room this minute
     * and today, the least used first; at least the key in use.
     *
     * @return array<int, array{key: string, name: string, index: int}>
     */
    private function keysForRound(): array
    {
        $manager = $this->getKeyManager();
        $keys = count($manager->getAllKeys()) > 1 ? $manager->keysWithRoom($this->currentModel) : [];

        return $keys !== [] ? $keys : [$this->currentKeyInfo ?? $this->getAvailableKey()];
    }

    /**
     * How many pieces are read at the same time over $keys keys:
     * services.gemini_tts.max_parallel_requests on each key (each has its own
     * limits), at most services.gemini_tts.max_parallel_total. With 3 keys,
     * 12: the 8,000 characters Studio Max allows (about 10 pieces) are read in
     * one round of about half a minute, where 4 on one key took 3.
     */
    private function piecesAtOnce(int $keys): int
    {
        $perKey = max(1, (int) config('services.gemini_tts.max_parallel_requests', 4));

        return max(1, min(max(1, (int) config('services.gemini_tts.max_parallel_total', 12)), $perKey * max(1, $keys)));
    }

    /**
     * Join the pieces into one WAV file with a pause between them: the one
     * their text calls for (JoinPauses), each piece's own quiet cut at the
     * join so that pause is the whole pause; 0.3 s when none is given. Each
     * piece is written as it comes and let go (SpeechJoin), so a long text
     * (50,000 characters is close to an hour of audio) is never held whole
     * within the web server's memory limit.
     *
     * @param  iterable<int, array{bytes:string,mime_type:?string}>  $parts
     * @param  array<int, int>  $groups  the part of the script each piece reads
     * @param  array<int, float>  $pauses  seconds of pause after each piece
     * @return array{path:string,mime_type:string,pieces:array<int, array{0:int,1:int,2:int}>}
     */
    private function joinAudioParts(iterable $parts, array $groups = [], array $pauses = []): array
    {
        return SpeechJoin::toFile(is_array($parts) ? SpeechJoin::draining($parts) : $parts, $pauses, $groups, SpeechJoin::PAUSE, $pauses !== []);
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

        $customPrompt = trim((string) ($voiceConfig['style_prompt'] ?? ''));
        $stylePrompt = $customPrompt !== ''
            ? $this->ownDirection($customPrompt, $targetLanguage, $speed, $dialect)
            : $this->buildStylePrompt(
                $performanceStyle,
                $emotion,
                $targetLanguage,
                $speed,
                $dialect,
                $pitch,
            );
        // A speaker with their own dialect or accent gets their own direction, so two
        // people can talk in two dialects (a Saudi host and an Egyptian guest); a child
        // speaker always speaks as a child.
        $speakerStyles = [];
        foreach ($speakers as $speaker) {
            $name = trim((string) ($speaker['name'] ?? $speaker['id'] ?? ''));
            $own = trim((string) ($speaker['dialect'] ?? ''));
            $voice = (string) ($speaker['voice_name'] ?? $speaker['voice'] ?? '');
            if ($name === '' || ($own === '' && self::asSpeaker($voice, '') === '')) {
                continue;
            }
            $speakerStyle = $own === '' ? $stylePrompt : ($customPrompt !== ''
                ? $this->ownDirection($customPrompt, $targetLanguage, $speed, $own)
                : $this->buildStylePrompt($performanceStyle, $emotion, $targetLanguage, $speed, $own, $pitch));
            $speakerStyles[$name] = GeminiVocalTags::forPerformance($text, self::asSpeaker($voice, $speakerStyle))['style'];
        }

        // Moods and delivery tags direct their own turn only (structuredDialogueParts()).
        $performed = GeminiVocalTags::forPerformance($text, $stylePrompt);
        [$text, $stylePrompt] = [$performed['text'], $performed['style']];
        // A long dialogue is made in pieces split at speaker turns, each with the same
        // speakers and voices (dialoguePieces()), and joined in order as a single voice's
        // pieces are: written to a file as they come, with the pause a reader takes
        // after each piece's last line.
        $payloads = [];
        $pauses = [];
        foreach ($this->dialoguePieces($text, $speakers) as $piece) {
            $cast = array_map(fn (array $speaker): string => trim((string) ($speaker['name'] ?? $speaker['id'] ?? '')), $piece['speakers']);
            $payloads[] = $this->buildDialoguePayload($piece['text'], $stylePrompt, $piece['speakers'], array_intersect_key($speakerStyles, array_flip($cast)));
            $pauses[] = JoinPauses::after($piece['text']);
        }

        if (count($payloads) === 1) {
            $audio = $this->requestAudio($endpoint, $payloads[0]);
            $filename = $this->saveAudioFile($audio['bytes'], $audio['mime_type']);
        } else {
            $filename = $this->saveJoinedAudio($this->joinAudioParts($this->requestAudioParts($endpoint, $payloads), [], $pauses));
        }

        return [
            'audio_path' => $filename,
            'duration_seconds' => $this->estimateDurationSeconds($text),
        ];
    }

    /**
     * A dialogue is made in one request, and very long ones come back without
     * audio. A dialogue longer than services.gemini_tts.max_dialogue_chars_per_request
     * (1,500 characters: about 1.5 to 2 minutes of conversation, well under the
     * 2,400 one dialogue request used to be allowed; shorter dialogues stay one
     * request, as before) is split at speaker turns into pieces of about even length.
     * A single turn longer than that is split at sentence ends, every part
     * starting with its speaker's name.
     *
     * Every piece is made with the same speakers and voices, even a piece where only
     * one of them talks. The engine takes at most two voices a request, so a
     * dialogue with three or more speakers is also split where a third speaker
     * would come in: each piece has two of them, each always with their own voice.
     *
     * @param  array<int, array<string, mixed>>  $speakers
     * @return array<int, array{text: string, speakers: array<int, array<string, mixed>>}>
     */
    private function dialoguePieces(string $text, array $speakers): array
    {
        $byName = [];
        foreach ($speakers as $speaker) {
            $name = trim((string) ($speaker['name'] ?? $speaker['id'] ?? ''));
            if ($name !== '' && ! isset($byName[$name])) {
                $byName[$name] = $speaker;
            }
        }
        $names = array_keys($byName);
        $pairs = count($names) > 2;
        $max = max(300, (int) config('services.gemini_tts.max_dialogue_chars_per_request', 1500));
        // A dialogue with a child in it is made in shorter pieces, so the child stays a child.
        foreach ($byName as $speaker) {
            if (self::isChild((string) ($speaker['voice_name'] ?? $speaker['voice'] ?? ''))) {
                $max = min($max, max(300, (int) config('services.gemini_tts.child_max_dialogue_chars_per_request', 600)));
                break;
            }
        }
        if (! $pairs && mb_strlen($text) <= $max) {
            return [['text' => $text, 'speakers' => $speakers]];
        }

        // The turns: a "Name:" line starts one, a line without a name goes on with it.
        $turns = [];
        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $speaker = null;
            foreach ($names as $name) {
                if (str_starts_with($line, $name.':')) {
                    $speaker = $name;
                    break;
                }
            }
            if ($speaker !== null || $turns === []) {
                $turns[] = ['speaker' => $speaker ?? ($names[0] ?? 'Speaker'), 'named' => $speaker !== null, 'lines' => []];
            }
            $turns[array_key_last($turns)]['lines'][] = $line;
        }
        foreach ($turns as $i => $turn) {
            $spoken = implode("\n", $turn['lines']);
            $turns[$i]['words'] = GeminiVocalTags::hasWords($turn['named'] ? mb_substr($spoken, mb_strlen($turn['speaker']) + 1) : $spoken);
        }

        // The fewest pieces the size allows, then the smallest size that gives no more
        // of them: pieces of about even length, made at the same time.
        $pieces = $this->packDialogueTurns($turns, $max, $pairs);
        if (count($pieces) > 1) {
            $total = array_sum(array_map(fn (array $turn): int => mb_strlen(implode("\n", $turn['lines'])) + 1, $turns));
            [$low, $high] = [min($max, max(200, intdiv($total, count($pieces)))), $max];
            while ($low < $high) {
                $size = intdiv($low + $high, 2);
                if (count($this->packDialogueTurns($turns, $size, $pairs)) <= count($pieces)) {
                    $high = $size;
                } else {
                    $low = $size + 1;
                }
            }
            $pieces = $this->packDialogueTurns($turns, $low, $pairs);
        }

        return array_map(function (array $units) use ($speakers, $byName, $names, $pairs): array {
            $cast = $speakers;
            if ($pairs) {
                // Its two speakers, in the dialogue's order; one who talks alone gets the
                // next speaker as the second voice, so the request stays a dialogue.
                $voices = array_values(array_intersect($names, array_column($units, 'speaker')));
                if (count($voices) < 2) {
                    $voices = array_values(array_intersect($names, [$voices[0], current(array_diff($names, $voices))]));
                }
                $cast = array_map(fn (string $name): array => $byName[$name], $voices);
            }

            return ['text' => implode("\n", array_merge(...array_column($units, 'lines'))), 'speakers' => $cast];
        }, $pieces);
    }

    /**
     * The dialogue's turns in order, in pieces of at most $limit characters (and of
     * two speakers when $pairs). A turn of tags alone ("Omar: [scared]") goes on with
     * the words after it, which it directs.
     *
     * @param  array<int, array{speaker: string, named: bool, lines: array<int, string>, words: bool}>  $turns
     * @return array<int, array<int, array{speaker: string, named: bool, lines: array<int, string>, words: bool}>>
     */
    private function packDialogueTurns(array $turns, int $limit, bool $pairs): array
    {
        $pieces = [];
        $current = [];
        $size = -1;
        foreach ($turns as $turn) {
            $units = [$turn];
            $text = implode("\n", $turn['lines']);
            if (mb_strlen($text) > $limit) {
                // One very long turn: split at sentence ends, each part with the speaker's name.
                $label = $turn['speaker'].': ';
                $spoken = $turn['named'] ? trim(mb_substr($text, mb_strlen($turn['speaker']) + 1)) : $text;
                $units = array_map(
                    fn (string $chunk): array => ['speaker' => $turn['speaker'], 'named' => true, 'lines' => [$label.$chunk], 'words' => true],
                    TextChunker::split($spoken, max(100, $limit - mb_strlen($label)), 'mb_strlen'),
                );
            }

            foreach ($units as $unit) {
                $unitSize = mb_strlen(implode("\n", $unit['lines']));
                $voices = array_unique(array_merge(array_column($current, 'speaker'), [$unit['speaker']]));
                if ($current !== [] && ($size + 1 + $unitSize > $limit || ($pairs && count($voices) > 2))) {
                    $carry = [];
                    while (count($current) > 1 && ! end($current)['words']) {
                        array_unshift($carry, array_pop($current));
                    }
                    if ($pairs && count(array_unique(array_merge(array_column($carry, 'speaker'), [$unit['speaker']]))) > 2) {
                        $current = array_merge($current, $carry);
                        $carry = [];
                    }
                    $pieces[] = $current;
                    $current = $carry;
                    $size = $carry === [] ? -1 : mb_strlen(implode("\n", array_merge(...array_column($carry, 'lines'))));
                }
                $current[] = $unit;
                $size += 1 + $unitSize;
            }
        }
        if ($current !== []) {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSingleSpeakerPayload(string $text, string $stylePrompt, string $voiceName): array
    {
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

    /**
     * @param  array<int, array<string, mixed>>  $speakers
     * @return array<string, mixed>
     */
    private function buildDialoguePayload(
        string $text,
        string $stylePrompt,
        array $speakers,
        array $speakerStyles = [],
    ): array {
        $speakerVoiceConfigs = [];
        foreach ($speakers as $speaker) {
            $speakerName = trim((string) ($speaker['name'] ?? $speaker['id'] ?? 'Speaker'));
            if ($speakerName === '') {
                $speakerName = 'Speaker';
            }

            $voice = $this->resolveGeminiVoice(
                (string) ($speaker['voice_name'] ?? $speaker['voice'] ?? ''),
                $speaker['gender'] ?? null,
            );
            $speakerVoiceConfigs[] = [
                'speaker' => $speakerName,
                'voiceConfig' => self::dialogueVoiceConfig($voice),
            ];
        }

        return [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $this->structuredDialogueParts($text, $speakers, $stylePrompt, $speakerStyles),
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'multiSpeakerVoiceConfig' => [
                        'speakerVoiceConfigs' => $speakerVoiceConfigs,
                    ],
                ],
            ],
        ];
    }

    /**
     * Split a script into verbatim turns so Gemini 3.8 does not read speaker labels aloud.
     * Every line is a part with its speaker and that speaker's direction. A mood or
     * delivery tag ([angry]) directs its own turn only, from where it is written to
     * the end of the turn (all of it when nothing follows the tag); a turn of tags
     * alone directs the next turn. It never colours the rest of the dialogue.
     *
     * @param  array<int, array<string, mixed>>  $speakers
     * @param  array<string, string>  $speakerStyles  direction per speaker name, for speakers with their own dialect
     * @return array<int, array<string, mixed>>
     */
    private function structuredDialogueParts(string $text, array $speakers, string $stylePrompt, array $speakerStyles = []): array
    {
        $names = [];
        foreach ($speakers as $speaker) {
            $name = trim((string) ($speaker['name'] ?? $speaker['id'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        // The turns: a "Name:" line starts one, a line without a name goes on with it.
        $currentSpeaker = $names[0] ?? 'Speaker';
        $turns = [];
        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $spoken = $line;
            $named = false;
            foreach ($names as $name) {
                $prefix = $name.':';
                if (str_starts_with($line, $prefix)) {
                    $currentSpeaker = $name;
                    $spoken = trim(substr($line, strlen($prefix)));
                    $named = true;
                    break;
                }
            }

            if ($named || $turns === []) {
                $turns[] = ['speaker' => $currentSpeaker, 'lines' => []];
            }
            if ($spoken !== '') {
                $turns[array_key_last($turns)]['lines'][] = $spoken;
            }
        }

        $parts = [];
        $carried = '';
        foreach ($turns as $turn) {
            $script = trim($carried.' '.implode("\n", $turn['lines']));
            $pieces = GeminiVocalTags::parts($script, true);
            $trailing = GeminiVocalTags::trailingDelivery($script);
            $carried = $pieces === [] ? $trailing : '';
            $turnCues = $pieces !== [] && $trailing !== '' ? GeminiVocalTags::prepare($trailing)['cues'] : [];
            foreach ($pieces as $piece) {
                $lineStyle = GeminiVocalTags::withCues(
                    $speakerStyles[$turn['speaker']] ?? $stylePrompt,
                    array_values(array_unique(array_merge($piece['cues'], $turnCues))),
                );
                // One part per line, as written.
                foreach (preg_split("/\r\n|\n|\r/", $piece['text']) ?: [] as $spoken) {
                    if (trim($spoken) === '') {
                        continue;
                    }
                    $metadata = ['speaker' => $turn['speaker']];
                    if (trim($lineStyle) !== '') {
                        $metadata['style'] = $lineStyle;
                    }
                    $parts[] = ['text' => trim($spoken), 'speech_metadata' => $metadata];
                }
            }
        }

        if ($parts !== []) {
            return $parts;
        }

        $prepared = GeminiVocalTags::prepare($text);
        $part = ['text' => $prepared['text'], 'speech_metadata' => ['speaker' => $currentSpeaker]];
        $stylePrompt = GeminiVocalTags::withCues($stylePrompt, $prepared['cues']);
        if (trim($stylePrompt) !== '') {
            $part['speech_metadata']['style'] = $stylePrompt;
        }

        return [$part];
    }

    /**
     * Rewrite a dialogue's lines into each speaker's dialect (their own, or the
     * script's). One request per dialect; the "Name:" labels never leave this code.
     * If a rewrite does not come back line for line, those lines stay as written.
     *
     * @param  array<int, array<string, mixed>>  $speakers
     */
    private function dialogueInDialects(string $text, array $speakers, string $dialect): string
    {
        $own = [];
        foreach ($speakers as $speaker) {
            $name = trim((string) ($speaker['name'] ?? $speaker['id'] ?? ''));
            if ($name !== '') {
                $own[$name] = trim((string) ($speaker['dialect'] ?? '')) ?: $dialect;
            }
        }

        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $current = array_key_first($own);
        $groups = [];
        foreach ($lines as $i => $line) {
            $spoken = trim($line);
            foreach (array_keys($own) as $name) {
                if (str_starts_with($spoken, $name.':')) {
                    $current = $name;
                    $spoken = trim(substr($spoken, strlen($name) + 1));
                    break;
                }
            }
            $lineDialect = $current !== null ? $own[$current] : $dialect;
            if ($spoken === '' || $lineDialect === '') {
                continue;
            }
            $groups[$lineDialect][$i] = $spoken;
        }

        $cloning = app(VoiceCloningService::class);
        $startedAt = microtime(true);
        foreach ($groups as $lineDialect => $spokenLines) {
            // A slow rewrite must not hold the generation: past 20 seconds the rest stay as written.
            if (microtime(true) - $startedAt > 20) {
                break;
            }
            $block = implode("\n", $spokenLines);
            $rewritten = $cloning->speakableText($block, $lineDialect);
            $newLines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $rewritten) ?: []), fn ($l) => $l !== ''));
            if ($rewritten === $block || count($newLines) !== count($spokenLines)) {
                continue;
            }
            foreach (array_keys($spokenLines) as $n => $i) {
                $label = '';
                foreach (array_keys($own) as $name) {
                    if (str_starts_with(trim($lines[$i]), $name.':')) {
                        $label = $name.': ';
                        break;
                    }
                }
                $lines[$i] = $label.$newLines[$n];
            }
        }

        return implode("\n", $lines);
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
        // server timeout if repeated: past the budget the request fails instead
        // (and the member's credits are given back).
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
        // Keys that answered with a limit that outlasts the minute: the day's
        // quota, the spending cap, or a refusal of the key itself.
        $keysOut = 0;

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
            $dailyQuota = GeminiKeyManager::isDailyQuotaError($response->status(), (string) $response->body());
            if ($dailyQuota) {
                $this->getKeyManager()->markOutForToday(
                    $keyInfo['name'],
                    GeminiKeyManager::modelFromPath($endpoint, (string) ($this->currentModel ?? GeminiTtsModel::resolve())),
                );
            }

            // A spending cap stops every model on the key, and not for a minute.
            $spendingCap = GeminiKeyManager::isSpendingCapError($response->status(), (string) $response->body());
            if ($spendingCap) {
                $this->getKeyManager()->markOutForSpendingCap($keyInfo['name']);
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
            if ($dailyQuota || $spendingCap || $badKey) {
                $keysOut++;
            }

            // On 429, a spending cap or a refused key, rotate to next key and retry
            if (($response->status() === 429 || $spendingCap || $badKey) && $attempts < $maxAttempts) {
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

            // Every key answered with a limit that lasts: the member is told the
            // limit is ours, not that the voices are busy for a minute.
            if ($keysOut === $maxAttempts) {
                throw new GeminiKeysOutException('Every Gemini TTS API key is out (HTTP ' . $response->status() . '): ' . mb_substr($response->body(), 0, 200));
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

        // All keys exhausted: for the minute, or for longer when each one tried was out.
        if ($keysOut > 0 && $keysOut === $attempts) {
            throw new GeminiKeysOutException('Every Gemini TTS API key is out (their daily quota, spending cap or a refused key).');
        }
        throw new \RuntimeException('All Gemini TTS API keys exhausted (429 rate limit). Add more keys or wait for limits to reset.');
    }

    /**
     * Extract raw audio bytes from Gemini API response.
     */
    private function extractAudioFromResponse(array $response): array
    {
        $blockReason = $response['promptFeedback']['blockReason'] ?? null;
        if (is_string($blockReason) && $blockReason !== '') {
            throw new GeminiTextRefusedException('Gemini TTS blocked the prompt: ' . $blockReason);
        }

        $candidates = $response['candidates'] ?? [];
        if (!is_array($candidates) || empty($candidates)) {
            throw new TransientGeminiException('Gemini TTS response contains no candidates.');
        }

        $finishReason = (string) ($candidates[0]['finishReason'] ?? '');
        if (in_array($finishReason, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII'], true)) {
            throw new GeminiTextRefusedException('Gemini TTS refused the text (finishReason ' . $finishReason . ').');
        }

        // Stopped at the output limit: the audio ends mid-sentence. It is asked
        // for again rather than delivered (and charged) as if it were complete.
        if ($finishReason === 'MAX_TOKENS') {
            throw new TransientGeminiException('Gemini TTS stopped at its output limit (finishReason MAX_TOKENS).');
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
        // Gemini can return raw PCM (L16): it is written as a WAV on the way.
        return $this->saveJoinedAudio(SpeechJoin::toFile([['bytes' => $audioData, 'mime_type' => $mimeTypeHint]]));
    }

    /**
     * Save a take from its temp file: packaged (MP3, ShareableAudio) and
     * written to the disk as a stream, so a long one is never held whole.
     *
     * @param  array{path:string,mime_type:string,pieces:array<int, array<int, int>>}  $joined
     */
    private function saveJoinedAudio(array $joined): string
    {
        $packaged = ['path' => $joined['path'], 'extension' => 'wav'];
        try {
            $packaged = ShareableAudio::packageFile($joined['path'], $joined['pieces']);
            $filename = 'audio/' . now()->format('Ymd_His') . '_listen.studio_' . Str::random(12) . '.' . $packaged['extension'];
            ShareableAudio::store($packaged['path'], $filename);

            return $filename;
        } finally {
            ShareableAudio::discard($joined['path'], $packaged['path']);
        }
    }

    /**
     * Build a comprehensive style prompt for Gemini TTS.
     *
     * Combines dialect, performance style, emotion, speed, and pitch into a single
     * instruction like: "Voice dialect: Egyptian Arabic. Emotional tone: warm, friendly."
     *
     * Note: Gemini TTS doesn't have explicit speed/pitch API parameters like Google Cloud TTS.
     * Instead, these are controlled via natural language instructions in the prompt.
     *
     * Without $withFallback, nothing picked gives an empty direction (see ownDirection()).
     */
    private function buildStylePrompt(string $style, string $emotion, string $targetLanguage, float $speed, ?string $dialect = null, float $pitch = 0.0, bool $withFallback = true): string
    {
        $style = strtolower(trim($style));
        $emotion = strtolower(trim($emotion));
        $isArabic = $this->isArabicLanguage($targetLanguage);

        // Build dialect instruction for Arabic
        $dialectInstruction = '';
        if ($isArabic && $dialect !== null && $dialect !== '') {
            $dialectName = self::ARABIC_DIALECT_PROMPTS[$dialect] ?? ArabicDialects::prompt($dialect);
            if ($dialectName !== null) {
                // Plain Fusha is formal Arabic, not a regional variety: it gets no regional wording.
                $fusha = ArabicDialects::isPlainFusha($dialect);
                $dialectInstruction = $fusha
                    ? "Voice dialect: {$dialectName}. Speak with clear, formal Modern Standard Arabic pronunciation. "
                    : "Voice dialect: {$dialectName}. Speak with authentic regional pronunciation and natural dialect characteristics. ";
                $markers = ArabicDialects::markers($dialect);
                if ($markers !== null) {
                    $dialectInstruction .= ($fusha ? 'Pronunciation' : 'Dialect markers')." to make clearly audible: {$markers} ";
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

        // Speed and pitch are written directions (Gemini has no such API parameters),
        // one for every step of the studio's sliders; 1.0x and 0 send nothing.
        $pace = PaceAndPitch::speed($speed);
        if ($pace !== '') {
            $prompt .= ' '.$pace;
        }
        $tone = PaceAndPitch::pitch($pitch);
        if ($tone !== '') {
            $prompt .= ' '.$tone;
        }

        // Fallback if prompt is empty
        if ($withFallback && trim($prompt) === '') {
            $prompt = $isArabic
                ? 'Read the following Arabic text naturally with clear pronunciation and a balanced, professional tone.'
                : 'Read the following text naturally with clear pronunciation and a balanced, professional tone.';
        }

        $dialectCode = strtoupper(trim((string) $dialect));
        // Fusha and its registers (news, classical, White Arabic) are not colloquial.
        if ($isArabic && $dialectCode !== '' && ! str_starts_with($dialectCode, 'AR-XA')) {
            $prompt .= $dialectCode === 'AR-EG'
                ? ' Stay in Cairene Egyptian colloquial Arabic, not Modern Standard Arabic. Pronounce ج like the English g, and use Cairo rhythm, the way someone from Cairo talks to a friend. Do not switch to formal Fusha or news-anchor pronunciation.'
                : ' Stay in this regional colloquial variety. Do not switch to Modern Standard Arabic or news-anchor Fusha.';
        }

        // Gemini 3.8 does not accept languageCode on a single-speaker request.
        // The English accent has to travel in the style text, or American,
        // British, and Australian selections all sound the same.
        if (SpeechLanguages::premiumOnly(strtolower(trim($targetLanguage)))) {
            // The language travels in the style text as well (the newer model takes no language code).
            $name = SpeechLanguages::englishName($targetLanguage);
            $prompt .= " Speak in {$name} (".SpeechLanguages::nativeName($targetLanguage)."), with natural native pronunciation and rhythm. Read the transcript exactly as written; do not translate it or switch to another language.";
        } elseif (! $isArabic) {
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
     * A member's own direction leads, as they wrote it; the dialect (or accent) and
     * the speed they picked follow it, so writing their own words no longer loses
     * those choices. Style, emotion and pitch are left to their words.
     */
    private function ownDirection(string $customPrompt, string $targetLanguage, float $speed, ?string $dialect): string
    {
        $picked = $this->buildStylePrompt('', 'neutral', $targetLanguage, $speed, $dialect, 0.0, false);

        return trim(trim($customPrompt).' '.$picked);
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
            'hopeful' => 'hopeful and optimistic, lifting toward a brighter future',
            'serious' => 'serious and weighty, every word measured',
            'playful' => 'playful and lighthearted, with a mischievous twinkle',
            'mysterious' => 'mysterious and hushed, hinting at secrets',
            'confident' => 'confident and assured, commanding attention',
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
            'curious' => 'curious and intrigued, asking with genuine interest',
            'relieved' => 'relieved, the tension finally released, letting out the worry',
            'disappointed' => 'disappointed and let down, with a sinking, deflated tone',
            'embarrassed' => 'embarrassed and awkward, a little flustered and hesitant',
            'shy' => 'shy and soft-spoken, a little hesitant and gentle',
            'bored' => 'bored and unenthusiastic, flat and slightly drawn out',
            'frustrated' => 'frustrated and exasperated, holding back irritation',
            'disgusted' => 'disgusted and repulsed, with clear distaste in the voice',
            'heartbroken' => 'heartbroken, quietly devastated, the voice close to breaking',
            'panicked' => 'panicked and urgent, breathless and fast, close to losing control',
            'amazed' => 'amazed and in awe, full of wonder',
            'reassuring' => 'reassuring and steady, calming the listener that all will be well',
            'apologetic' => 'apologetic and sincere, softly regretful',
            'menacing' => 'menacing and threatening, low, slow and controlled',
            'dreamy' => 'dreamy and far away, soft and floating',
            'sleepy' => 'sleepy and drowsy, slow, low energy, with soft yawning pauses',
            'cheerful' => 'cheerful and sunny, with a smile in every word',
            'triumphant' => 'triumphant and victorious, celebrating a hard-won win',
            'innocent' => 'innocent and pure, soft and wide-eyed',
            'sincere' => 'sincere and heartfelt, every word meant',
            'lonely' => 'lonely and withdrawn, quiet and far away',
            'guilty' => 'guilty and uneasy, hesitant and low',
            'jealous' => 'jealous and resentful, with a bitter edge',
            'furious' => 'furious and explosive, barely contained rage',
            'defiant' => 'defiant and unyielding, standing firm',
            'resigned' => 'resigned and weary, accepting what cannot change',
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
            'social_media' => 'Speak like a popular social-media creator talking to camera: fast, punchy, upbeat and personal, hooking the viewer in the first seconds.',
            'tutorial' => 'Speak like a friendly tutorial presenter: clear, step by step, patient, emphasising each action and number so it is easy to follow.',
            'stand_up' => 'Perform like a stand-up comedian on stage: conversational, confident, with timing, build-ups and punchlines landing after a short beat.',
            'horror' => 'Narrate like a horror storyteller: low, slow and chilling, with long tense pauses and a hushed voice before each scare.',
            'dubbing' => 'Perform like a drama dubbing artist: expressive and theatrical, rich articulation, every line acted with clear emotion.',
            'oratory' => 'Deliver like a powerful orator giving a speech: resonant, rhythmic and rousing, with strong emphasis and deliberate pauses for effect.',
            'reverent' => 'Read in a calm, reverent and spiritual tone, with careful, precise articulation and gentle pauses, suited to supplications and devotional texts.',
            'cartoon' => 'Perform like an animated cartoon character: bright, bouncy and exaggerated, with playful pitch jumps and lots of energy.',
            'announcer' => 'Speak like a public announcement at an airport or station: clear, calm, evenly paced and polite, with every name and number distinct.',
            'interview' => 'Speak like someone being interviewed: natural, thoughtful and articulate, answering with conversational pauses.',
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

        // 0. A premium speaker (by id or name) has a voice of their own (App\Support\SpeakerVoices);
        //    that voice itself (a library id dubbing passes on) is used as is.
        $assigned = SpeakerVoices::find($voiceName);
        if ($assigned !== null) {
            return $assigned;
        }
        if ($voiceName !== '' && SpeakerVoices::isAssigned($voiceName)) {
            return $voiceName;
        }

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

    /**
     * A child or elder speaker (App\Support\SpeakerVoices) keeps their age from
     * the first word to the last: the direction leads whatever else is asked. A
     * child is a light grown-up voice playing a child, which drifts back to its
     * own age over a long reading unless told otherwise. Anyone else's direction
     * is unchanged.
     */
    public static function asSpeaker(string $speaker, string $direction): string
    {
        $who = SpeakerVoices::speaker($speaker);
        $female = ($who['gender'] ?? '') === 'female';
        $age = match ($who['age'] ?? null) {
            'child' => 'The speaker is a real '.($female ? 'girl' : 'boy')." of about eight or nine: a child's small, high, bright voice with natural childish rhythm and energy, never an adult imitating a child. Keep the same child's voice from the first word to the last, however long the reading.",
            'elder' => 'The speaker is an older '.($female ? 'woman in her' : 'man in his').' late sixties: the settled, slightly weathered voice of that age, natural and steady, never shaky or exaggerated. Keep that age from the first word to the last.',
            default => '',
        };

        return $age === '' ? $direction : trim($age.' '.$direction);
    }

    /**
     * Whether the speaker is a child (App\Support\SpeakerVoices).
     */
    private static function isChild(string $speaker): bool
    {
        return (SpeakerVoices::speaker($speaker)['age'] ?? null) === 'child';
    }

    /**
     * A dialogue speaker's voice: a library voice is named the 3.8 way, a studio voice as before.
     *
     * @return array<string, mixed>
     */
    public static function dialogueVoiceConfig(string $voice): array
    {
        return in_array($voice, self::VOICES, true)
            ? ['prebuiltVoiceConfig' => ['voiceName' => $voice]]
            : ['voice' => $voice];
    }

    /**
     * "male" or "female" for a studio voice or a speaker's library voice, or null when unknown.
     */
    public static function voiceGender(string $voiceName): ?string
    {
        return self::VOICE_GENDER_MAP[$voiceName] ?? SpeakerVoices::genderOf($voiceName);
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
     * Input text is charged per character.
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
