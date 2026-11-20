<?php

/*
 * For the "Server deploy" workflow's newsletter-draft modes: two announcements of speech to text,
 * saved in Admin → Newsletter as drafts for the owner to read and send. It never sends anything:
 * no example, no batch, no delivery row. Sending stays in the admin.
 *
 *   "Speech to text: plans that include it"  to the plans that include it (Chosen plans): what it
 *       does, and each person's own plan and minutes ({plan}, {stt_minutes}).
 *   "Speech to text: Basic upgrade"          to the paid plans on sale without it (Basic today):
 *       what it does, the free try, and the plans that include it, with their minutes.
 *
 * Its figures are the site's own, read when it runs. NL_APPLY=0 prints them, who each email would
 * reach (as counts) and both emails as text, and changes nothing. NL_APPLY=1 saves the drafts: new
 * ones, or the same drafts again while they are still drafts with nothing sent. Prints no member data.
 *
 *   ssh user@host 'cd ~/public_html && NL_APPLY=0 php' < scripts/support/newsletter_draft.php
 */

use App\Models\Newsletter;
use App\Models\Plan;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Newsletter\NewsletterRenderer;
use App\Support\StudioFacts;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// An error anywhere stops the check as FAILED (left to Laravel, it would be shown and the run would still pass).
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

$fail = function (string $why): never {
    echo "STOPPED: {$why}\n";
    exit(1);
};
$apply = getenv('NL_APPLY') === '1';

if (! Schema::hasColumn('newsletters', 'plan_ids')) {
    $fail('this site cannot send a newsletter to several plans yet (the plan_ids migration has not run).');
}
if (! StudioFacts::speechToText()) {
    $fail('speech to text is not public here (open to everyone, on a plan on sale), so there is nothing to announce yet.');
}

// What is true on this site now.
$languages = StudioFacts::speechToTextLanguages();
$mb = TranscriptionSettings::maxUploadMb();
$free = TranscriptionSettings::freeTrySeconds();
$paidPlans = Plan::query()->where('price_cents', '>', 0)->orderBy('price_cents')->get();
$withIt = $paidPlans->filter(fn (Plan $plan): bool => TranscriptionAccess::planIncludes($plan))->values();
$onSaleWithIt = collect(StudioFacts::speechToTextPlans('en'));
$withoutIt = $paidPlans->filter(fn (Plan $plan): bool => ! TranscriptionAccess::planIncludes($plan))->values();
if ($languages < 1 || $withIt->isEmpty() || $onSaleWithIt->isEmpty()) {
    $fail('the languages or the plans that include speech to text could not be read.');
}
$name = fn (Plan $plan, string $lang): string => (string) ($plan->getLocalizedName($lang) ?: $plan->name);
$minutes = fn (int $n, string $lang): string => trans_choice('ui.pricing.lines.stt_minutes', $n, ['count' => number_format($n)], $lang);
$freeLength = fn (string $lang): string => $free % 60 === 0 ? $minutes(intdiv($free, 60), $lang) : $free.($lang === 'ar' ? ' ثانية' : ' seconds');
$base = rtrim((string) config('app.url'), '/');
$studio = fn (string $lang): string => $base.'/'.$lang.'/dashboard#transcribeSection';
// The plans on sale that include it, cheapest first, as list lines: "**Starter**: 120 minutes a month".
$planLines = fn (string $lang): string => implode("\n", array_map(
    fn (array $p): string => '- **'.$p['name'].'**: '.$minutes($p['minutes'], $lang).($lang === 'ar' ? ' شهرياً' : ' a month'),
    StudioFacts::speechToTextPlans($lang)
));
$first = StudioFacts::speechToTextPlans('en')[0];
$firstAr = StudioFacts::speechToTextPlans('ar')[0];

