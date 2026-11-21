<?php

/*
 * For the "Server deploy" workflow's newsletter-draft modes with newsletter=uae: the two emails to
 * UAE companies (a first email and one follow-up), Arabic first then English, saved in
 * Admin → Newsletter as drafts for the owner to read and send. It never sends anything: no
 * example, no batch, no delivery row. Sending stays in the admin.
 *
 *   "UAE companies 1: Emirati voices"  the dialect showcase, with a button to the Emirati page.
 *   "UAE companies 2: follow-up"       one short follow-up, asking for a line of their own script.
 *
 * Only what is true on this site goes in: the dialect counts are the site's own, rounded down,
 * and a tool is listed only when it is open to everyone here (video dubbing, voice cloning, voice
 * design, voice agents). No prices, no trial, no other company's name. The recipients are
 * company addresses published for business enquiries (RECIPIENTS below), for the owner to check.
 *
 * NL_APPLY=0 prints both emails as text and what was left out, and changes nothing. NL_APPLY=1
 * saves the drafts: new ones, or the same drafts again while they are still drafts with nothing
 * sent. Prints no member data.
 *
 *   ssh user@host 'cd ~/public_html && NL_APPLY=0 php' < scripts/support/newsletter_draft_uae.php
 */

use App\Models\Newsletter;
use App\Services\Newsletter\NewsletterRenderer;
use App\Support\Agents\AgentAccess;
use App\Support\StudioFacts;
use App\Support\VideoDubbing;
use App\Support\VoiceCloningAccess;
use App\Support\VoiceDesign;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = getenv('NL_APPLY') === '1';

// Company addresses each company publishes on its own site for business enquiries. Empty until the
// owner has checked the list; the drafts then send to nobody.
const RECIPIENTS = [];

$base = rtrim((string) config('app.url'), '/');
$safe = function (callable $check): bool {
    try {
        return (bool) $check();
    } catch (\Throwable) {
        return false;
    }
};

// What is open to everyone on this site now.
$open = [
    'dubbing' => $safe(fn () => VideoDubbing::audience() === VideoDubbing::AUDIENCE_EVERYONE),
    'cloning' => $safe(fn () => StudioFacts::cloning() && VoiceCloningAccess::audience() === VoiceCloningAccess::AUDIENCE_EVERYONE),
    'design' => $safe(fn () => VoiceDesign::shownOnSite(null)),
    'agents' => $safe(fn () => AgentAccess::openTo(null)),
];
$dialects = StudioFacts::roundedDown(StudioFacts::arabicDialects());
$countries = StudioFacts::arabicCountries();
$accents = StudioFacts::roundedDown(StudioFacts::englishAccents());

$tools = [
    'en' => array_filter([
        '- **Studio**: write in Arabic or English, pick the voice and dialect, and download the audio',
        $open['dubbing'] ? '- **Video dubbing**: every line re-voiced in the dialect or accent you choose, while the music and sound effects stay' : null,
        $open['cloning'] && $open['design'] ? '- **Voice cloning and design**: your spokesperson\'s voice, recorded with their consent, or a new voice you describe in words'
            : ($open['cloning'] ? '- **Voice cloning**: your spokesperson\'s voice, recorded with their consent' : ($open['design'] ? '- **Voice design**: a new voice you describe in words' : null)),
        $open['agents'] ? '- **Voice agents**: answer your customers in their dialect from a button on your website or a link of its own' : null,
        '- **Developer API**: Arabic and English speech inside your own apps',
    ]),
    'ar' => array_filter([
        '- **الاستوديو**: اكتبوا النص بالعربية أو الإنجليزية، واختاروا الصوت واللهجة، وحمّلوا الملف الصوتي',
        $open['dubbing'] ? '- **دبلجة الفيديو**: يُعاد أداء كل جملة باللهجة أو اللكنة التي تختارونها، وتبقى الموسيقى والمؤثرات' : null,
        $open['cloning'] && $open['design'] ? '- **استنساخ الصوت وتصميمه**: صوت المتحدث باسمكم بعد تسجيل موافقته، أو صوت جديد تصفونه بالكلمات'
            : ($open['cloning'] ? '- **استنساخ الصوت**: صوت المتحدث باسمكم بعد تسجيل موافقته' : ($open['design'] ? '- **تصميم الصوت**: صوت جديد تصفونه بالكلمات' : null)),
        $open['agents'] ? '- **الوكلاء الصوتيون**: يردّون على عملائكم بلهجتهم من زر في موقعكم أو من رابط خاص' : null,
        '- **واجهة المطورين**: لإضافة الكلام العربي والإنجليزي إلى تطبيقاتكم',
    ]),
];

