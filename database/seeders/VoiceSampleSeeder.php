<?php

namespace Database\Seeders;

use App\Console\Commands\GenerateVoiceSamples;
use App\Models\VoiceSample;
use Illuminate\Database\Seeder;

class VoiceSampleSeeder extends Seeder
{
    private const AUDIO_PATHS = [
        'إعلان موشن جرافيك سعودي' => 'audio/20260304_081425_listen.studio_9SAwYxyqqeRI.wav',
        'رواية صوتية عربية' => 'audio/20260304_081236_listen.studio_Xtyraqs2j7ZY.wav',
        'إعلان ورشة عمل مصري' => 'audio/20260304_081456_listen.studio_1JNptZKiBvVw.wav',
        'وثائقي الحضارة العربية' => 'audio/20260304_081255_listen.studio_EbdMoYdLR8nu.wav',
        'درس تعليمي - الذكاء الاصطناعي' => 'audio/20260304_081311_listen.studio_fReip0LGeDKS.wav',
        'قصة أطفال سحرية' => 'audio/20260304_081328_listen.studio_imqefPzZlz3p.wav',
        'خطاب تحفيزي' => 'audio/20260304_081345_listen.studio_qwBvOhRfIIKe.wav',
        'مقدّمة بودكاست عربي' => 'audio/20260304_081357_listen.studio_WVn1TFwHXyL5.wav',
        'إعلان ترويجي بصوت طفولي' => 'audio/20260304_081440_listen.studio_gCqtuNYCZ5hp.wav',
        'برنامج طبخ مصري' => 'audio/20260304_081509_listen.studio_igip4duQ1Ptd.wav',
        'إعلان تجميل إماراتي' => 'audio/20260304_081525_listen.studio_guL0AkYvvEt7.wav',
        'برنامج إذاعي لبناني' => 'audio/20260304_081609_listen.studio_VLmPtDXPlE69.wav',
        'بودكاست قصة نجاح أردني' => 'audio/20260304_081624_listen.studio_GCazAeuujpov.wav',
        'حكاية فلسطينية' => 'audio/20260304_081644_listen.studio_xRsslYPtZare.wav',
        'قصيدة عراقية' => 'audio/20260304_081701_listen.studio_dqblJnWGjRGn.wav',
        'دراما تاريخية سورية' => 'audio/20260304_081717_listen.studio_LQAacYeDZfPr.wav',
        'وثائقي الحضارة المغربية' => 'audio/20260304_081734_listen.studio_WYjRVIgScQXf.wav',
        'إعلان برنامج مكافآت كويتي' => 'audio/20260304_081749_listen.studio_UuQNfpua5yrR.wav',
        'إعلان مصرفي بحريني' => 'audio/20260304_081805_listen.studio_inwpSNZK4Bne.wav',
        'وثائقي طبيعة عمانية' => 'audio/20260304_083853_listen.studio_8691oOn39pIS.wav',
        'دليل سياحي قطري' => 'audio/20260304_082025_listen.studio_LQFnY3Uepqia.wav',
        'حكاية سودانية' => 'audio/20260304_082054_listen.studio_JPCLf8duFvaB.wav',
        'درس تعليمي تونسي' => 'audio/20260304_083236_listen.studio_K9PYTTEAFRTz.wav',
        'مساعد ذكي يمني' => 'audio/20260304_082113_listen.studio_sWGdd6xi1hEP.wav',
        'إعلان سياحي ليبي' => 'audio/20260304_082130_listen.studio_HQ9Jq38SXFHV.wav',
        'Luxury Brand Story' => 'audio/20260304_083312_listen.studio_VnBejDzOv1ge.wav',
        'E-Learning Course' => 'audio/20260304_083327_listen.studio_qwhRwgyy4fSv.wav',
        'True Crime Podcast' => 'audio/20260304_083343_listen.studio_JG4RPKUZLrVA.wav',
        'Corporate Training' => 'audio/20260304_083359_listen.studio_cS44lHaiZwcm.wav',
        'Travel Documentary' => 'audio/20260304_083414_listen.studio_96QTRhD3llyp.wav',
        'Meditation Guide' => 'audio/20260304_084319_listen.studio_SY5c5hfCFXJP.wav',
        'Breaking News Bulletin' => 'audio/20260304_083630_listen.studio_PdGbQpsXxB48.wav',
        'Children\'s Audiobook' => 'audio/20260304_083645_listen.studio_viuBIHzteM2R.wav',
        'Fitness Coaching' => 'audio/20260304_083702_listen.studio_m8DoMdFNZkoA.wav',
        'Science Documentary' => 'audio/20260304_083722_listen.studio_do6CnBaS2kFt.wav',
        'Romantic Audiobook' => 'audio/20260304_083736_listen.studio_CMjLWO8VDvQg.wav',
    ];