// What it does, the same in both emails.
$features = [
    'en' => implode("\n", [
        '- **Add a recording your way.** Upload an audio or video file of up to '.$mb.' MB, paste a link, or record straight from your browser.',
        '- **See who said what.** Every speaker is labelled and every sentence is timed, so any moment is easy to find.',
        '- **Work in '.$languages.' languages.** Including Arabic in its dialects, Modern Standard Arabic and English. The language is detected for you.',
        '- **Edit as you listen.** The text follows the audio as it plays. Correct a word or rename a speaker in place.',
        '- **Download or translate.** Export TXT, SRT or VTT subtitles, or a DOCX document, in the original language or translated.',
    ]),
    'ar' => implode("\n", [
        '- **أضف تسجيلك بالطريقة التي تناسبك.** ارفع ملف صوت أو فيديو حتى '.$mb.' ميغابايت، أو الصق رابطاً، أو سجّل مباشرة من متصفحك.',
        '- **اعرف من قال ماذا.** لكل متحدث اسمه، ولكل جملة وقتها، فتصل إلى أي لحظة في التسجيل بسهولة.',
        '- **'.$languages.' لغة.** منها العربية بلهجاتها والفصحى والإنجليزية، وتُكتشف لغة التسجيل تلقائياً.',
        '- **عدّل وأنت تستمع.** يتابع النص الصوت أثناء التشغيل، فتصحّح أي كلمة أو تغيّر اسم أي متحدث في مكانه.',
        '- **نزّل النص أو ترجمه.** صدّره بصيغة TXT أو ترجمة مرئية SRT وVTT أو مستند DOCX، بلغته الأصلية أو مترجماً.',
    ]),
];
$signOff = ['en' => "If you have any questions, just reply to this email. We are happy to help.\n\nBest regards,\nThe Listen team",
    'ar' => "إن كان لديك أي سؤال، يكفي أن ترد على هذه الرسالة، ويسعدنا مساعدتك.\n\nمع أطيب التحيات،\nفريق منصة إسمع"];

$common = [
    'status' => Newsletter::STATUS_DRAFT,
    'audience' => Newsletter::AUDIENCE_PLAN,
    'custom_emails' => null,
    'exclude_blocked' => true,
    'exclude_unsubscribed' => true,
    'exclude_already_sent' => true,
    'skip_phone_accounts' => true,
    'exclude_admins' => true,
    'language' => Newsletter::LANGUAGE_BOTH,
    'bilingual_order' => 'ar_en',
    'eyebrow_en' => '',
    'eyebrow_ar' => '',
    'highlights_en' => [],
    'highlights_ar' => [],
    'footer_en' => 'You are receiving this because you have an active Listen subscription.',
    'footer_ar' => 'وصلتك هذه الرسالة لأن لديك اشتراكاً فعّالاً في منصة إسمع.',
    'from_name' => 'Listen',
    'from_address' => 'help@listenai.studio',
    'reply_to' => null,
];

$drafts = [];

