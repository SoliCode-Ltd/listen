<?php

namespace App\Support;

use App\Models\SiteSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeoHelper
{
    private const DEFAULT_META_ROBOTS = 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';

    private const ALLOWED_CHANGE_FREQUENCIES = [
        'always',
        'hourly',
        'daily',
        'weekly',
        'monthly',
        'yearly',
        'never',
    ];

    /**
     * Canonical page registry used by SEO settings, sitemap generation, and fallback metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pageDefinitions(): array
    {
        return [
            'home' => [
                'label' => 'Home',
                'title' => 'Listen AI Text to Speech',
                'title_key' => 'ui.seo.home_title',
                'description' => 'Convert text into natural, multilingual speech with premium Arabic and English AI voices.',
                'description_key' => 'ui.seo.home_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 1.0,
                'sitemap_change_frequency' => 'daily',
            ],
            'pricing' => [
                'label' => 'Pricing',
                'title' => 'AI Text to Speech Pricing',
                'title_key' => 'ui.seo.pricing_title',
                'description' => 'Compare Listen plans and choose the right AI voice generation package for your workflow.',
                'description_key' => 'ui.seo.pricing_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/pricing',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.9,
                'sitemap_change_frequency' => 'weekly',
            ],
            'samples' => [
                'label' => 'Voice samples',
                'title' => 'English AI Voice Samples: Accents, Moods and Conversations',
                'title_key' => 'ui.seo.samples_title',
                'description' => 'Hear Listen\'s English voices: accents from around the world, moods, two-voice conversations and sound environments.',
                'description_key' => 'ui.seo.samples_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/voice-samples',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.9,
                'sitemap_change_frequency' => 'weekly',
            ],
            'contact' => [
                'label' => 'Contact',
                'title' => 'Contact Listen',
                'title_key' => 'ui.seo.contact_title',
                'description' => 'Contact the Listen team for support, sales questions, and partnership inquiries.',
                'description_key' => 'ui.seo.contact_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/contact-us',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.7,
                'sitemap_change_frequency' => 'monthly',
            ],
            'extension' => [
                'label' => 'Browser Extension',
                'title' => 'Text to Speech Browser Extension',
                'title_key' => 'ui.seo.extension_title',
                'description' => 'Turn web pages into natural audio with the Listen browser extension for Arabic and English.',
                'description_key' => 'ui.seo.extension_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/browsers-extension',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.8,
                'sitemap_change_frequency' => 'weekly',
            ],
            'developers' => [
                'label' => 'Developer API',
                'title' => 'Text to Speech API for Arabic and English',
                'title_key' => 'developers.docs.seo_title',
                'description' => 'The Listen text to speech API: Arabic dialects, English accents and the studio voices, metered on your plan credits.',
                'description_key' => 'developers.docs.seo_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/developers',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.7,
                'sitemap_change_frequency' => 'monthly',
            ],
            'privacy' => [
                'label' => 'Privacy Policy',
                'title' => 'Privacy Policy',
                'title_key' => 'ui.seo.privacy_title',
                'description' => 'Read the Listen privacy policy to understand how user and usage data is handled.',
                'description_key' => 'ui.seo.privacy_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/privacy-policy',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.3,
                'sitemap_change_frequency' => 'yearly',
            ],
            'terms' => [
                'label' => 'Terms of Service',
                'title' => 'Terms of Service',
                'title_key' => 'ui.seo.terms_title',
                'description' => 'Review the legal terms that govern using Listen services and products.',
                'description_key' => 'ui.seo.terms_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/terms-of-service',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.3,
                'sitemap_change_frequency' => 'yearly',
            ],
            'cookies' => [
                'label' => 'Cookie Policy',
                'title' => 'Cookie Policy',
                'title_key' => 'ui.seo.cookies_title',
                'description' => 'Learn how Listen uses cookies and related technologies across the website.',
                'description_key' => 'ui.seo.cookies_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/cookie-policy',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.3,
                'sitemap_change_frequency' => 'yearly',
            ],
            'ai_text_to_speech' => [
                'label' => 'AI Text to Speech',
                'title' => 'AI Text to Speech',
                'title_key' => 'ui.seo.ai_text_to_speech_title',
                'description' => 'Convert text to lifelike audio with Arabic and English AI text to speech built for creators and teams.',
                'description_key' => 'ui.seo.ai_text_to_speech_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/ai-text-to-speech',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.85,
                'sitemap_change_frequency' => 'weekly',
            ],
            'ai_voice_generator' => [
                'label' => 'AI Voice Generator',
                'title' => 'AI Voice Generator',
                'title_key' => 'ui.seo.ai_voice_generator_title',
                'description' => 'Generate premium AI voices for narration, product videos, explainers, and multilingual content.',
                'description_key' => 'ui.seo.ai_voice_generator_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/ai-voice-generator',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.85,
                'sitemap_change_frequency' => 'weekly',
            ],
            'text_to_speech_arabic' => [
                'label' => 'Arabic Text to Speech',
                'title' => 'Arabic Text to Speech',
                'title_key' => 'ui.seo.text_to_speech_arabic_title',
                'description' => 'Create natural Arabic text to speech audio with premium voices for content, products, and accessibility.',
                'description_key' => 'ui.seo.text_to_speech_arabic_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/text-to-speech-arabic',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.82,
                'sitemap_change_frequency' => 'weekly',
            ],
            'text_to_speech' => [
                'label' => 'Text to Speech Online',
                'title' => 'Text to Speech Online in Arabic and English',
                'title_key' => 'ui.seo.text_to_speech_title',
                'description' => 'Turn writing into natural speech online: paste your text, pick a voice, a language and a dialect or accent, and download MP3.',
                'description_key' => 'ui.seo.text_to_speech_description',
                'robots' => self::DEFAULT_META_ROBOTS,
                'path' => '/text-to-speech',
                'localized' => true,
                'indexable' => true,
                'sitemap_priority' => 0.9,
                'sitemap_change_frequency' => 'weekly',
            ],
            'demo' => [
                'label' => 'Demo',
                'title' => 'Demo',
                'title_key' => 'ui.seo.demo_title',
                'description' => 'Try Listen text to speech online and hear instant voice previews in multiple languages.',
                'description_key' => 'ui.seo.demo_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/demo',
                'localized' => true,
                'indexable' => false,
            ],
            'login' => [
                'label' => 'Login',
                'title' => 'Sign In',
                'title_key' => 'ui.seo.login_title',
                'description' => 'Sign in to your Listen account and continue your voice generation workflow.',
                'description_key' => 'ui.seo.login_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/login',
                'localized' => true,
                'indexable' => false,
            ],
            'register' => [
                'label' => 'Register',
                'title' => 'Create Account',
                'title_key' => 'ui.seo.register_title',
                'description' => 'Create a Listen account and start converting text into high-quality AI speech.',
                'description_key' => 'ui.seo.register_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/register',
                'localized' => true,
                'indexable' => false,
            ],
            'dashboard' => [
                'label' => 'Dashboard',
                'title' => 'Dashboard',
                'title_key' => 'ui.seo.dashboard_title',
                'description' => 'Manage audio jobs, voices, and account usage from your Listen dashboard.',
                'description_key' => 'ui.seo.dashboard_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/dashboard',
                'localized' => true,
                'indexable' => false,
            ],
            'billing' => [
                'label' => 'Billing',
                'title' => 'Billing',
                'title_key' => 'ui.seo.billing_title',
                'description' => 'Manage Listen billing details, invoices, and subscription settings.',
                'description_key' => 'ui.seo.billing_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/billing',
                'localized' => true,
                'indexable' => false,
            ],
            'manage_subscription' => [
                'label' => 'Manage Subscription',
                'title' => 'Manage Subscription',
                'title_key' => 'ui.seo.manage_subscription_title',
                'description' => 'Update or cancel your Listen subscription and payment preferences.',
                'description_key' => 'ui.seo.manage_subscription_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/manage-subscription',
                'localized' => true,
                'indexable' => false,
            ],
            'upgrade' => [
                'label' => 'Upgrade',
                'title' => 'Upgrade',
                'title_key' => 'ui.seo.upgrade_title',
                'description' => 'Upgrade your Listen plan to unlock more monthly voice generation capacity.',
                'description_key' => 'ui.seo.upgrade_description',
                'robots' => 'noindex,nofollow,noarchive,nosnippet',
                'path' => '/upgrade',
                'localized' => true,
                'indexable' => false,
            ],
        ] + SeoDialectPages::pageDefinitions(self::DEFAULT_META_ROBOTS)
          + SeoEnglishPages::pageDefinitions(self::DEFAULT_META_ROBOTS);
    }

    /**
     * Pages edited one by one in the admin SEO settings. Generated pages (the
     * Arabic dialect pages) take their metadata from SeoDialectPages instead.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function adminPageDefinitions(): array
    {
        return array_filter(
            self::pageDefinitions(),
            static fn (array $definition): bool => ! ($definition['generated'] ?? false)
        );
    }

    /**
     * Default SEO landing page content used by public pages and prefilled in admin SEO settings.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function landingPageContentDefaults(): array
    {
        return [
            'ai_text_to_speech' => [
                'en' => [
                    'eyebrow' => 'AI Text to Speech',
                    'h1' => 'AI Text to Speech for Arabic and English Content Teams',
                    'intro' => 'Listen helps teams turn scripts, articles, help-center content, and product copy into natural audio with reliable quality and fast production turnaround.',
                    'highlights_title' => 'Why teams choose this workflow',
                    'highlights_intro' => 'Built for real production output, not one-off experiments.',
                    'highlights' => [
                        ['title' => 'Natural bilingual output', 'body' => 'Generate polished Arabic and English speech from one platform with consistent tone and clarity.'],
                        ['title' => 'Production-ready speed', 'body' => 'Move from text draft to publishable audio quickly when campaigns, product copy, or docs change.'],
                        ['title' => 'Designed for modern teams', 'body' => 'Support product, growth, support, and education teams with one shared voice workflow.'],
                    ],
                    'use_cases_title' => 'Practical use cases',
                    'use_cases_intro' => 'Use the same pipeline across core customer touchpoints.',
                    'use_cases' => [
                        ['title' => 'Product onboarding audio', 'body' => 'Turn onboarding steps and walkthrough scripts into clear voice guidance for faster product adoption.'],
                        ['title' => 'Marketing and landing pages', 'body' => 'Add voice narration to campaigns, feature pages, and launch content with a premium branded tone.'],
                        ['title' => 'Support and knowledge bases', 'body' => 'Convert help-center articles into audio to improve accessibility and reduce reading friction.'],
                        ['title' => 'Internal training content', 'body' => 'Create consistent voiceovers for SOPs, team training, and operational documentation.'],
                    ],
                    'faq_title' => 'Frequently asked questions',
                    'faqs' => [
                        ['question' => 'What is AI text to speech used for?', 'answer' => 'Teams use AI text to speech to convert written content into spoken audio for products, marketing, support, and training.'],
                        ['question' => 'Which languages does Listen speak?', 'answer' => 'Arabic, in Fusha and '.DialectCounts::arabic().'+ regional accents, and English in more than '.DialectCounts::english().' accents, from American and British to English with a Spanish, French or Arabic accent.'],
                        ['question' => 'Can I test before I commit to a paid plan?', 'answer' => 'Yes. Every new account gets a small allowance to try the voices in the demo before choosing a plan.'],
                        ['question' => 'Who is this page for?', 'answer' => 'It is ideal for creators, startups, product teams, agencies, and organizations that publish multilingual audio content.'],
                    ],
                    'cta_title' => 'Start generating production-ready audio',
                    'cta_body' => 'Launch your next voice workflow with Listen and move from text to publishable audio in minutes.',
                    'cta_primary' => 'Create your account',
                ],
                'ar' => [
                    'eyebrow' => 'تحويل النص إلى صوت',
                    'h1' => 'تحويل النص إلى صوت بالذكاء الاصطناعي لفرق المحتوى العربية والإنجليزية',
                    'intro' => 'تساعدك منصة إسمع على تحويل النصوص والمقالات ورسائل المنتج ومحتوى الدعم إلى صوت طبيعي بجودة ثابتة وسرعة مناسبة للإنتاج الفعلي.',
                    'highlights_title' => 'لماذا تعتمد الفرق هذا المسار',
                    'highlights_intro' => 'مناسب للتنفيذ اليومي داخل فرق المنتج والمحتوى، وليس مجرد تجربة مؤقتة.',
                    'highlights' => [
                        ['title' => 'مخرجات ثنائية اللغة بطابع طبيعي', 'body' => 'أنشئ صوتاً احترافياً بالعربية والإنجليزية من منصة واحدة مع نبرة متناسقة ووضوح عالٍ.'],
                        ['title' => 'سرعة مناسبة للإنتاج', 'body' => 'انتقل من النص إلى صوت قابل للنشر بسرعة عند تحديث الحملات أو محتوى المنتج أو التوثيق.'],
                        ['title' => 'مصمم لفرق العمل الحديثة', 'body' => 'خدم فرق المنتج والنمو والدعم والتدريب عبر سير عمل واحد قابل للتوسع.'],
                    ],
                    'use_cases_title' => 'حالات استخدام عملية',
                    'use_cases_intro' => 'طبّق نفس المسار على أهم نقاط التواصل مع المستخدم.',
                    'use_cases' => [
                        ['title' => 'الصوت داخل تجربة البدء بالمنتج', 'body' => 'حوّل خطوات الإعداد والشروحات إلى صوت واضح يساعد المستخدمين على الفهم السريع.'],
                        ['title' => 'محتوى التسويق وصفحات الهبوط', 'body' => 'أضف تعليقاً صوتياً احترافياً للحملات وصفحات الميزات ومحتوى الإطلاق.'],
                        ['title' => 'الدعم وقاعدة المعرفة', 'body' => 'حوّل مقالات المساعدة إلى صوت لتحسين الوصول وتقليل الجهد على المستخدم.'],
                        ['title' => 'التدريب الداخلي والتشغيلي', 'body' => 'أنشئ مقاطع صوتية موحدة لعمليات التدريب والإجراءات الداخلية.'],
                    ],
                    'faq_title' => 'الأسئلة الشائعة',
                    'faqs' => [
                        ['question' => 'في ماذا يُستخدم تحويل النص إلى صوت بالذكاء الاصطناعي؟', 'answer' => 'يُستخدم لتحويل المحتوى المكتوب إلى صوت في المنتجات، والتسويق، والدعم، والتدريب، وتجارب الوصول الرقمي.'],
                        ['question' => 'ما اللغات التي تتحدث بها منصة إسمع؟', 'answer' => 'العربية بالفصحى وبـ'.DialectCounts::arabic().' لكنة محلية، والإنجليزية بأكثر من '.DialectCounts::english().' لكنة، من الأمريكية والبريطانية إلى الإنجليزية بلكنة إسبانية أو فرنسية أو عربية.'],
                        ['question' => 'هل يمكن التجربة قبل الاشتراك المدفوع؟', 'answer' => 'نعم، يحصل كل حساب جديد على رصيد صغير لتجربة الأصوات في العرض التجريبي قبل اختيار الباقة.'],
                        ['question' => 'لمن تناسب هذه الصفحة؟', 'answer' => 'تناسب صناع المحتوى، والشركات الناشئة، وفرق المنتجات، والجهات التعليمية، والفرق التي تعمل بلغتين.'],
                    ],
                    'cta_title' => 'ابدأ إنتاج صوت جاهز للنشر',
                    'cta_body' => 'ابدأ مع منصة إسمع وحوّل النص إلى صوت احترافي خلال دقائق.',
                    'cta_primary' => 'أنشئ حسابك',
                ],
            ],
            'ai_voice_generator' => [
                'en' => [
                    'eyebrow' => 'AI Voice Generator',
                    'h1' => 'AI Voice Generator for Demos, Ads, Courses, and Product UX',
                    'intro' => 'Create natural AI voiceovers for launches, explainers, social campaigns, and customer education with a workflow built for high-output teams.',
                    'highlights_title' => 'What makes this generator different',
                    'highlights_intro' => 'Optimized for quality, speed, and repeatable production.',
                    'highlights' => [
                        ['title' => 'Consistent brand voice', 'body' => 'Keep tone and quality aligned across campaigns, onboarding, training, and customer communication.'],
                        ['title' => 'Arabic + English from one stack', 'body' => 'Serve regional and global audiences without splitting your production flow across multiple tools.'],
                        ['title' => 'Fast revision cycles', 'body' => 'Regenerate clips quickly when scripts change, offers update, or product messaging evolves.'],
                    ],
                    'use_cases_title' => 'Where teams use it most',
                    'use_cases_intro' => 'A practical generator for growth, product, and operations.',
                    'use_cases' => [
                        ['title' => 'Product demos and launch videos', 'body' => 'Create clear narration for feature launches, onboarding walkthroughs, and in-app education.'],
                        ['title' => 'Performance marketing creatives', 'body' => 'Produce voiceovers for paid ads, social media variants, and multilingual campaign assets.'],
                        ['title' => 'Training and enablement', 'body' => 'Generate voice content for sales onboarding, support training, and internal learning paths.'],
                        ['title' => 'Customer communication content', 'body' => 'Use high-quality voice in help content, updates, and product explainers for better retention.'],
                    ],
                    'faq_title' => 'Frequently asked questions',
                    'faqs' => [
                        ['question' => 'What does an AI voice generator do?', 'answer' => 'It converts written scripts into spoken audio that can be used in media, product experiences, onboarding, and operations.'],
                        ['question' => 'Why use Listen instead of generic tools?', 'answer' => 'Listen focuses on premium Arabic and English output, production-ready speed, and a workflow built for teams.'],
                        ['question' => 'Can I use this for marketing content?', 'answer' => 'Yes. It is built for ads, explainers, social campaigns, and landing page media.'],
                        ['question' => 'Can this connect with the rest of the product flow?', 'answer' => 'Yes. It fits naturally with the demo path, pricing journey, dashboard, and browser extension experience.'],
                    ],
                    'cta_title' => 'Generate your next campaign voiceover faster',
                    'cta_body' => 'Start with Listen and produce premium AI voice content without slowing your release cycle.',
                    'cta_primary' => 'Try Listen now',
                ],
                'ar' => [
                    'eyebrow' => 'مولد الأصوات بالذكاء الاصطناعي',
                    'h1' => 'مولد أصوات بالذكاء الاصطناعي للشروحات والحملات وتجارب المنتج',
                    'intro' => 'أنشئ تعليقات صوتية طبيعية للإطلاقات والمحتوى التسويقي وتجارب العملاء عبر سير عمل سريع يناسب الفرق ذات الإنتاج المرتفع.',
                    'highlights_title' => 'ما الذي يميز هذا المولد',
                    'highlights_intro' => 'مبني على جودة ثابتة وسرعة تنفيذ وتحديثات متكررة بلا تعقيد.',
                    'highlights' => [
                        ['title' => 'نبرة متناسقة للعلامة التجارية', 'body' => 'حافظ على نفس الطابع والجودة عبر الحملات ورسائل المنتج ومحتوى التدريب والدعم.'],
                        ['title' => 'العربية والإنجليزية من منصة واحدة', 'body' => 'اخدم الجمهور المحلي والدولي بدون تقسيم مسار الإنتاج بين أدوات متعددة.'],
                        ['title' => 'دورات تعديل سريعة', 'body' => 'أعد توليد المقاطع فوراً عند تحديث النصوص أو تغيّر العروض أو تطوير الرسائل.'],
                    ],
                    'use_cases_title' => 'أين تستخدمه الفرق غالباً',
                    'use_cases_intro' => 'أداة عملية لفرق النمو والمنتج والعمليات.',
                    'use_cases' => [
                        ['title' => 'فيديوهات الشرح وإطلاق الميزات', 'body' => 'أنشئ صوتاً واضحاً لفيديوهات التعريف بالميزات وتجربة البدء داخل المنتج.'],
                        ['title' => 'محتوى الحملات التسويقية', 'body' => 'أنتج تعليقات صوتية للإعلانات المدفوعة والمحتوى الاجتماعي والنسخ متعددة اللغة.'],
                        ['title' => 'التدريب والتمكين الداخلي', 'body' => 'ولّد محتوى صوتياً لمسارات التدريب الداخلي وتأهيل فرق المبيعات والدعم.'],
                        ['title' => 'محتوى التواصل مع العملاء', 'body' => 'استخدم صوتاً احترافياً في الشروحات والتحديثات ومحتوى المساعدة لرفع جودة التجربة.'],
                    ],
                    'faq_title' => 'الأسئلة الشائعة',
                    'faqs' => [
                        ['question' => 'ما وظيفة مولد الأصوات بالذكاء الاصطناعي؟', 'answer' => 'يحوّل النصوص المكتوبة إلى صوت قابل للاستخدام في الفيديوهات والمنتجات والتدريب والمحتوى التشغيلي.'],
                        ['question' => 'لماذا أستخدم منصة إسمع بدلاً من الأدوات العامة؟', 'answer' => 'لأن منصة إسمع تركّز على جودة عربية وإنجليزية مرتفعة وسير عمل مناسب للتشغيل الحقيقي داخل الفرق.'],
                        ['question' => 'هل يصلح للمحتوى التسويقي؟', 'answer' => 'نعم، مناسب للإعلانات، وفيديوهات الشرح، والمحتوى الاجتماعي، وصفحات الهبوط.'],
                        ['question' => 'هل يتكامل مع باقي مسار المنتج؟', 'answer' => 'نعم، يرتبط بشكل طبيعي بمسار الديمو والأسعار ولوحة التحكم وصفحة إضافة المتصفح.'],
                    ],
                    'cta_title' => 'أنشئ الصوت التالي لحملتك بسرعة أكبر',
                    'cta_body' => 'ابدأ مع منصة إسمع وأنشئ مخرجات صوتية احترافية دون تعطيل سرعة التنفيذ داخل فريقك.',
                    'cta_primary' => 'ابدأ الآن مع منصة إسمع',
                ],
            ],
            'text_to_speech_arabic' => [
                'en' => [
                    'eyebrow' => 'Arabic Text to Speech',
                    'h1' => 'Arabic Text to Speech for Clear, Natural, Product-Ready Audio',
                    'intro' => 'Create Arabic audio for onboarding, education, media, and accessibility with a reliable workflow built for modern product and content teams.',
                    'highlights_title' => 'Built for Arabic-first quality',
                    'highlights_intro' => 'Deliver Arabic audio that sounds polished and ready for public use.',
                    'highlights' => [
                        ['title' => 'Natural Arabic delivery', 'body' => 'Generate Arabic output with clear pronunciation and a professional listening experience.'],
                        ['title' => 'Ready for production workflows', 'body' => 'Use one platform across product content, support assets, and customer-facing media.'],
                        ['title' => 'Fast path from text to audio', 'body' => 'Go from written Arabic to publishable voice quickly without a complex pipeline.'],
                    ],
                    'use_cases_title' => 'Common Arabic TTS use cases',
                    'use_cases_intro' => 'Useful across education, product, media, and support.',
                    'use_cases' => [
                        ['title' => 'Arabic onboarding and explainers', 'body' => 'Turn product walkthroughs and onboarding instructions into high-quality Arabic narration.'],
                        ['title' => 'Learning and training content', 'body' => 'Publish Arabic lessons, internal training modules, and educational voice content efficiently.'],
                        ['title' => 'Accessible website content', 'body' => 'Provide spoken Arabic versions of written pages to improve accessibility and engagement.'],
                        ['title' => 'Customer support playback', 'body' => 'Convert key Arabic support content into audio for easier consumption and faster resolution.'],
                    ],
                    'faq_title' => 'Frequently asked questions',
                    'faqs' => [
                        ['question' => 'Is this page focused on Arabic audio generation?', 'answer' => 'Yes. This page targets Arabic text to speech use cases where quality and production reliability matter.'],
                        ['question' => 'Can Listen also support English?', 'answer' => 'Yes. Listen supports both Arabic and English, while this page is focused on Arabic-first intent.'],
                        ['question' => 'Who benefits most from Arabic text to speech?', 'answer' => 'Product teams, educators, media creators, customer-support teams, and businesses serving Arabic-speaking audiences.'],
                        ['question' => 'Can I compare plans before starting?', 'answer' => 'Yes. You can review pricing, try the demo flow, and choose the plan that fits your usage volume.'],
                    ],
                    'cta_title' => 'Launch Arabic voice content with confidence',
                    'cta_body' => 'Use Listen to transform written Arabic into premium voice output for products, content, and customer communication.',
                    'cta_primary' => 'Start with Listen',
                ],
                'ar' => [
                    'eyebrow' => 'تحويل النص العربي إلى صوت',
                    'h1' => 'تحويل النص العربي إلى كلام بصوت طبيعي وبكل اللهجات الرئيسية',
                    'intro' => 'اكتب أو الصق أي نص عربي، فصحى أو عامية، واحصل على صوت بشري طبيعي بنطق صحيح خلال ثوانٍ. منصة إسمع مبنية للعربية أولاً، وتدعم الفصحى و'.DialectCounts::arabic().' لكنة عربية من مصر والخليج والشام والعراق والمغرب العربي.',
                    'highlights_title' => 'لماذا منصة إسمع للنص العربي؟',
                    'highlights_intro' => 'لأن العربية ليست لغة إضافية عندنا، بل اللغة الأساسية.',
                    'highlights' => [
                        ['title' => 'نطق عربي صحيح', 'body' => 'مخارج حروف واضحة، ونطق سليم للأسماء والأرقام والتواريخ في النص العربي.'],
                        ['title' => 'الفصحى وكل اللهجات الرئيسية', 'body' => 'اختر الفصحى للمحتوى الرسمي والتعليمي، أو لهجة جمهورك: الخليجية أو الشامية أو المصرية أو العراقية أو المغاربية.'],
                        ['title' => 'تحكم في الأداء والمشاعر', 'body' => 'اختر أسلوب الإلقاء: إعلاني، قصصي، تعليمي، أو حواري، مع تحديد النبرة والمشاعر.'],
                    ],
                    'use_cases_title' => 'استخدامات تحويل النص العربي إلى صوت',
                    'use_cases_intro' => 'من المحتوى التعليمي إلى الإعلانات.',
                    'use_cases' => [
                        ['title' => 'التعليق الصوتي للفيديو', 'body' => 'تعليق صوتي عربي لفيديوهات يوتيوب وتيك توك وإنستغرام والإعلانات.'],
                        ['title' => 'الكتب الصوتية والمقالات', 'body' => 'حوّل الكتب والمقالات والقصص العربية إلى محتوى مسموع.'],
                        ['title' => 'التعليم والدورات', 'body' => 'دروس مسموعة بالفصحى أو باللهجة القريبة من الطلاب.'],
                        ['title' => 'إمكانية الوصول', 'body' => 'قراءة صفحات الويب والنصوص بصوت عالٍ لضعاف البصر ومن يفضّلون الاستماع، عبر إضافة المتصفح.'],
                    ],
                    'faq_title' => 'الأسئلة الشائعة',
                    'faqs' => [
                        ['question' => 'كيف أحول نص عربي إلى صوت؟', 'answer' => 'أنشئ حساباً، الصق النص، اختر الصوت واللهجة، ثم اضغط توليد وحمّل الملف بصيغة MP3.'],
                        ['question' => 'هل يدعم التشكيل والنص غير المشكول؟', 'answer' => 'نعم، يقرأ النص المشكول وغير المشكول. إضافة التشكيل على الكلمات الملتبسة تساعد على نطق أدق.'],
                        ['question' => 'هل يمكن تحويل النص إلى صوت باللهجة السعودية أو المصرية أو المغربية؟', 'answer' => 'نعم، اختر اللهجة من الاستوديو: لهجات الخليج (السعودية بلكناتها، الإماراتية، الكويتية، القطرية، البحرينية، العُمانية)، والشام، ومصر والسودان، والعراق واليمن، والمغرب العربي.'],
                        ['question' => 'هل يمكن التجربة قبل الاشتراك؟', 'answer' => 'نعم، يحصل كل حساب جديد على رصيد صغير لتجربة الأصوات في العرض التجريبي، ثم تختار الباقة التي تناسب عدد الأحرف الذي تحتاجه.'],
                    ],
                    'cta_title' => 'حوّل نصك العربي إلى صوت الآن',
                    'cta_body' => 'أنشئ حسابك واسمع نصك بصوت عربي طبيعي خلال ثوانٍ.',
                    'cta_primary' => 'أنشئ حسابك',
                ],
            ],
            'text_to_speech' => [
                'en' => [
                    'eyebrow' => 'Text to Speech Online',
                    'h1' => 'Text to Speech Online with Natural Arabic and English Voices',
                    'intro' => 'Paste any text, choose a voice, and get natural-sounding speech in seconds. Listen reads English and Arabic, including Fusha and '.DialectCounts::arabic().'+ Arabic accents and more than '.DialectCounts::english().' English accents, and gives you an MP3 you can download and use.',
                    'highlights_title' => 'Why use Listen for text to speech',
                    'highlights_intro' => 'Human-sounding voices without the robotic tone.',
                    'highlights' => [
                        ['title' => 'Natural HD voices', 'body' => 'Choose Standard voices for everyday reading or HD voices with emotion and performance styles for professional voiceovers.'],
                        ['title' => 'English and Arabic, every major dialect', 'body' => 'More than '.DialectCounts::english().' English accents including American, British and Australian, plus Modern Standard Arabic and Gulf, Levantine, Egyptian, Iraqi and Maghrebi dialects.'],
                        ['title' => 'Translate, then speak', 'body' => 'Write in one language and get audio in the other with built-in translation.'],
                    ],
                    'use_cases_title' => 'What people use it for',
                    'use_cases_intro' => 'From quick listening to finished voiceovers.',
                    'use_cases' => [
                        ['title' => 'Video voiceovers', 'body' => 'Narration for YouTube, TikTok, Reels, ads and product videos.'],
                        ['title' => 'Listening instead of reading', 'body' => 'Have articles, documents and web pages read aloud, including with the Chrome extension.'],
                        ['title' => 'E-learning and training', 'body' => 'Turn lessons and training scripts into clear spoken audio.'],
                        ['title' => 'Apps and customer service', 'body' => 'Voice prompts, IVR menus and announcements in English and Arabic.'],
                    ],
                    'faq_title' => 'Frequently asked questions',
                    'faqs' => [
                        ['question' => 'Can I try Listen before I pay?', 'answer' => 'Yes. Every new account gets a small allowance to try the voices in the demo. Plans add monthly characters, premium voices, dialects, accents and emotions.'],
                        ['question' => 'How do I convert text to speech?', 'answer' => 'Sign up, paste your text, pick a language, voice and dialect, then generate and download the MP3.'],
                        ['question' => 'Which languages are supported?', 'answer' => 'Arabic, including Modern Standard Arabic and dialects from Egypt, the Gulf, the Levant, Iraq, Yemen, Sudan and the Maghreb, and English in more than '.DialectCounts::english().' accents.'],
                        ['question' => 'Can I read web pages aloud?', 'answer' => 'Yes. The Listen Reader Chrome extension reads selected text on any page.'],
                    ],
                    'cta_title' => 'Turn your text into speech now',
                    'cta_body' => 'Create your account and hear your first audio in seconds.',
                    'cta_primary' => 'Create your account',
                ],
                'ar' => [
                    'eyebrow' => 'تحويل النص إلى كلام',
                    'h1' => 'تحويل النص إلى كلام أونلاين بصوت عربي وإنجليزي طبيعي',
                    'intro' => 'الصق أي نص، اختر الصوت، واحصل على كلام طبيعي خلال ثوانٍ. منصة إسمع تقرأ العربية والإنجليزية، بالفصحى وبـ'.DialectCounts::arabic().' لكنة عربية وأكثر من '.DialectCounts::english().' لكنة إنجليزية، وتعطيك ملف MP3 جاهزاً للتحميل والاستخدام.',
                    'highlights_title' => 'لماذا منصة إسمع لتحويل الكتابة إلى صوت',
                    'highlights_intro' => 'أصوات تشبه البشر، بلا نبرة آلية.',
                    'highlights' => [
                        ['title' => 'أصوات طبيعية عالية الدقة', 'body' => 'أصوات قياسية للقراءة اليومية، وأصوات عالية الدقة بالمشاعر وأساليب الأداء للتعليق الصوتي الاحترافي.'],
                        ['title' => 'العربية بكل لهجاتها الرئيسية والإنجليزية', 'body' => 'الفصحى، ولهجات الخليج والشام ومصر والعراق والمغرب العربي، وأكثر من '.DialectCounts::english().' لكنة إنجليزية منها الأمريكية والبريطانية والأسترالية.'],
                        ['title' => 'ترجم ثم حوّل إلى صوت', 'body' => 'اكتب بلغة واحصل على الصوت باللغة الأخرى عبر الترجمة المدمجة.'],
                    ],
                    'use_cases_title' => 'فيم يُستخدم',
                    'use_cases_intro' => 'من الاستماع السريع إلى التعليق الصوتي الجاهز للنشر.',
                    'use_cases' => [
                        ['title' => 'التعليق الصوتي للفيديو', 'body' => 'تعليق صوتي لفيديوهات يوتيوب وتيك توك والريلز والإعلانات وفيديوهات المنتجات.'],
                        ['title' => 'الاستماع بدل القراءة', 'body' => 'استمع إلى المقالات والمستندات وصفحات الويب، مع إضافة كروم للقراءة بصوت عالٍ.'],
                        ['title' => 'التعليم والتدريب', 'body' => 'حوّل الدروس ونصوص التدريب إلى صوت واضح.'],
                        ['title' => 'التطبيقات وخدمة العملاء', 'body' => 'رسائل صوتية وقوائم رد آلي وإعلانات بالعربية والإنجليزية.'],
                    ],
                    'faq_title' => 'الأسئلة الشائعة',
                    'faqs' => [
                        ['question' => 'هل يمكن تجربة منصة إسمع قبل الدفع؟', 'answer' => 'نعم، يحصل كل حساب جديد على رصيد صغير لتجربة الأصوات في العرض التجريبي. الباقات تضيف أحرفاً شهرية وأصواتاً مميزة ولهجات ولكنات ومشاعر.'],
                        ['question' => 'كيف أحول الكتابة إلى صوت؟', 'answer' => 'سجّل حساباً، الصق النص، اختر اللغة والصوت واللهجة، ثم اضغط توليد وحمّل ملف MP3.'],
                        ['question' => 'ما اللغات المدعومة؟', 'answer' => 'العربية بالفصحى ولهجات مصر والخليج والشام والعراق واليمن والسودان والمغرب العربي، والإنجليزية بأكثر من '.DialectCounts::english().' لكنة.'],
                        ['question' => 'هل يمكن قراءة صفحات الويب بصوت عالٍ؟', 'answer' => 'نعم، إضافة إسمع لمتصفح كروم تقرأ أي نص تحدده على أي صفحة.'],
                    ],
                    'cta_title' => 'حوّل نصك إلى كلام الآن',
                    'cta_body' => 'أنشئ حسابك واسمع أول مقطع صوتي خلال ثوانٍ.',
                    'cta_primary' => 'أنشئ حسابك',
                ],
            ],
        ];
    }

    /**
     * Build default sitemap entries.
     *
     * @return array<int, array{path:string,priority:float,change_frequency:string}>
     */
    public static function defaultSitemapEntries(): array
    {
        return [];
    }

    /**
     * Default sitemap text shown in dashboard.
     */
    public static function defaultSitemapEntriesText(): string
    {
        return implode("\n", array_map(
            fn (array $entry) => "{$entry['path']}|{$entry['priority']}|{$entry['change_frequency']}",
            self::defaultSitemapEntries()
        ));
    }

    public static function defaultSitemapExcludedPathsText(): string
    {
        return '';
    }

    public static function includeNonIndexablePagesInSitemap(): bool
    {
        return (string) SiteSetting::get('sitemap_include_non_indexable', '1') === '1';
    }

    /**
     * Parse sitemap entries from dashboard setting.
     *
     * Each line format: /path|priority|change_frequency
     *
     * @return array<int, array{path:string,priority:float,change_frequency:string}>
     */
    public static function sitemapEntries(): array
    {
        $raw = trim((string) SiteSetting::get('sitemap_paths', ''));
        if ($raw === '') {
            return [];
        }

        $entries = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $path = (string) ($parts[0] ?? '');
            if ($path === '') {
                continue;
            }

            $priority = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 0.5;
            $priority = max(0.0, min(1.0, $priority));

            $frequency = strtolower((string) ($parts[2] ?? 'weekly'));
            if (!in_array($frequency, self::ALLOWED_CHANGE_FREQUENCIES, true)) {
                $frequency = 'weekly';
            }

            if (!Str::startsWith($path, ['http://', 'https://'])) {
                $path = '/' . ltrim($path, '/');
            }

            if (! self::isSitemapEntryAllowed($path)) {
                continue;
            }

            $entries[] = [
                'path' => $path,
                'priority' => $priority,
                'change_frequency' => $frequency,
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, array{page:string,locale:string,url:string,priority:float,change_frequency:string,lastmod:Carbon,alternates:array<string,string>}>
     */
    public static function publicSitemapEntries(): array
    {
        $entries = [];
        $seenUrls = [];
        $append = static function (array $entry) use (&$entries, &$seenUrls): void {
            $url = trim((string) ($entry['url'] ?? ''));
            if ($url === '') {
                return;
            }

            $key = self::sitemapUrlKey($url);
            if (isset($seenUrls[$key])) {
                return;
            }

            $seenUrls[$key] = true;
            $entries[] = $entry;
        };

        $includeNonIndexable = self::includeNonIndexablePagesInSitemap();

        foreach (self::pageDefinitions() as $page => $definition) {
            if (! $includeNonIndexable && ! ($definition['indexable'] ?? false)) {
                continue;
            }

            // A URL marked noindex in the sitemap is a contradiction Google reports as an error.
            if (str_contains(strtolower(self::robots($page)), 'noindex')) {
                continue;
            }

            foreach (self::pageLocales($page) as $locale) {
                $append([
                    'page' => $page,
                    'locale' => $locale,
                    'url' => self::pageUrl($page, $locale),
                    'priority' => self::sitemapPriorityForPage($page, $definition),
                    'change_frequency' => self::sitemapChangeFrequencyForPage($page, $definition),
                    'lastmod' => self::pageLastModified($page),
                    'alternates' => self::hreflangMap($page),
                ]);
            }
        }

        foreach (self::sitemapEntries() as $entry) {
            if (self::isNoindexPath($entry['path'])) {
                continue;
            }

            $append([
                'page' => '',
                'locale' => '',
                'url' => Str::startsWith($entry['path'], ['http://', 'https://']) ? $entry['path'] : self::canonicalUrl($entry['path']),
                'priority' => (float) $entry['priority'],
                'change_frequency' => (string) $entry['change_frequency'],
                'lastmod' => self::siteLastModified(),
                'alternates' => [],
            ]);
        }

        return $entries;
    }

    /**
     * Whether a sitemap path points at a page that is marked noindex.
     */
    private static function isNoindexPath(string $path): bool
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            $canonicalHost = strtolower((string) parse_url(rtrim((string) SiteSetting::get('canonical_url', config('app.url')), '/'), PHP_URL_HOST));
            if (strtolower((string) parse_url($path, PHP_URL_HOST)) !== $canonicalHost) {
                return false;
            }
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $stripped = rtrim(self::stripLocaleFromPath($path), '/') ?: '/';
        foreach (self::pageDefinitions() as $page => $definition) {
            if (($definition['path'] ?? null) === $stripped) {
                return str_contains(strtolower(self::robots($page)), 'noindex');
            }
        }

        return false;
    }

    /**
     * Default robots.txt.
     */
    public static function defaultRobotsTxt(): string
    {
        return implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /livewire/',
            '',
            'Sitemap: ' . url('/sitemap.xml'),
        ]);
    }

    /**
     * Default llms.txt content.
     */
    public static function defaultLlmsTxt(): string
    {
        $base = rtrim((string) SiteSetting::get('canonical_url', config('app.url')), '/');
        $siteName = self::siteName('en');

        $lines = [
            "# {$siteName}",
            '',
            '> Listen (Arabic: منصة إسمع) is an AI text to speech platform for Arabic and English at ' . $base . '. It speaks Modern Standard Arabic (Fusha), '
                . DialectCounts::spokenArabic() . ' Arabic dialect accents from ' . (count(ArabicDialects::COUNTRIES) - 1) . ' countries and ' . count(EnglishAccents::ALL) . ' English accents, with emotion and performance styles, sound environments, translation and a Chrome extension.',
            '',
            '## Official URLs',
            "- [Home (English)]({$base}/en): overview, voice samples",
            "- [Home (Arabic)]({$base}/ar)",
            "- [Text to speech online]({$base}/en/text-to-speech): online text to speech in Arabic and English",
            "- [Arabic text to speech]({$base}/en/text-to-speech-arabic) · [Arabic version]({$base}/ar/text-to-speech-arabic)",
            "- [AI text to speech]({$base}/en/ai-text-to-speech)",
            "- [AI voice generator]({$base}/en/ai-voice-generator)",
            "- [Voice samples]({$base}/en/voice-samples): samples of English accents, moods, conversations and sound environments, ready to play; the Arabic page ({$base}/ar/voice-samples) has samples of Arabic dialects",
            "- [Pricing]({$base}/en/pricing): monthly plans; new accounts can try the voices in the demo first",
            "- [Chrome extension]({$base}/en/browsers-extension): reads selected text on any web page",
            "- [Contact]({$base}/en/contact-us)",
            '',
            '## Arabic dialects',
            "- [All Arabic dialects]({$base}/en/arabic-dialects) · [Arabic version]({$base}/ar/arabic-dialects)",
        ];

        foreach (SeoDialectPages::PAGES as $slug => $row) {
            $lines[] = "- [{$row['en']}" . (SeoDialectPages::isFusha($slug) ? '' : ' Arabic') . " text to speech]({$base}/en/arabic-dialects/{$slug})";
        }

        $lines[] = '';
        $lines[] = '## English accents';
        $lines[] = "- [All English accents]({$base}/en/english-accents) · [Arabic version]({$base}/ar/english-accents)";
        foreach (SeoEnglishPages::PAGES as $slug => $row) {
            $lines[] = '- [' . SeoEnglishPages::title($slug, 'en') . "]({$base}/en/english-accents/{$slug})";
        }

        return implode("\n", array_merge($lines, [
            '',
            '## Notes for AI agents',
            '- Prefer canonical URLs from this domain.',
            '- English pages live under /en and Arabic pages under /ar; they are language alternates, not duplicates.',
            "- Sitemap: {$base}/sitemap.xml",
        ]));
    }

    /**
     * Whether llms.txt endpoint is enabled.
     */
    public static function llmsTxtEnabled(): bool
    {
        return SiteSetting::get('enable_llms_txt', '1') === '1';
    }

    /**
     * Get llms.txt content from settings or fallback.
     */
    public static function llmsTxtContent(): string
    {
        $content = trim((string) SiteSetting::get('llms_txt', ''));
        if ($content === '') {
            return self::defaultLlmsTxt();
        }

        return $content;
    }

    /**
     * Get the full meta title for a page.
     * If a per-page title override exists in the DB, it takes precedence.
     */
    public static function title(string $pageTitle = '', string $page = '', ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $template = self::localizedTitleTemplate($locale);

        if ($page !== '') {
            $override = self::localizedOverride("seo_{$page}_title", $locale, $locale === 'ar');
            if ($override !== '') {
                $pageTitle = $override;
            }
        }

        $pageTitle = trim($pageTitle);
        if ($pageTitle === '') {
            $pageTitle = self::localizedDefault('title', $page, $locale);
        }

        if ($pageTitle === '') {
            $pageTitle = self::siteName($locale);
        }

        return str_replace(
            ['%page_title%', '%brand_name%'],
            [$pageTitle, self::localizedBrandName($locale)],
            $template
        );
    }

    private static function localizedTitleTemplate(string $locale): string
    {
        $template = self::localizedOverride('meta_title_template', $locale, $locale === 'ar');
        if ($template === '') {
            $template = '%page_title% - %brand_name%';
        } elseif ($locale === 'ar' && in_array(trim($template), ['%page_title% - استمع', '%page_title% - إسمع', '%page_title% - Listen'], true)) {
            $template = '%page_title% - منصة إسمع';
        }

        return (string) preg_replace('/\s*\|\s*/u', ' - ', $template);
    }

    private static function localizedBrandName(string $locale): string
    {
        return self::siteName($locale);
    }

    private static function localizedDefault(string $field, string $page, string $locale): string
    {
        $definition = self::pageDefinitions()[$page] ?? null;
        if (! is_array($definition)) {
            return '';
        }

        $dialectSlug = SeoDialectPages::slugForPageKey($page);
        if ($dialectSlug !== null && in_array($field, ['title', 'description'], true)) {
            return $field === 'title'
                ? SeoDialectPages::title($dialectSlug, $locale)
                : SeoDialectPages::description($dialectSlug, $locale);
        }

        $accentSlug = SeoEnglishPages::slugForPageKey($page);
        if ($accentSlug !== null && in_array($field, ['title', 'description'], true)) {
            return $field === 'title'
                ? SeoEnglishPages::title($accentSlug, $locale)
                : SeoEnglishPages::description($accentSlug, $locale);
        }

        $translationKey = (string) ($definition["{$field}_key"] ?? '');
        if ($translationKey !== '') {
            $translated = trim((string) __($translationKey, [], $locale));
            if ($translated !== '' && $translated !== $translationKey) {
                return $translated;
            }
        }

        return trim((string) ($definition[$field] ?? ''));
    }

    private static function containsArabic(string $value): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $value);
    }

    /**
     * Get meta description - page-specific override or global default.
     */
    public static function description(string $page = '', ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);

        if ($page !== '') {
            $override = self::localizedOverride("seo_{$page}_description", $locale, $locale === 'ar');
            if ($override !== '') {
                return $override;
            }
        }

        // A page's own description comes before the site-wide one, so pages
        // without an override still get distinct snippets in search results.
        $fallback = self::localizedDefault('description', $page, $locale);
        if ($fallback !== '' && ($locale !== 'ar' || self::containsArabic($fallback))) {
            return $fallback;
        }

        $global = self::localizedOverride('meta_description', $locale, $locale === 'ar');
        if ($global !== '') {
            return $global;
        }

        return self::siteDescription($locale);
    }

    /**
     * Get meta keywords.
     */
    public static function keywords(string $page = '', ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);

        if ($page !== '') {
            $override = self::localizedOverride("seo_{$page}_keywords", $locale);
            if ($override !== '') {
                return $override;
            }
        }

        return self::localizedOverride('meta_keywords', $locale);
    }

    /**
     * Get robots directive for page.
     */
    public static function robots(string $page = ''): string
    {
        if ($page) {
            $definition = self::pageDefinitions()[$page] ?? null;

            // Sign-in, account and app pages stay out of search results whatever
            // the dashboard says: an "index" override there only adds thin
            // pages that compete with the real landing pages.
            if (is_array($definition) && ($definition['indexable'] ?? true) === false) {
                return (string) ($definition['robots'] ?? 'noindex,nofollow');
            }

            $override = trim((string) SiteSetting::get("seo_{$page}_robots", ''));
            if ($override !== '') {
                return $override;
            }

            $fallback = self::pageDefinitions()[$page]['robots'] ?? '';
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return (string) SiteSetting::get('meta_robots', self::DEFAULT_META_ROBOTS);
    }

    /**
     * Get meta author value.
     */
    public static function author(): string
    {
        return trim((string) SiteSetting::get('meta_author', ''));
    }

    /**
     * Get page-specific title override or return empty string.
     */
    public static function pageTitle(string $page, ?string $locale = null): string
    {
        return self::localizedOverride("seo_{$page}_title", self::normalizeLocale($locale));
    }

    /**
     * Get canonical URL.
     */
    public static function canonicalUrl(string $path = '', ?string $locale = null): string
    {
        $base = rtrim((string) SiteSetting::get('canonical_url', config('app.url')), '/');
        $normalizedPath = self::normalizePath($path, $locale);

        return $normalizedPath === '/' ? $base . $normalizedPath : $base . rtrim($normalizedPath, '/');
    }

    public static function currentCanonicalUrl(?string $locale = null): string
    {
        return self::canonicalUrl(self::currentLocalizedPath($locale), $locale);
    }

    public static function pageUrl(string $page, ?string $locale = null): string
    {
        $definition = self::pageDefinitions()[$page] ?? null;
        if (! is_array($definition)) {
            return '';
        }

        return self::canonicalUrl((string) ($definition['path'] ?? '/'), $locale);
    }

    public static function hreflangMap(string $page = ''): array
    {
        $page = $page !== '' ? $page : self::resolveCurrentPageKey();
        if ($page === '') {
            return [];
        }

        $links = [];
        foreach (self::pageLocales($page) as $locale) {
            $links[$locale] = self::pageUrl($page, $locale);
        }

        if (isset($links['en'])) {
            $links['x-default'] = $links['en'];
        }

        return $links;
    }

    public static function hreflangTags(string $page = ''): string
    {
        $tags = [];

        foreach (self::hreflangMap($page) as $locale => $url) {
            $tags[] = '<link rel="alternate" hreflang="' . e($locale) . '" href="' . e($url) . '">';
        }

        return implode("\n", $tags);
    }

    /**
     * Generate Open Graph meta tags HTML.
     */
    public static function ogTags(string $pageTitle = '', string $page = ''): string
    {
        $locale = self::currentLocale();
        $title = self::title($pageTitle, $page, $locale);
        $description = self::description($page, $locale);
        $image = self::shareImageUrl($locale);
        $siteName = self::siteName($locale);
        $type = (string) SiteSetting::get('og_type', 'website');
        $tags = [
            '<meta property="og:type" content="' . e($type) . '">',
            '<meta property="og:site_name" content="' . e($siteName) . '">',
            '<meta property="og:locale" content="' . e(self::ogLocale($locale)) . '">',
            '<meta property="og:title" content="' . e($title) . '">',
            '<meta property="og:description" content="' . e($description) . '">',
            '<meta property="og:url" content="' . e(self::currentCanonicalUrl($locale)) . '">',
        ];

        foreach (self::supportedLocales() as $alternateLocale) {
            if ($alternateLocale === $locale) {
                continue;
            }

            $tags[] = '<meta property="og:locale:alternate" content="' . e(self::ogLocale($alternateLocale)) . '">';
        }

        if ($image !== '') {
            $tags[] = '<meta property="og:image" content="' . e($image) . '">';
            $tags[] = '<meta property="og:image:alt" content="' . e($title) . '">';
            if (self::usesDefaultShareImage()) {
                $tags[] = '<meta property="og:image:width" content="1200">';
                $tags[] = '<meta property="og:image:height" content="630">';
            }
        }

        return implode("\n", $tags);
    }

    /**
     * Generate Twitter Card meta tags HTML.
     */
    public static function twitterCard(string $pageTitle = '', string $page = ''): string
    {
        $locale = self::currentLocale();
        $cardType = (string) SiteSetting::get('twitter_card_type', 'summary_large_image');
        $title = self::title($pageTitle, $page, $locale);
        $description = self::description($page, $locale);
        $handle = trim((string) SiteSetting::get('twitter_handle', ''));
        $creator = trim((string) SiteSetting::get('twitter_creator', ''));
        $image = self::shareImageUrl($locale);
        $tags = [
            '<meta name="twitter:card" content="' . e($cardType) . '">',
            '<meta name="twitter:title" content="' . e($title) . '">',
            '<meta name="twitter:description" content="' . e($description) . '">',
            '<meta name="twitter:url" content="' . e(self::currentCanonicalUrl($locale)) . '">',
        ];

        if ($handle !== '') {
            $tags[] = '<meta name="twitter:site" content="@' . e(ltrim($handle, '@')) . '">';
        }

        if ($creator !== '') {
            $tags[] = '<meta name="twitter:creator" content="@' . e(ltrim($creator, '@')) . '">';
        }

        if ($image !== '') {
            $tags[] = '<meta name="twitter:image" content="' . e($image) . '">';
        }

        return implode("\n", $tags);
    }

    /**
     * Generate JSON-LD structured data.
     */
    public static function structuredData(array $faqItems = [], string $pageTitle = '', string $page = ''): string
    {
        if (SiteSetting::get('enable_structured_data', '1') !== '1') {
            return '';
        }

        $page = $page !== '' ? $page : self::resolveCurrentPageKey();
        $locale = self::currentLocale();
        $language = self::schemaLanguage($locale);
        $siteName = self::siteName($locale);
        $description = self::description($page, $locale);
        $url = self::currentCanonicalUrl($locale);
        $homeUrl = self::canonicalUrl('/' . self::defaultHreflangLocale(), self::defaultHreflangLocale());
        $logo = self::normalizeMediaUrl((string) SiteSetting::get('logo_url', '')) ?: self::publicAssetUrl('images/email-logo-en.png');
        $organizationId = $homeUrl . '#organization';
        $websiteId = $homeUrl . '#website';
        $appId = $homeUrl . '#app';
        $names = array_values(array_unique(array_filter([self::siteName('en'), 'Listen', 'Listen AI', 'إسمع', 'منصة إسمع'])));
        $alternateNames = array_values(array_filter($names, static fn (string $name): bool => $name !== $siteName));

        $graph = [
            array_filter([
                '@type' => 'Organization',
                '@id' => $organizationId,
                'name' => $siteName,
                'alternateName' => $alternateNames,
                'url' => $homeUrl,
                'description' => self::siteDescription($locale),
                'logo' => $logo !== '' ? $logo : null,
                'sameAs' => self::socialProfileUrls() ?: null,
            ], static fn ($value) => $value !== null && $value !== '' && $value !== []),
            [
                '@type' => 'WebSite',
                '@id' => $websiteId,
                'name' => $siteName,
                'alternateName' => $alternateNames,
                'url' => $homeUrl,
                'description' => self::siteDescription($locale),
                'inLanguage' => ['en', 'ar'],
                'publisher' => ['@id' => $organizationId],
            ],
        ];

        $webPage = [
            '@type' => 'WebPage',
            '@id' => $url . '#webpage',
            'name' => self::title($pageTitle, $page, $locale),
            'url' => $url,
            'description' => $description,
            'inLanguage' => $language,
            'isPartOf' => ['@id' => $websiteId],
        ];

        $definition = self::pageDefinitions()[$page] ?? [];
        $isPublicProductPage = ($definition['indexable'] ?? false) && ! in_array($page, ['privacy', 'terms', 'cookies', 'contact'], true);
        if ($isPublicProductPage) {
            $webPage['about'] = ['@id' => $appId];
            $graph[] = self::softwareApplicationNode($appId, $organizationId, $homeUrl, $locale);
        }
        $graph[] = $webPage;

        $breadcrumbs = self::breadcrumbs($page, $locale);
        if (count($breadcrumbs) > 1) {
            $items = [];
            foreach ($breadcrumbs as $index => $crumb) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'],
                ];
            }
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }

        if ($faqItems !== []) {
            $questions = [];
            foreach ($faqItems as $item) {
                $question = trim((string) ($item['question'] ?? ''));
                $answer = trim((string) ($item['answer'] ?? ''));
                if ($question === '' || $answer === '') {
                    continue;
                }

                $questions[] = [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $answer,
                    ],
                ];
            }

            if ($questions !== []) {
                $graph[] = [
                    '@type' => 'FAQPage',
                    'mainEntity' => $questions,
                    'inLanguage' => $language,
                ];
            }
        }

        return '<script type="application/ld+json">' . json_encode([
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    }

    /**
     * Breadcrumb trail (home first) for a page in the given locale.
     *
     * @return array<int, array{name: string, url: string}>
     */
    public static function breadcrumbs(string $page, ?string $locale = null): array
    {
        $locale = self::normalizeLocale($locale);
        $definition = self::pageDefinitions()[$page] ?? null;
        if ($page === '' || $page === 'home' || ! is_array($definition) || ! ($definition['indexable'] ?? false)) {
            return [];
        }

        $trail = [['name' => $locale === 'ar' ? 'الرئيسية' : 'Home', 'url' => self::pageUrl('home', $locale)]];
        if (SeoDialectPages::slugForPageKey($page) !== null) {
            $trail[] = [
                'name' => $locale === 'ar' ? 'اللهجات العربية' : 'Arabic dialects',
                'url' => self::pageUrl(SeoDialectPages::HUB_PAGE, $locale),
            ];
        }
        if (SeoEnglishPages::slugForPageKey($page) !== null) {
            $trail[] = [
                'name' => $locale === 'ar' ? 'اللكنات الإنجليزية' : 'English accents',
                'url' => self::pageUrl(SeoEnglishPages::HUB_PAGE, $locale),
            ];
        }

        $name = self::localizedOverride("seo_{$page}_title", $locale, $locale === 'ar') ?: self::localizedDefault('title', $page, $locale);
        $trail[] = ['name' => $name, 'url' => self::pageUrl($page, $locale)];

        return $trail;
    }

    /**
     * @return array<string, mixed>
     */
    private static function softwareApplicationNode(string $appId, string $organizationId, string $homeUrl, string $locale): array
    {
        $node = [
            '@type' => 'SoftwareApplication',
            '@id' => $appId,
            'name' => self::siteName($locale),
            'url' => $homeUrl,
            'applicationCategory' => 'MultimediaApplication',
            'applicationSubCategory' => 'Text to speech',
            'operatingSystem' => 'Web, Chrome',
            'description' => self::siteDescription($locale),
            'inLanguage' => ['ar', 'en'],
            'featureList' => $locale === 'ar'
                ? ['تحويل النص إلى صوت بالعربية والإنجليزية', 'العربية الفصحى و'.DialectCounts::arabic().' لكنة عربية', 'أكثر من '.DialectCounts::english().' لكنة إنجليزية', 'أصوات عالية الدقة مع التحكم في المشاعر والأداء', 'بيئات صوتية مثل القاعة والهاتف والراديو', 'ترجمة قبل التحويل إلى صوت', 'إضافة لمتصفح كروم']
                : ['Arabic and English text to speech', 'Modern Standard Arabic and '.DialectCounts::arabic().'+ Arabic accents', ''.DialectCounts::english().'+ English accents', 'HD voices with emotion and performance control', 'Sound environments such as hall, phone and radio', 'Translate before speech', 'Chrome extension'],
            'publisher' => ['@id' => $organizationId],
        ];

        $offers = self::planOffers();
        if ($offers !== null) {
            $node['offers'] = $offers;
        }

        $image = self::shareImageUrl($locale);
        if ($image !== '') {
            $node['image'] = $image;
        }

        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function planOffers(): ?array
    {
        try {
            // Paid plans only: a $0 plan is the demo account, not an offer.
            $prices = \App\Models\Plan::query()->where('is_active', true)->where('price_cents', '>', 0)->pluck('price_cents')->map(fn ($cents) => (int) $cents)->all();
        } catch (\Throwable) {
            return null;
        }

        if ($prices === []) {
            return null;
        }

        return [
            '@type' => 'AggregateOffer',
            'priceCurrency' => strtoupper((string) config('cashier.currency', 'usd')),
            'lowPrice' => number_format(min($prices) / 100, 2, '.', ''),
            'highPrice' => number_format(max($prices) / 100, 2, '.', ''),
            'offerCount' => count($prices),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function socialProfileUrls(): array
    {
        $urls = [];
        foreach (['twitter', 'github', 'linkedin', 'facebook', 'instagram', 'youtube', 'tiktok'] as $network) {
            $value = trim((string) SiteSetting::get("footer_social_{$network}_url", ''));
            if ($value !== '' && Str::startsWith($value, ['http://', 'https://'])) {
                $urls[] = $value;
            }
        }

        return $urls;
    }

    /**
     * The image shown when a page is shared: the one set in the dashboard, or
     * the bundled 1200x630 image in the page's language.
     */
    public static function shareImageUrl(?string $locale = null): string
    {
        $configured = self::normalizeMediaUrl((string) SiteSetting::get('og_image_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        $locale = self::normalizeLocale($locale);

        return self::versionedAssetUrl(self::publicAssetUrl("images/og/listen-og-{$locale}.png"));
    }

    private static function usesDefaultShareImage(): bool
    {
        return self::normalizeMediaUrl((string) SiteSetting::get('og_image_url', '')) === '';
    }

    public static function verificationTags(): string
    {
        $map = [
            'google_site_verification' => 'google-site-verification',
            'bing_site_verification' => 'msvalidate.01',
            'yandex_site_verification' => 'yandex-verification',
            'baidu_site_verification' => 'baidu-site-verification',
            'naver_site_verification' => 'naver-site-verification',
        ];

        $tags = [];
        foreach ($map as $key => $metaName) {
            $value = trim((string) SiteSetting::get($key, ''));
            if ($value === '') {
                continue;
            }

            $tags[] = '<meta name="' . e($metaName) . '" content="' . e($value) . '">';
        }

        return implode("\n", $tags);
    }

    public static function llmsDiscoveryTag(): string
    {
        if (! self::llmsTxtEnabled()) {
            return '';
        }

        return '<link rel="alternate" type="text/plain" title="LLMs" href="' . e(url('/llms.txt')) . '">';
    }

    public static function faviconMetaTags(): string
    {
        $svg = self::versionedAssetUrl(self::svgFaviconUrl());
        $icon = self::versionedAssetUrl(self::primaryFaviconUrl());
        $appleTouch = self::versionedAssetUrl(
            self::normalizeMediaUrl((string) SiteSetting::get('seo_apple_touch_icon_url', ''))
            ?: self::publicAssetUrl('apple-touch-icon.png')
            ?: $icon
            ?: $svg
        );
        $manifest = trim((string) SiteSetting::get('seo_manifest_url', ''));
        $manifest = $manifest !== '' ? self::normalizeMediaUrl($manifest) : url('/site.webmanifest');
        $themeColor = trim((string) SiteSetting::get('seo_favicon_theme_color', '#0f172a'));
        $tileColor = trim((string) SiteSetting::get('seo_favicon_tile_color', '#0f172a'));

        $tags = [];
        if ($svg !== '') {
            $tags[] = '<link rel="icon" type="image/svg+xml" sizes="any" href="' . e($svg) . '">';
        }
        if ($icon !== '' && $icon !== $svg) {
            $tags[] = '<link rel="icon" href="' . e($icon) . '">';
            if (! str_ends_with(strtolower(parse_url($icon, PHP_URL_PATH) ?? ''), '.svg')) {
                $tags[] = '<link rel="shortcut icon" href="' . e($icon) . '">';
            }
        }
        if ($appleTouch !== '') {
            $tags[] = '<link rel="apple-touch-icon" href="' . e($appleTouch) . '">';
        }
        if ($manifest !== '') {
            $tags[] = '<link rel="manifest" href="' . e($manifest) . '">';
        }
        $tags[] = '<meta name="msapplication-config" content="none">';
        if ($themeColor !== '') {
            $tags[] = '<meta name="theme-color" content="' . e($themeColor) . '">';
        }
        if ($tileColor !== '') {
            $tags[] = '<meta name="msapplication-TileColor" content="' . e($tileColor) . '">';
        }

        return implode("\n", $tags);
    }

    public static function defaultTheme(): string
    {
        $theme = SiteSetting::get('default_theme', 'dark');
        return match ($theme) {
            'light' => 'light',
            'dark' => 'dark',
            default => 'dark',
        };
    }

    public static function themeClass(): string
    {
        $theme = SiteSetting::get('default_theme', 'system');
        return match ($theme) {
            'dark' => 'dark',
            'light' => '',
            default => '',
        };
    }

    public static function customCss(): string
    {
        return (string) SiteSetting::get('custom_css', '');
    }

    public static function analyticsId(): string
    {
        return trim((string) SiteSetting::get('google_analytics_id', ''));
    }

    public static function googleTagId(): string
    {
        return trim((string) SiteSetting::get('google_tag_id', ''));
    }

    public static function googleAdsConversionSendTo(): string
    {
        return trim((string) SiteSetting::get('google_ads_conversion_send_to', ''));
    }

    public static function googleAdsTrialConversionSendTo(): string
    {
        return trim((string) SiteSetting::get('google_ads_trial_conversion_send_to', ''));
    }

    public static function googleTagHeadCode(): string
    {
        return trim((string) SiteSetting::get('google_tag_head_code', ''));
    }

    public static function analyticsScript(): string
    {
        $customCode = self::googleTagHeadCode();
        if ($customCode !== '') {
            return $customCode;
        }

        $ids = array_values(array_unique(array_filter([
            self::analyticsId(),
            self::googleTagId(),
        ], static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return '';
        }

        $primaryId = $ids[0];
        $configLines = implode("\n", array_map(
            static fn (string $id): string => "  gtag('config', '{$id}');",
            $ids
        ));

        return <<<HTML
<script async src="https://www.googletagmanager.com/gtag/js?id={$primaryId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
{$configLines}
</script>
HTML;
    }

    public static function facebookPixelScript(): string
    {
        return trim((string) SiteSetting::get('facebook_pixel_code', ''));
    }

    public static function siteWebmanifest(): array
    {
        $name = self::siteName('en');
        $shortName = trim((string) SiteSetting::get('seo_manifest_short_name', $name));
        $icons = [];

        foreach (array_filter([
            self::svgFaviconUrl(),
            self::primaryFaviconUrl(),
            self::normalizeMediaUrl((string) SiteSetting::get('seo_apple_touch_icon_url', '')),
            self::publicAssetUrl('apple-touch-icon.png'),
        ]) as $icon) {
            if (isset($icons[$icon])) {
                continue;
            }

            $icons[$icon] = [
                'src' => self::versionedAssetUrl($icon),
                'sizes' => str_ends_with(strtolower(parse_url($icon, PHP_URL_PATH) ?? ''), '.svg') ? 'any' : '192x192',
                'type' => self::manifestIconType($icon),
                'purpose' => 'any',
            ];
        }

        return [
            'name' => $name,
            'short_name' => $shortName !== '' ? $shortName : $name,
            'icons' => array_values($icons),
            'theme_color' => trim((string) SiteSetting::get('seo_favicon_theme_color', '#0f172a')),
            'background_color' => trim((string) SiteSetting::get('seo_favicon_tile_color', '#0f172a')),
            'display' => 'standalone',
            'lang' => self::currentLocale(),
            'start_url' => '/' . self::defaultHreflangLocale(),
        ];
    }

    public static function primaryFaviconUrl(): string
    {
        return self::normalizeMediaUrl((string) SiteSetting::get('seo_favicon_url', ''))
            ?: self::publicAssetUrl('favicon.png')
            ?: self::publicAssetUrl('favicon-192.png')
            ?: self::svgFaviconUrl();
    }

    public static function versionedPrimaryFaviconUrl(): string
    {
        return self::versionedAssetUrl(self::primaryFaviconUrl());
    }

    public static function svgFaviconUrl(): string
    {
        return self::normalizeMediaUrl((string) SiteSetting::get('seo_favicon_svg_url', ''))
            ?: self::publicAssetUrl('favicon.svg')
            ?: self::publicAssetUrl('icons/headphones.svg');
    }

    public static function siteName(?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);

        if ($locale === 'ar') {
            $brand = trim((string) SiteSetting::get('brand_name_ar', 'منصة إسمع'));

            return in_array($brand, ['', 'استمع', 'إسمع', 'Listen'], true) ? 'منصة إسمع' : $brand;
        }

        return trim((string) SiteSetting::get('site_name', config('app.name', 'Listen')));
    }

    public static function siteDescription(?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $configured = self::localizedOverride('site_seo_description', $locale, $locale === 'ar');
        if ($configured !== '') {
            return $configured;
        }

        return $locale === 'ar'
            ? 'منصة لتحويل النص إلى صوت بأصوات طبيعية بالفصحى واللهجات العربية واللكنات الإنجليزية، للمحتوى والإعلانات والتعليم والمنتجات.'
            : 'AI text to speech with natural voices in Fusha, Arabic dialects and English accents, for content, ads, learning and products.';
    }

    public static function currentLocale(): string
    {
        return self::normalizeLocale((string) app()->getLocale());
    }

    /**
     * @return array<int, string>
     */
    public static function supportedLocales(): array
    {
        return ['en', 'ar'];
    }

    public static function defaultHreflangLocale(): string
    {
        return 'en';
    }

    /**
     * @return array<int, string>
     */
    public static function pageLocales(string $page): array
    {
        $definition = self::pageDefinitions()[$page] ?? null;
        if (! is_array($definition)) {
            return [];
        }

        if (isset($definition['allowed_locales']) && is_array($definition['allowed_locales'])) {
            return array_values(array_intersect(self::supportedLocales(), $definition['allowed_locales']));
        }

        return ($definition['localized'] ?? false) ? self::supportedLocales() : [self::defaultHreflangLocale()];
    }

    public static function currentLocalizedPath(?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $requestPath = '/' . ltrim((string) request()->path(), '/');
        if ($requestPath === '/' || $requestPath === '/index.php') {
            return '/' . $locale;
        }

        return self::normalizePath($requestPath, $locale);
    }

    public static function resolveCurrentPageKey(): string
    {
        $strippedPath = self::stripLocaleFromPath('/' . ltrim((string) request()->path(), '/'));

        foreach (self::pageDefinitions() as $page => $definition) {
            $pagePath = (string) ($definition['path'] ?? '');
            if ($pagePath === $strippedPath || ($pagePath === '/' && $strippedPath === '/')) {
                return $page;
            }
        }

        return '';
    }

    public static function pageLastModified(string $page): Carbon
    {
        $timestamps = [];

        foreach (self::pageFiles($page) as $relativePath) {
            $fullPath = base_path($relativePath);
            if (is_file($fullPath)) {
                $timestamps[] = filemtime($fullPath) ?: null;
            }
        }

        $settingsUpdatedAt = SiteSetting::query()
            ->whereIn('key', self::pageSettingKeys($page))
            ->max('updated_at');

        if ($settingsUpdatedAt !== null) {
            $timestamps[] = Carbon::parse($settingsUpdatedAt)->timestamp;
        }

        $timestamps[] = self::siteLastModified()->timestamp;
        $timestamps = array_values(array_filter($timestamps, static fn ($value) => is_int($value) && $value > 0));

        return Carbon::createFromTimestamp($timestamps !== [] ? max($timestamps) : time());
    }

    public static function siteLastModified(): Carbon
    {
        $updatedAt = SiteSetting::query()
            ->whereIn('group', ['seo', 'general'])
            ->max('updated_at');

        return $updatedAt ? Carbon::parse($updatedAt) : Carbon::now();
    }

    private static function normalizeLocale(?string $locale): string
    {
        $locale = trim((string) $locale);

        if ($locale === '') {
            $locale = (string) app()->getLocale();
        }

        $locale = strtolower(str_replace('_', '-', trim($locale)));
        $primary = trim((string) explode('-', $locale)[0]);

        if (in_array($primary, self::supportedLocales(), true)) {
            return $primary;
        }

        return self::defaultHreflangLocale();
    }

    private static function normalizePath(string $path, ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $path = trim($path);
        if ($path === '') {
            return '/' . $locale;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $path = '/' . ltrim($path, '/');
        $stripped = self::stripLocaleFromPath($path);

        return $stripped === '/' ? '/' . $locale : '/' . $locale . $stripped;
    }

    private static function isSitemapEntryAllowed(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            $entryHost = strtolower((string) parse_url($path, PHP_URL_HOST));
            $canonicalBase = rtrim((string) SiteSetting::get('canonical_url', config('app.url')), '/');
            $canonicalHost = strtolower((string) parse_url($canonicalBase, PHP_URL_HOST));

            // Only enforce private-path filtering for this primary domain.
            if ($entryHost !== '' && $canonicalHost !== '' && $entryHost === $canonicalHost) {
                $entryPath = (string) parse_url($path, PHP_URL_PATH);
                return ! self::isExcludedSitemapPath($entryPath);
            }

            return true;
        }

        return ! self::isExcludedSitemapPath($path);
    }

    private static function isExcludedSitemapPath(string $path): bool
    {
        $path = '/' . ltrim(trim($path), '/');
        $stripped = self::stripLocaleFromPath($path);

        foreach (self::sitemapExcludedPaths() as $excluded) {
            $excluded = self::stripLocaleFromPath('/' . ltrim($excluded, '/'));
            if ($excluded === '/') {
                continue;
            }

            if ($stripped === $excluded || Str::startsWith($stripped, rtrim($excluded, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private static function sitemapExcludedPaths(): array
    {
        $raw = trim((string) SiteSetting::get('sitemap_excluded_paths', self::defaultSitemapExcludedPathsText()));
        if ($raw === '') {
            return [];
        }

        $items = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $items[] = '/' . ltrim($line, '/');
        }

        return array_values(array_unique($items));
    }

    private static function sitemapUrlKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        if ($host === '') {
            return strtolower($path);
        }

        if ($scheme === '') {
            $scheme = 'https';
        }

        return $scheme . '://' . $host . $path;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function sitemapPriorityForPage(string $page, array $definition): float
    {
        $fallback = max(0.0, min(1.0, (float) ($definition['sitemap_priority'] ?? 0.5)));
        $configured = trim((string) SiteSetting::get("seo_{$page}_sitemap_priority", ''));

        if ($configured === '' || ! is_numeric($configured)) {
            return $fallback;
        }

        return max(0.0, min(1.0, (float) $configured));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function sitemapChangeFrequencyForPage(string $page, array $definition): string
    {
        $fallback = strtolower((string) ($definition['sitemap_change_frequency'] ?? 'weekly'));
        if (! in_array($fallback, self::ALLOWED_CHANGE_FREQUENCIES, true)) {
            $fallback = 'weekly';
        }

        $configured = strtolower(trim((string) SiteSetting::get("seo_{$page}_sitemap_change_frequency", '')));
        if ($configured === '' || ! in_array($configured, self::ALLOWED_CHANGE_FREQUENCIES, true)) {
            return $fallback;
        }

        return $configured;
    }

    private static function stripLocaleFromPath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($value) => $value !== ''));

        if ($segments !== [] && in_array($segments[0], self::supportedLocales(), true)) {
            array_shift($segments);
        }

        return $segments === [] ? '/' : '/' . implode('/', $segments);
    }

    private static function localizedOverride(string $baseKey, string $locale, bool $requireArabic = false): string
    {
        $keys = ["{$baseKey}_{$locale}", $baseKey];

        foreach ($keys as $key) {
            $value = trim((string) SiteSetting::get($key, ''));
            if ($value === '') {
                continue;
            }

            if ($requireArabic && $locale === 'ar' && ! self::containsArabic($value)) {
                continue;
            }

            return $value;
        }

        return '';
    }

    private static function ogLocale(string $locale): string
    {
        $configured = trim((string) SiteSetting::get("og_locale_{$locale}", ''));
        if ($configured !== '') {
            return $configured;
        }

        $supported = (array) config('laravellocalization.supportedLocales', []);
        $regional = trim((string) data_get($supported, "{$locale}.regional", ''));

        return $regional !== '' ? $regional : ($locale === 'ar' ? 'ar_AE' : 'en_GB');
    }

    private static function schemaLanguage(string $locale): string
    {
        return $locale === 'ar' ? 'ar' : 'en';
    }

    /**
     * @return array<int, string>
     */
    private static function pageSettingKeys(string $page): array
    {
        return [
            "seo_{$page}_title",
            "seo_{$page}_title_en",
            "seo_{$page}_title_ar",
            "seo_{$page}_description",
            "seo_{$page}_description_en",
            "seo_{$page}_description_ar",
            "seo_{$page}_keywords",
            "seo_{$page}_keywords_en",
            "seo_{$page}_keywords_ar",
            "seo_{$page}_robots",
            'meta_title_template',
            'meta_title_template_en',
            'meta_title_template_ar',
            'meta_description',
            'meta_description_en',
            'meta_description_ar',
            'meta_keywords',
            'meta_keywords_en',
            'meta_keywords_ar',
            'site_name',
            'brand_name_ar',
            'site_seo_description',
            'site_seo_description_en',
            'site_seo_description_ar',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function pageFiles(string $page): array
    {
        return match ($page) {
            'home' => ['resources/views/landing.blade.php', 'resources/views/components/header.blade.php', 'resources/views/components/footer.blade.php', 'resources/views/components/seo.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'pricing' => ['resources/views/pricing.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'samples' => ['resources/views/samples.blade.php', 'app/Http/Controllers/HomeController.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'contact' => ['resources/views/contact.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'extension' => ['resources/views/extension.blade.php', 'app/Http/Controllers/ExtensionController.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'developers' => ['resources/views/developers/docs.blade.php', 'lang/en/developers.php', 'lang/ar/developers.php'],
            'privacy' => ['resources/views/legal/privacy.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'terms' => ['resources/views/legal/terms.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'cookies' => ['resources/views/legal/cookies.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'ai_text_to_speech', 'ai_voice_generator', 'text_to_speech_arabic', 'text_to_speech' => ['resources/views/seo/landing-page.blade.php', 'app/Http/Controllers/HomeController.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'demo' => ['resources/views/demo.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'login' => ['resources/views/auth/login.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'register' => ['resources/views/auth/register.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'dashboard' => ['resources/views/dashboard.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'billing' => ['resources/views/billing.blade.php', 'resources/views/billing-manual.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'manage_subscription' => ['resources/views/manage-subscription.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            'upgrade' => ['resources/views/upgrade.blade.php', 'lang/en/ui.php', 'lang/ar/ui.php'],
            default => match (true) {
                SeoDialectPages::slugForPageKey($page) !== null, $page === SeoDialectPages::HUB_PAGE => ['resources/views/seo/landing-page.blade.php', 'app/Support/SeoDialectPages.php', 'app/Support/ArabicDialects.php'],
                SeoEnglishPages::slugForPageKey($page) !== null, $page === SeoEnglishPages::HUB_PAGE => ['resources/views/seo/landing-page.blade.php', 'app/Support/SeoEnglishPages.php', 'app/Support/EnglishAccents.php'],
                default => [],
            },
        };
    }

    private static function normalizeMediaUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        if (Str::startsWith($value, ['/'])) {
            return url($value);
        }

        if (Storage::disk('public')->exists($value)) {
            return Storage::disk('public')->url(ltrim($value, '/'));
        }

        $publicPath = public_path(ltrim($value, '/'));
        if (is_file($publicPath)) {
            return asset(ltrim($value, '/'));
        }

        return '';
    }

    private static function publicAssetUrl(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $fullPath = public_path($relativePath);

        return is_file($fullPath) && (filesize($fullPath) ?: 0) > 0 ? asset($relativePath) : '';
    }

    private static function versionedAssetUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $url;
        }

        $publicFile = public_path(ltrim($path, '/'));
        if (is_file($publicFile)) {
            $separator = str_contains($url, '?') ? '&' : '?';

            return $url . $separator . 'v=' . filemtime($publicFile);
        }

        $storageCandidate = ltrim($path, '/');
        $storageCandidate = str_starts_with($storageCandidate, 'storage/')
            ? substr($storageCandidate, 8)
            : $storageCandidate;

        if ($storageCandidate !== '' && Storage::disk('public')->exists($storageCandidate)) {
            $separator = str_contains($url, '?') ? '&' : '?';

            return $url . $separator . 'v=' . filemtime(Storage::disk('public')->path($storageCandidate));
        }

        return $url;
    }

    private static function manifestIconType(string $icon): string
    {
        return str_ends_with(strtolower($icon), '.svg') ? 'image/svg+xml' : 'image/x-icon';
    }
}
