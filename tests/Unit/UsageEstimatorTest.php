<?php

namespace Tests\Unit;

use App\Support\Credits;
use App\Support\UsageEstimator;
use App\Support\VoiceTier;
use PHPUnit\Framework\TestCase;

/**
 * The studio's "≈ 0:15 of audio · 225 credits" and what the studio, the
 * extension and the API reserve: from rates measured on live audio (see
 * UsageEstimator). Members are charged the real length afterwards.
 */
class UsageEstimatorTest extends TestCase
{
    private const OWNERS_CLIP = 'مالك يا صديقى ايه الى حصل [متوتر] كل مرة تقولى مفيش حاجة بس انا قلقان عليك ما تفهمنى <بلع ريق> بس عامة انت حبيبى <قبلة> ولا تزعل نفسك';

    public function test_every_character_is_billable_spaces_and_line_breaks_too(): void
    {
        // Gemini bills every character it reads, so members are charged for them too.
        $this->assertSame(20, UsageEstimator::billableChars(" Hi, Test! 123 -- @#$ \n\t "));
        $this->assertSame(11, UsageEstimator::billableChars("مرحبا\nبالكل"));
        $this->assertSame(0, UsageEstimator::billableChars("  \n  "));
    }

    public function test_arabic_takes_longer_than_the_old_flat_rate_said(): void
    {
        // 360 Arabic letters: standard voices read about 9.4 a second, not 15 characters.
        $text = trim(str_repeat('كتب الولد درسه ', 30));
        $standard = UsageEstimator::estimate($text, VoiceTier::STANDARD);
        $this->assertSame(mb_strlen($text), $standard['chars']);
        $this->assertSame(360, UsageEstimator::features($text)['arabic']);
        $this->assertEqualsWithDelta((0.27 + 360 * 0.106) * UsageEstimator::MARGIN[VoiceTier::STANDARD], $standard['seconds'], 0.5);
        $this->assertGreaterThan((int) ceil(mb_strlen($text) / 15), $standard['seconds']);
        $this->assertSame(Credits::forSeconds($standard['seconds']), $standard['credits']);
    }

    public function test_sounds_moods_studio_settings_and_a_written_direction_add_time_on_a_premium_voice(): void
    {
        $plain = UsageEstimator::estimate('مالك يا صديقى ايه الى حصل كل مرة تقولى مفيش حاجة', VoiceTier::HD)['seconds'];
        $tagged = UsageEstimator::estimate('مالك يا صديقى <ضحكة> ايه الى حصل [حزين] كل مرة تقولى <تنهيدة> مفيش حاجة', VoiceTier::HD)['seconds'];
        // Two sounds (1.3 s each) and a second take.
        $this->assertGreaterThanOrEqual($plain + 2, $tagged);

        $features = UsageEstimator::features(self::OWNERS_CLIP);
        $this->assertSame(['sound' => 2, 'mood' => 1], ['sound' => $features['sound'], 'mood' => $features['mood']]);
        // The tags themselves are not read: only the words' letters count.
        $this->assertSame(85, $features['arabic']);
        // The owner's clip (premium, 15 s on live) with no studio setting: 11 s. Settings
        // that read slower show it longer: a cinematic style, more with a written
        // direction; a storytelling style in a Yemeni dialect (member 5369's).
        $this->assertSame(11, UsageEstimator::estimate(self::OWNERS_CLIP, VoiceTier::HD)['seconds']);
        $this->assertSame(15, UsageEstimator::estimate(self::OWNERS_CLIP, VoiceTier::HD, 1.0, ['performance_style' => 'cinematic'])['seconds']);
        $this->assertSame(16, UsageEstimator::estimate(self::OWNERS_CLIP, VoiceTier::HD, 1.0, [
            'performance_style' => 'cinematic', 'style_prompt' => 'Whisper it, slowly',
        ])['seconds']);
        $this->assertSame(14, UsageEstimator::estimate(self::OWNERS_CLIP, VoiceTier::HD, 1.0, [
            'performance_style' => 'storytelling', 'emotion' => 'warm', 'dialect' => 'ar-YE',
        ])['seconds']);
    }