// 1. To the plans that include it: each person reads their own plan and minutes.
$drafts[] = array_merge($common, [
    'name' => 'Speech to text: plans that include it',
    'plan_ids' => $withIt->pluck('id')->map(fn ($id): int => (int) $id)->all(),
    'plan_id' => (int) $withIt->first()->id,
    'subject_en' => 'Speech to text is now included in your plan',
    'subject_ar' => 'تحويل الصوت إلى نص أصبح ضمن باقتك',
    'preheader_en' => 'Turn your recordings into editable transcripts and subtitles, in '.$languages.' languages.',
    'preheader_ar' => 'حوّل تسجيلاتك إلى نصوص قابلة للتعديل وترجمات مرئية، بـ'.$languages.' لغة.',
    'headline_en' => 'Speech to text is now part of your plan',
    'headline_ar' => 'تحويل الصوت إلى نص أصبح جزءاً من باقتك',
    'intro_en' => 'We have added speech to text to Listen, and it is already included in your {plan} plan. From today, you can turn any recording into a clear, editable transcript, in the same studio you use for your voices.',
    'intro_ar' => 'أضفنا خدمة تحويل الصوت إلى نص إلى منصة إسمع، وهي متاحة الآن ضمن باقة {plan} التي تشترك فيها. يمكنك من اليوم تحويل أي تسجيل إلى نص واضح قابل للتعديل، من الاستوديو نفسه الذي تستخدمه لأصواتك.',
    'body_en' => implode("\n\n", [
        "## What you can do\n".$features['en'],
        "## Your minutes\nYour {plan} plan includes **{stt_minutes} minutes** of speech to text every month. Minutes are counted to the second, a file that fails or has no speech in it uses none, and translating a transcript uses no minutes at all.",
        "## Getting started\nOpen your dashboard, choose **Speech to text**, and add your first recording. The transcript appears as soon as it is ready.",
        $signOff['en'],
    ]),
    'body_ar' => implode("\n\n", [
        "## ما الذي يمكنك فعله\n".$features['ar'],
        "## دقائقك\nتتضمن باقة {plan} **{stt_minutes} دقيقة** من تحويل الصوت إلى نص كل شهر. تُحسب الدقائق بالثانية، والملف الذي يتعذر تحويله أو لا كلام فيه لا يستهلك شيئاً، وترجمة النص لا تستهلك أي دقائق.",
        "## كيف تبدأ\nافتح لوحة التحكم، واختر **تحويل الصوت إلى نص**، ثم أضف تسجيلك الأول، وسيظهر النص فور جاهزيته.",
        $signOff['ar'],
    ]),
    'cta_label_en' => 'Start transcribing',
    'cta_label_ar' => 'ابدأ تحويل تسجيلاتك',
    'cta_url_en' => $studio('en'),
    'cta_url_ar' => $studio('ar'),
    'internal_notes' => 'To the plans that include speech to text. Each person reads their own plan and minutes. Prepared from the site\'s figures on '.now()->toDateString().'. Nothing has been sent.',
]);

// 2. To the paid plans without it: the free try, and the plans that include it.
if ($withoutIt->isNotEmpty()) {
    $drafts[] = array_merge($common, [
        'name' => 'Speech to text: Basic upgrade',
        'plan_ids' => $withoutIt->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        'plan_id' => (int) $withoutIt->first()->id,
        'subject_en' => 'New in Listen: speech to text',
        'subject_ar' => 'جديد في إسمع: تحويل الصوت إلى نص',
        'preheader_en' => 'Try it with a free recording today, or upgrade for minutes every month.',
        'preheader_ar' => 'جرّبه اليوم بتسجيل مجاني، أو رقِّ باقتك لتحصل على دقائق كل شهر.',
        'headline_en' => 'Turn your recordings into text',
        'headline_ar' => 'حوّل تسجيلاتك إلى نص',
        'intro_en' => 'We have added speech to text to Listen. It turns any recording into a clear, editable transcript, with every speaker labelled and every sentence timed, in the same studio you use for your voices.',
        'intro_ar' => 'أضفنا خدمة تحويل الصوت إلى نص إلى منصة إسمع. تحوّل الخدمة أي تسجيل إلى نص واضح قابل للتعديل، مع اسم كل متحدث ووقت كل جملة، من الاستوديو نفسه الذي تستخدمه لأصواتك.',
        'body_en' => implode("\n\n", array_filter([
            "## What it does\n".$features['en'],
            $free > 0 ? "## Try it today\nSpeech to text is not part of the {plan} plan, but you can try it now with one recording of up to ".$freeLength('en').', at no cost. Open your dashboard and choose **Speech to text**.' : null,
            "## Get minutes every month\nUpgrade to **".$first['name'].'** to get '.$minutes($first['minutes'], 'en').' of speech to text every month, renewed with your plan. These plans include it:'."\n\n".$planLines('en'),
            'Minutes are counted to the second, a file that fails or has no speech in it uses none, and translating a transcript uses no minutes at all.',
            $signOff['en'],
        ])),
        'body_ar' => implode("\n\n", array_filter([
            "## ما الذي تقدمه الخدمة\n".$features['ar'],
            $free > 0 ? "## جرّبها اليوم\nخدمة تحويل الصوت إلى نص ليست ضمن باقة {plan}، لكن يمكنك تجربتها الآن بتسجيل واحد مدته حتى ".$freeLength('ar').' دون أي تكلفة. افتح لوحة التحكم واختر **تحويل الصوت إلى نص**.' : null,
            "## احصل على دقائق كل شهر\nرقِّ باقتك إلى **".$firstAr['name'].'** لتحصل على '.$minutes($firstAr['minutes'], 'ar').' من تحويل الصوت إلى نص كل شهر، تتجدد مع باقتك. هذه الباقات تشمل الخدمة:'."\n\n".$planLines('ar'),
            'تُحسب الدقائق بالثانية، والملف الذي يتعذر تحويله أو لا كلام فيه لا يستهلك شيئاً، وترجمة النص لا تستهلك أي دقائق.',
            $signOff['ar'],
        ])),
        'cta_label_en' => 'Compare plans',
        'cta_label_ar' => 'قارن الباقات',
        'cta_url_en' => Newsletter::billingUrl('en'),
        'cta_url_ar' => Newsletter::billingUrl('ar'),
        'internal_notes' => 'To the paid plans without speech to text: the free try, then the plans that include it. Prepared from the site\'s figures on '.now()->toDateString().'. Nothing has been sent.',
    ]);
}