$footer = [
    'en' => 'You are receiving this email from Listen, a service of SoliCode Ltd, because your company publishes this address for business enquiries. We will send one follow-up at most. To stop our emails, click Unsubscribe below or reply with "stop", and we will not write to you again. To reach us, simply reply to this email.',
    'ar' => 'وصلتكم هذه الرسالة من إسمع، وهي خدمة من SoliCode Ltd، لأن شركتكم تنشر هذا العنوان للتواصل التجاري. سنرسل رسالة متابعة واحدة على الأكثر. لإيقاف رسائلنا اضغطوا «إلغاء الاشتراك» أدناه أو ردّوا بكلمة «إيقاف»، ولن نراسلكم بعدها. وللتواصل معنا يكفي الرد على هذه الرسالة.',
];

$common = [
    'status' => Newsletter::STATUS_DRAFT,
    'audience' => Newsletter::AUDIENCE_CUSTOM,
    'plan_id' => null,
    'plan_ids' => [],
    'custom_emails' => RECIPIENTS === [] ? null : implode("\n", RECIPIENTS),
    'exclude_blocked' => true,
    'exclude_unsubscribed' => true,
    'exclude_already_sent' => true,
    'skip_phone_accounts' => true,
    'exclude_admins' => true,
    'language' => Newsletter::LANGUAGE_BOTH,
    'bilingual_order' => 'ar_en',
    'from_name' => 'Listen',
    'from_address' => 'help@listenai.studio',
    'reply_to' => null,
];

$lines = fn (array $texts): array => array_map(fn (string $text): array => ['text' => $text], $texts);

$drafts = [];