    private const SORT_ORDERS = [
        'إعلان موشن جرافيك سعودي' => 1,
        'رواية صوتية عربية' => 2,
        'إعلان ورشة عمل مصري' => 2,
        'وثائقي الحضارة العربية' => 3,
        'درس تعليمي - الذكاء الاصطناعي' => 4,
        'قصة أطفال سحرية' => 5,
        'خطاب تحفيزي' => 6,
        'مقدّمة بودكاست عربي' => 7,
        'إعلان ترويجي بصوت طفولي' => 9,
        'برنامج طبخ مصري' => 11,
        'إعلان تجميل إماراتي' => 12,
        'برنامج إذاعي لبناني' => 13,
        'بودكاست قصة نجاح أردني' => 14,
        'حكاية فلسطينية' => 15,
        'قصيدة عراقية' => 16,
        'دراما تاريخية سورية' => 17,
        'وثائقي الحضارة المغربية' => 18,
        'إعلان برنامج مكافآت كويتي' => 19,
        'إعلان مصرفي بحريني' => 20,
        'وثائقي طبيعة عمانية' => 21,
        'دليل سياحي قطري' => 22,
        'حكاية سودانية' => 23,
        'درس تعليمي تونسي' => 24,
        'مساعد ذكي يمني' => 25,
        'إعلان سياحي ليبي' => 26,
        'Listen Premium Welcome' => 27,
        'Luxury Brand Story' => 28,
        'E-Learning Course' => 29,
        'True Crime Podcast' => 30,
        'Corporate Training' => 31,
        'Travel Documentary' => 32,
        'Meditation Guide' => 33,
        'Breaking News Bulletin' => 34,
        'Children\'s Audiobook' => 35,
        'Fitness Coaching' => 36,
        'Science Documentary' => 37,
        'Romantic Audiobook' => 38,
    ];

    private const INACTIVE_TITLES = [
        'إعلان تجميل إماراتي',
    ];