    public function test_each_language_has_its_own_factors_for_the_studio_settings(): void
    {
        // Measured apart: a serious Arabic voice reads a quarter slower, while English
        // keeps the factors it had (a serious emotion changes nothing there).
        $arabic = UsageEstimator::features(self::OWNERS_CLIP);
        $english = UsageEstimator::features(str_repeat('The quick brown fox jumps over the lazy dog. ', 6));
        $this->assertEqualsWithDelta(1.25 * UsageEstimator::rawSeconds($arabic, VoiceTier::HD),
            UsageEstimator::rawSeconds($arabic, VoiceTier::HD, 1.0, ['emotion' => 'serious']), 0.001);
        $this->assertEqualsWithDelta(UsageEstimator::rawSeconds($english, VoiceTier::HD),
            UsageEstimator::rawSeconds($english, VoiceTier::HD, 1.0, ['emotion' => 'serious']), 0.001);
        // A meditation style: 1.13 on an Arabic voice, 1.22 on an English one.
        $this->assertEqualsWithDelta(1.13 * UsageEstimator::rawSeconds($arabic, VoiceTier::HD),
            UsageEstimator::rawSeconds($arabic, VoiceTier::HD, 1.0, ['performance_style' => 'meditation']), 0.001);
        $this->assertEqualsWithDelta(1.22 * UsageEstimator::rawSeconds($english, VoiceTier::HD),
            UsageEstimator::rawSeconds($english, VoiceTier::HD, 1.0, ['performance_style' => 'meditation']), 0.001);

        // The studio script gets each language's; an older copy of it (the browser
        // extension's, until its next version is installed) reads one table, English's.
        $config = UsageEstimator::clientConfig();
        $this->assertSame(UsageEstimator::STUDIO_FACTORS, $config['studio_factors_by_language']);
        $this->assertSame(UsageEstimator::STUDIO_FACTORS['en'], $config['studio_factors']);
    }

    public function test_a_standard_voice_skips_sounds_and_ignores_the_studio_settings(): void
    {
        $text = 'Hello there <laugh> my friend.';
        $this->assertSame(
            UsageEstimator::estimate('Hello there my friend.', VoiceTier::STANDARD)['seconds'],
            UsageEstimator::estimate($text, VoiceTier::STANDARD, 0.5, ['performance_style' => 'meditation'])['seconds'],
        );
    }

    public function test_vowel_marks_do_not_split_words_or_inflate_the_estimate(): void
    {
        $marked = UsageEstimator::estimate('بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ، الْحَمْدُ لِلَّهِ رَبِّ الْعَالَمِينَ.', VoiceTier::HD);
        $bare = UsageEstimator::estimate('بسم الله الرحمن الرحيم، الحمد لله رب العالمين.', VoiceTier::HD);

        $this->assertSame(8, $marked['words']);
        // The old estimate read every marked letter as a word: about three times too long.
        $this->assertLessThanOrEqual($bare['seconds'] + 1, $marked['seconds']);
    }

    public function test_the_pace_setting_on_a_premium_voice(): void
    {
        $text = str_repeat('The quick brown fox jumps over the lazy dog. ', 6);
        $normal = UsageEstimator::estimate($text, VoiceTier::HD)['seconds'];

        // Slowing down works a little: pace 0.5 is about 1.2 times as long (measured on
        // live: 1.16 to 1.22), not the 1.6 the older engine read it at.
        $this->assertEqualsWithDelta($normal * 1.19, UsageEstimator::estimate($text, VoiceTier::HD, 0.5)['seconds'], 1.0);
        // Speeding up hardly changes it.
        $features = UsageEstimator::features($text);
        $faster = UsageEstimator::rawSeconds($features, VoiceTier::HD, 1.5);
        $this->assertLessThan(UsageEstimator::rawSeconds($features, VoiceTier::HD), $faster);
        $this->assertGreaterThan(0.95 * UsageEstimator::rawSeconds($features, VoiceTier::HD), $faster);
    }

    public function test_an_empty_text_costs_nothing_and_a_word_at_least_a_second(): void
    {
        $this->assertSame(0, UsageEstimator::estimate("  \n ")['credits']);
        $this->assertSame(1, UsageEstimator::estimate('Hi', VoiceTier::STANDARD)['seconds']);
        $this->assertSame(1, UsageEstimator::estimate('Hi')['minutes']);
    }
}