$drafts[] = array_merge($common, [
    'name' => 'UAE companies 1: Emirati voices',
    'subject_en' => 'Hear your brand in Emirati Arabic',
    'subject_ar' => 'صوت علامتكم التجارية باللهجة الإماراتية',
    'preheader_en' => 'Emirati, Gulf, MSA, Egyptian and Levantine voices, plus the English accents your customers speak.',
    'preheader_ar' => 'حامد وعائشة وسلطان بثلاث لهجات إماراتية، ومعهم أصوات خليجية وفصحى ومصرية وشامية وإنجليزية.',
    'eyebrow_en' => 'Voice samples for UAE teams',
    'eyebrow_ar' => 'عينات صوتية لفرق العمل في الإمارات',
    'headline_en' => 'Your brand\'s voice, in the dialect your audience knows',
    'headline_ar' => 'صوت علامتكم التجارية، باللهجة التي يعرفها جمهوركم',
    'intro_en' => 'We\'re Listen, an Arabic-first AI voice platform. If your company makes ads, project videos, welcome messages or training in Arabic and English, we\'ve gathered voices in your audience\'s dialects for you to hear for yourself.',
    'intro_ar' => 'نحن فريق إسمع، منصة أصوات بالذكاء الاصطناعي تبدأ بالعربية. إذا كانت شركتكم تُعدّ إعلانات أو فيديوهات مشاريع أو رسائل ترحيب أو دورات تدريبية بالعربية والإنجليزية، فقد جمعنا لكم أصواتاً بلهجات جمهوركم لتسمعوها بأنفسكم.',
    'body_en' => implode("\n\n", [
        "## From the Emirates\n".implode("\n", [
            '- **Hamed**: a lively, upbeat Emirati show host, for promos and shows',
            '- **Aisha**: a clear, polite voice from Ras Al Khaimah, for customer service and welcome messages',
            '- **Sultan**: a gravelly, unhurried Bedouin voice from Al Ain, for narration and stories',
            '- **Hessa**: an Emirati girl\'s voice, for children\'s and family content',
        ]),
        "## From the Gulf and the wider Arab world\n".implode("\n", [
            '- **Gulf**: Saudi, Qatari, Omani, Kuwaiti and Bahraini voices, including Abdulaziz, Abeer, Noura, Maha, Mishary and Reem',
            '- **Modern Standard Arabic**: for official, training and pan-Arab content, with a news style when you need one',
            '- **Egyptian and Levantine**: Egyptian, and Levantine in Lebanese, Syrian, Jordanian and Palestinian',
            '- **English**: British, American, Indian, Pakistani and Filipino accents, and Arabic-accented English',
        ]),
        "## What you can make\n".implode("\n", $tools['en']),
        'Need a plan for your team, or more minutes? Reply with how much audio you make each month, and we\'ll answer by email.',
        'The button opens our Emirati page. From there you can move to the other Arabic dialects and to our English accents.',
    ]),
    'body_ar' => implode("\n\n", [
        "## من الإمارات\n".implode("\n", [
            '- **حامد**: مقدّم برامج حيوي بلهجة إماراتية، للعروض الترويجية والبرامج',
            '- **عائشة**: صوت واضح ومهذّب من رأس الخيمة، لخدمة العملاء ورسائل الترحيب',
            '- **سلطان**: صوت أجشّ متأنٍّ بلهجة العين البدوية، للسرد والقصص',
            '- **حصة**: صوت طفلة إماراتية، لمحتوى الأطفال والعائلة',
        ]),
        "## من الخليج والعالم العربي\n".implode("\n", [
            '- **الخليجية**: أصوات سعودية وقطرية وعُمانية وكويتية وبحرينية، منها عبدالعزيز وعبير ونورة ومها ومشاري وريم',
            '- **الفصحى**: للمراسلات الرسمية والتدريب والمحتوى العربي العام، وبأسلوب إخباري عند الحاجة',
            '- **المصرية والشامية**: المصرية، والشامية بلهجاتها اللبنانية والسورية والأردنية والفلسطينية',
            '- **الإنجليزية**: بلكنات بريطانية وأمريكية وهندية وباكستانية وفلبينية، وبلكنة عربية',
        ]),
        "## ما الذي تصنعونه بها\n".implode("\n", $tools['ar']),
        'تحتاجون باقة لفريقكم أو دقائق أكثر؟ ردّوا على هذه الرسالة بكمية الصوت التي تنتجونها كل شهر، وسنرد عليكم بالبريد.',
        'يفتح الزر أدناه صفحة اللهجة الإماراتية، ومنها تنتقلون إلى بقية اللهجات العربية واللكنات الإنجليزية.',
    ]),
    'highlights_en' => $lines(array_filter([
        $dialects.'+ Arabic dialects from '.$countries.' countries, and '.$accents.'+ English accents',
        'Three Emirati variants: Dubai and Abu Dhabi, Northern (Ras Al Khaimah and Fujairah), and Bedouin (Al Ain)',
        $open['cloning'] ? 'No voice is cloned without a consent recording' : null,
    ])),
    'highlights_ar' => $lines(array_filter([
        'أكثر من '.$dialects.' لهجة عربية من '.$countries.' دولة، وأكثر من '.$accents.' لكنة إنجليزية',
        'ثلاث لهجات إماراتية: لهجة دبي وأبوظبي، والشمالية (رأس الخيمة والفجيرة)، والبدوية (العين)',
        $open['cloning'] ? 'لا يُستنسخ أي صوت إلا بتسجيل موافقة صاحبه' : null,
    ])),
    'cta_label_en' => 'Hear the Emirati voices',
    'cta_label_ar' => 'استمعوا إلى الأصوات الإماراتية',
    'cta_url_en' => $base.'/en/arabic-dialects/emirati',
    'cta_url_ar' => $base.'/ar/arabic-dialects/emirati',
    'footer_en' => $footer['en'],
    'footer_ar' => $footer['ar'],
    'internal_notes' => 'UAE companies, email 1 of 2. Send Tuesday 24 to Thursday 26 November (or 15 to 17 December), 09:00 to 11:00 UAE time. Recipients: company addresses published for business enquiries only. Add every "stop" / «إيقاف» reply to Newsletter → Unsubscribes. Prepared from the site\'s figures on '.now()->toDateString().'. Nothing has been sent.',
]);