    public function run(): void
    {
        foreach ($this->records() as $record) {
            VoiceSample::updateOrCreate(
                [
                    'title' => $record['title'],
                    'category' => $record['category'],
                    'language' => $record['language'],
                ],
                $record
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        $records = [];

        $records[] = [
            'title' => 'رسالة ترحيب بمنصة إسمع',
            'title_ar' => 'رسالة ترحيب بمنصة إسمع',
            'description' => 'Egyptian Arabic premium welcome message',
            'description_ar' => 'رسالة ترحيب عربية مميزة لمنصة إسمع',
            'icon' => '📺',
            'sample_text' => 'مرحباً بك في منصة إسمع. حوّل نصك إلى صوت احترافي واضح وجذاب في ثوانٍ. جودة عالية وتجربة ذكية تبرز رسالتك بثقة. هذا الصوت مولَّد بالذكاء الاصطناعي عبر منصة إسمع.',
            'sample_text_ar' => 'مرحباً بك في منصة إسمع. حوّل نصك إلى صوت احترافي واضح وجذاب في ثوانٍ. جودة عالية وتجربة ذكية تبرز رسالتك بثقة. هذا الصوت مولَّد بالذكاء الاصطناعي عبر منصة إسمع.',
            'audio_path' => 'audio/hero_ar_abdulaziz_20260307.wav',
            'language' => 'ar',
            'dialect' => 'ar-XA',
            'country' => 'XA',
            'emotion' => 'neutral',
            'gender' => 'male',
            'voice_name' => 'ar-XA-Chirp3-HD-Leda',
            'provider' => 'google_cloud',
            'sort_order' => 0,
            'is_featured' => true,
            'is_active' => true,
            'category' => 'hero',
            'duration_seconds' => 14,
            'metadata' => [
                'speaker_name' => 'Abdulaziz',
                'speaker_name_ar' => 'عبدالعزيز',
            ],
        ];

        foreach (GenerateVoiceSamples::SAMPLES as $index => $sample) {
            $title = $sample['title'];

            if (in_array($title, ['نشرة أخبار رسمية', 'SaaS Product Launch'], true)) {
                continue;
            }

            $text = $sample['language'] === 'ar' ? ($sample['text_ar'] ?? $sample['text']) : $sample['text'];

            $records[] = [
                'title' => $title,
                'title_ar' => $sample['title_ar'] ?? null,
                'description' => $sample['description'] ?? null,
                'description_ar' => $sample['description_ar'] ?? null,
                'icon' => $sample['icon'] ?? null,
                'sample_text' => $text,
                'sample_text_ar' => $sample['text_ar'] ?? $sample['text'],
                'audio_path' => self::AUDIO_PATHS[$title] ?? ('audio/' . $sample['language'] . '.mp3'),
                'language' => $sample['language'],
                'dialect' => $sample['dialect'] ?? null,
                'country' => $sample['country'] ?? null,
                'emotion' => $sample['emotion'] ?? null,
                'gender' => $sample['gender'] ?? null,
                'voice_name' => $sample['google_voice'] ?? null,
                'provider' => 'gemini',
                'sort_order' => self::SORT_ORDERS[$title] ?? ($index + 1),
                'is_featured' => (bool) ($sample['is_featured'] ?? false),
                'is_active' => ! in_array($title, self::INACTIVE_TITLES, true),
                'category' => $sample['category'] ?? 'hero',
                'duration_seconds' => 0,
                'metadata' => null,
            ];
        }

        $records[] = [
            'title' => 'Listen Premium Welcome',
            'title_ar' => 'رسالة ترحيب إنجليزية لمنصة إسمع',
            'description' => 'Premium English welcome message for Listen',
            'description_ar' => 'رسالة ترحيب إنجليزية مميزة لمنصة إسمع',
            'icon' => '🇺🇸',
            'sample_text' => 'Welcome to Listen. Turn your words into polished premium voice for faster, clearer communication. This voice was created with AI and generated through Listen platform.',
            'sample_text_ar' => 'مرحباً بك في منصة إسمع. حوّل كلماتك إلى صوت احترافي واضح وسريع. هذا الصوت تم إنشاؤه بالذكاء الاصطناعي عبر منصة إسمع.',
            'audio_path' => 'audio/20260306_223109_listen.studio_l0wpWRfDf6lm.wav',
            'language' => 'en',
            'dialect' => 'en-US',
            'country' => 'US',
            'emotion' => 'neutral',
            'gender' => 'female',
            'voice_name' => 'en-Gemini-HD-Zephyr',
            'provider' => 'gemini',
            'sort_order' => 27,
            'is_featured' => true,
            'is_active' => true,
            'category' => 'hero',
            'duration_seconds' => 16,
            'metadata' => [
                'speaker_name' => 'Clara',
            ],
        ];

        usort($records, fn (array $a, array $b) => [$a['sort_order'], $a['title']] <=> [$b['sort_order'], $b['title']]);

        return $records;
    }
}