echo "== Speech to text on this site ==\n";
echo "  languages: {$languages}\n  free try: ".($free > 0 ? $freeLength('en') : 'none')."\n  largest upload: {$mb} MB\n";
echo '  paid plans with it: '.$withIt->map(fn (Plan $p): string => $p->slug.' ('.TranscriptionAccess::monthlyMinutes($p).' min)')->implode(', ')."\n";
echo '  paid plans without it: '.($withoutIt->isEmpty() ? 'none' : $withoutIt->pluck('slug')->implode(', '))."\n";

$renderer = app(NewsletterRenderer::class);
$audience = app(NewsletterAudience::class);
// The first draft's old name, from the earlier version of this script.
$oldNames = ['Speech to text: plans that include it' => 'Speech to text: paid members'];
foreach ($drafts as $content) {
    $draft = Newsletter::query()->where('name', $content['name'])->latest('id')->first()
        ?? (isset($oldNames[$content['name']]) ? Newsletter::query()->where('name', $oldNames[$content['name']])->latest('id')->first() : null);
    $probe = new Newsletter($content);
    echo "\n======== {$content['name']} ========\n";
    echo '  to plans: '.Plan::query()->whereIn('id', $content['plan_ids'])->pluck('slug')->implode(', ').'; reaches '.$audience->count($probe)." people (after the skips)\n";
    foreach (['ar', 'en'] as $lang) {
        echo "\n--- {$lang} --- Subject: ".$renderer->subject($probe, $lang)."\n\n";
        echo $renderer->text($probe, $lang, ['preview' => true, 'name_en' => 'Sara', 'name_ar' => 'سارة'], true)."\n";
    }
    if (! $apply) {
        continue;
    }
    if ($draft && ($draft->status !== Newsletter::STATUS_DRAFT || (int) $draft->sent_count > 0 || $draft->deliveries()->where('is_test', false)->exists())) {
        echo "\n  LEFT AS IT IS: \"{$draft->name}\" (#{$draft->id}) has started sending.\n";

        continue;
    }
    $draft ? $draft->fill($content)->save() : ($draft = Newsletter::create($content));
    echo "\n  SAVED AS A DRAFT: newsletter #{$draft->id}. Nothing was sent.\n  Admin: {$base}/admin/newsletter/{$draft->id}/edit\n";
}

echo $apply ? "\nDone. Nothing was sent.\n" : "\nNOTHING SAVED (preview). Run newsletter-draft-save to save the drafts.\n";