$drafts[] = array_merge($common, [
    'name' => 'UAE companies 2: follow-up',
    'subject_en' => 'One line of your script, in an Emirati voice',
    'subject_ar' => 'سطر من نصكم، بصوت إماراتي',
    'preheader_en' => 'Send us one line, and we\'ll send it back voiced in the dialect you choose.',
    'preheader_ar' => 'أرسلوا لنا سطراً واحداً، ونعيده إليكم مسموعاً باللهجة التي تختارونها.',
    'eyebrow_en' => 'A short follow-up',
    'eyebrow_ar' => 'متابعة قصيرة',
    'headline_en' => 'Hear your own words',
    'headline_ar' => 'اسمعوا كلماتكم أنتم',
    'intro_en' => 'We wrote to you recently with Arabic and English voice samples. The easiest way to judge them is to hear your own script.',
    'intro_ar' => 'راسلناكم مؤخراً بعينات صوتية بالعربية والإنجليزية، وأسهل طريقة للحكم عليها أن تسمعوا نصكم أنتم.',
    'body_en' => implode("\n\n", [
        "Reply with one or two lines from an ad, a welcome message or a project video, and tell us the voice you want:\n".implode("\n", [
            '- **Emirati**: voiced by Hamed or Aisha',
            '- **Gulf, Modern Standard Arabic, Egyptian or Levantine**',
            '- **English**: in a British, Indian or Arabic accent',
        ]),
        'We\'ll reply with the audio file. If Ramadan content is in your 2027 plans, this is a good moment to try the voices before production starts.',
        'The button opens our English accents, from British and American to Indian, Pakistani, Filipino and Arabic-accented English.',
    ]),
    'body_ar' => implode("\n\n", [
        "ردّوا على هذه الرسالة بسطر أو سطرين من إعلان أو رسالة ترحيب أو فيديو مشروع، واذكروا الصوت الذي تريدونه:\n".implode("\n", [
            '- **الإماراتية**: بصوت حامد أو عائشة',
            '- **الخليجية أو الفصحى أو المصرية أو الشامية**',
            '- **الإنجليزية**: بلكنة بريطانية أو هندية أو بلكنة عربية',
        ]),
        'وسنرد عليكم بالملف الصوتي. وإن كان محتوى رمضان القادم ضمن خطتكم، فهذا وقت مناسب لتجربة الأصوات قبل بدء الإنتاج.',
        'في صفحة العينات، اختاروا «الخليج» لتسمعوا الأصوات الإماراتية والخليجية.',
    ]),
    'highlights_en' => $lines(['Just reply to this email; you don\'t need an account']),
    'highlights_ar' => $lines(['يكفي الرد على هذه الرسالة، ولا حاجة إلى إنشاء حساب']),
    'cta_label_en' => 'Hear the English accents',
    'cta_label_ar' => 'استمعوا إلى أصوات الخليج',
    'cta_url_en' => $base.'/en/voice-samples#english-accents',
    'cta_url_ar' => $base.'/ar/voice-samples#arabic-dialects',
    'footer_en' => 'This is the one follow-up we mentioned, and we won\'t write again unless you reply. It comes from Listen, a service of SoliCode Ltd, because your company publishes this address for business enquiries. To stop our emails, click Unsubscribe below or reply with "stop".',
    'footer_ar' => 'هذه رسالة المتابعة الوحيدة التي ذكرناها، ولن نراسلكم بعدها ما لم تردّوا علينا. وصلتكم من إسمع، وهي خدمة من SoliCode Ltd، لأن شركتكم تنشر هذا العنوان للتواصل التجاري. لإيقاف رسائلنا اضغطوا «إلغاء الاشتراك» أدناه أو ردّوا بكلمة «إيقاف».',
    'internal_notes' => 'UAE companies, email 2 of 2. Send 15 to 17 December, 09:00 to 11:00 UAE time, to the same list minus everyone who replied. Its offer (we voice a line of their script) means making that audio for each reply. Prepared on '.now()->toDateString().'. Nothing has been sent.',
]);

echo "== What this site offers everyone now ==\n";
foreach ($open as $tool => $isOpen) {
    echo '  '.str_pad($tool, 8).($isOpen ? 'open: in the email' : 'not open to everyone: left out')."\n";
}
echo "  counts: {$dialects}+ Arabic dialects from {$countries} countries, {$accents}+ English accents\n";
echo '  recipients: '.count(RECIPIENTS)." company addresses\n";

$renderer = app(NewsletterRenderer::class);
foreach ($drafts as $content) {
    $draft = Newsletter::query()->where('name', $content['name'])->latest('id')->first();
    $probe = new Newsletter($content);
    echo "\n======== {$content['name']} ========\n";
    echo 'Subject: '.$renderer->subject($probe, 'ar')."\n\n";
    foreach (['ar', 'en'] as $lang) {
        echo "--- {$lang} ---\n".$renderer->text($probe, $lang, ['preview' => true], true)."\n\n";
    }
    if (! $apply) {
        continue;
    }
    if ($draft && ($draft->status !== Newsletter::STATUS_DRAFT || (int) $draft->sent_count > 0 || $draft->deliveries()->where('is_test', false)->exists())) {
        echo "  LEFT AS IT IS: \"{$draft->name}\" (#{$draft->id}) has started sending.\n";

        continue;
    }
    $draft ? $draft->fill($content)->save() : ($draft = Newsletter::create($content));
    echo "  SAVED AS A DRAFT: newsletter #{$draft->id}. Nothing was sent.\n  Admin: {$base}/admin/newsletter/{$draft->id}/edit\n";
}

echo $apply ? "\nDone. Nothing was sent.\n" : "\nNOTHING SAVED (preview). Run newsletter-draft-save with newsletter=uae to save the drafts.\n";
