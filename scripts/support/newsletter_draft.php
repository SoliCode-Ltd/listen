<?php

/*
 * For the "Server deploy" workflow's newsletter-draft modes: the announcement of speech to text
 * to paid members, saved in Admin → Newsletter as a draft for the owner to read and send.
 * It never sends anything: no example, no batch, no delivery row. Sending stays in the admin.
 *
 * Its figures are the site's own, read when it runs (as the speech to text page reads them):
 * the plans on sale that include it and their minutes, the languages, the free try and the
 * largest upload. NL_APPLY=0 prints them, who it would reach (as counts) and the whole email,
 * and changes nothing. NL_APPLY=1 saves the draft: a new one, or the same draft again while it
 * is still a draft with nothing sent. Prints no member data.
 *
 *   ssh user@host 'cd ~/public_html && NL_APPLY=0 php' < scripts/support/newsletter_draft.php
 */

use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\User;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Newsletter\NewsletterRenderer;
use App\Support\StudioFacts;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = function (string $why): never {
    echo "STOPPED: {$why}\n";
    exit(1);
};
$apply = getenv('NL_APPLY') === '1';
const DRAFT_NAME = 'Speech to text: paid members';

// What is true on this site now.
if (! StudioFacts::speechToText()) {
    $fail('speech to text is not public here (open to everyone, on a plan on sale), so there is nothing to announce yet.');
}
$languages = StudioFacts::speechToTextLanguages();
$plansEn = StudioFacts::speechToTextPlans('en');
$plansAr = StudioFacts::speechToTextPlans('ar');
$free = TranscriptionSettings::freeTrySeconds();
$mb = TranscriptionSettings::maxUploadMb();
if ($languages < 1 || $plansEn === []) {
    $fail('the languages or the plans that include speech to text could not be read.');
}
$minutesEn = fn (int $n): string => trans_choice('ui.pricing.lines.stt_minutes', $n, ['count' => number_format($n)], 'en');
$minutesAr = fn (int $n): string => trans_choice('ui.pricing.lines.stt_minutes', $n, ['count' => number_format($n)], 'ar');
$listEn = implode(', ', array_map(fn (array $p): string => $p['name'].' '.$minutesEn($p['minutes']), $plansEn));
$listAr = implode('، ', array_map(fn (array $p): string => $p['name'].' '.$minutesAr($p['minutes']), $plansAr));
$freeEn = $free % 60 === 0 ? $minutesEn(intdiv($free, 60)) : $free.' seconds';
$freeAr = $free % 60 === 0 ? $minutesAr(intdiv($free, 60)) : $free.' ثانية';

// Who it reaches: paid members, and how many of them have it in their plan (counts only).
$draft = Newsletter::query()->where('name', DRAFT_NAME)->latest('id')->first();
$probe = $draft ?? new Newsletter(array_merge(Newsletter::defaultAttributes(), ['audience' => Newsletter::AUDIENCE_PAID]));
$reach = 0;
$included = 0;
$byPlan = [];
app(NewsletterAudience::class)->each($probe, function (array $recipient) use (&$reach, &$included, &$byPlan): void {
    $reach++;
    $user = $recipient['user_id'] ? User::find($recipient['user_id']) : null;
    $plan = $user ? TranscriptionAccess::planOf($user) : null;
    $key = $plan ? (string) $plan->slug : '(no plan)';
    $byPlan[$key] = ($byPlan[$key] ?? 0) + 1;
    if (TranscriptionAccess::planIncludes($plan)) {
        $included++;
    }
});
$everyone = $reach > 0 && $included === $reach;

// The email. Plain and exact: what it is, what it does, what each plan has, where to start.
$planLineEn = $everyone
    ? 'It is already part of your plan, and each plan comes with its own minutes every month: '.$listEn.'.'
    : 'It is included in these plans, each with its own minutes every month: '.$listEn.'.'
        .($free > 0 ? ' If your plan is not one of them, you can try it with one recording of up to '.$freeEn.'.' : '');
$planLineAr = $everyone
    ? 'والخدمة متاحة الآن ضمن باقتك، ولكل باقة دقائقها التي تتجدد كل شهر: '.$listAr.'.'
    : 'والخدمة متاحة في هذه الباقات، ولكل باقة دقائقها التي تتجدد كل شهر: '.$listAr.'.'
        .($free > 0 ? ' وإن لم تكن باقتك منها، يمكنك تجربتها بتسجيل واحد مدته حتى '.$freeAr.'.' : '');

$content = [
    'name' => DRAFT_NAME,
    'status' => Newsletter::STATUS_DRAFT,
    'audience' => Newsletter::AUDIENCE_PAID,
    'plan_id' => null,
    'custom_emails' => null,
    'exclude_blocked' => true,
    'exclude_unsubscribed' => true,
    'exclude_already_sent' => true,
    'skip_phone_accounts' => true,
    'exclude_admins' => true,
    'language' => Newsletter::LANGUAGE_BOTH,
    'bilingual_order' => 'ar_en',
    'subject_en' => 'New in Listen: speech to text',
    'subject_ar' => 'جديد في إسمع: تحويل الصوت إلى نص',
    'preheader_en' => 'Turn any recording into text, with who said what and when.',
    'preheader_ar' => 'حوّل أي تسجيل إلى نص، مع من قال ماذا ومتى.',
    'eyebrow_en' => '',
    'eyebrow_ar' => '',
    'headline_en' => 'Your recordings, written out word for word',
    'headline_ar' => 'تسجيلاتك مكتوبة كلمة بكلمة',
    'intro_en' => 'Listen now turns speech into text. Upload a recording, paste a link or record in your browser, and Listen writes out every word, with who said it and when.',
    'intro_ar' => 'أصبحت منصة إسمع تحوّل الكلام إلى نص. ارفع تسجيلاً أو الصق رابطاً أو سجّل من متصفحك، فتكتب المنصة كل كلمة مع من قالها ومتى.',
    'body_en' => implode("\n\n", [
        'The transcript opens in an editor that plays the audio along with the words. Correct a word, name the speakers, show it in another language, then download it as text, subtitles or a document.',
        $planLineEn,
        'Minutes are counted to the second, and a file that fails or has no speech in it uses none. Audio and video files up to '.$mb.' MB are accepted, and plans with API access can use it through the developer API too.',
    ]),
    'body_ar' => implode("\n\n", [
        'يفتح النص في محرر يشغّل الصوت مع الكلمات. صحّح أي كلمة، وسمِّ المتحدثين، واعرض النص بلغة أخرى، ثم نزّله نصاً أو ترجمة مرئية أو مستنداً.',
        $planLineAr,
        'تُحسب الدقائق بالثانية، والملف الذي يتعذر تحويله أو لا كلام فيه لا يستهلك شيئاً. يمكنك رفع ملفات الصوت والفيديو حتى '.$mb.' ميغابايت، ويمكن استخدامها أيضاً عبر واجهة المطورين في الباقات التي تشملها.',
    ]),
    'highlights_en' => [
        ['text' => 'Who said what: every speaker named, every sentence timed'],
        ['text' => $languages.' languages, including Arabic in its dialects and English'],
        ['text' => 'Downloads as TXT, SRT and VTT subtitles, or a DOCX document'],
        ['text' => 'Translation into another language, using no minutes'],
    ],
    'highlights_ar' => [
        ['text' => 'من قال ماذا: اسم لكل متحدث ووقت لكل جملة'],
        ['text' => $languages.' لغة، منها العربية بلهجاتها والإنجليزية'],
        ['text' => 'تنزيل النص بصيغة TXT أو ترجمة مرئية SRT وVTT أو مستند DOCX'],
        ['text' => 'ترجمة النص إلى لغة أخرى دون استهلاك أي دقائق'],
    ],
    'cta_label_en' => 'Open speech to text',
    'cta_label_ar' => 'افتح تحويل الصوت إلى نص',
    'cta_url_en' => rtrim((string) config('app.url'), '/').'/en/dashboard#transcribeSection',
    'cta_url_ar' => rtrim((string) config('app.url'), '/').'/ar/dashboard#transcribeSection',
    'footer_en' => 'You received this because you have a paid Listen plan.',
    'footer_ar' => 'وصلتك هذه الرسالة لأن لديك باقة مدفوعة على منصة إسمع.',
    'from_name' => 'Listen',
    'from_address' => 'help@listenai.studio',
    'reply_to' => null,
    'internal_notes' => 'Prepared from the site\'s own figures on '.now()->toDateString().'. Read it, send yourself an example, then send. Nothing has been sent.',
];

echo "== Speech to text on this site ==\n";
echo "  languages: {$languages}\n  plans: {$listEn}\n  free try: ".($free > 0 ? $freeEn : 'none')."\n  largest upload: {$mb} MB\n";
echo "== Paid members it reaches (after the skips) ==\n  {$reach}, of whom {$included} have speech to text in their plan\n";
ksort($byPlan);
foreach ($byPlan as $slug => $count) {
    $plan = Plan::where('slug', $slug)->first();
    echo "  {$slug}: {$count}".($plan && TranscriptionAccess::planIncludes($plan) ? ' (includes it, '.TranscriptionAccess::monthlyMinutes($plan).' min)' : ' (does not include it)')."\n";
}
echo '  wording: '.($everyone ? 'every one of them has it ("already part of your plan")' : 'not all have it (the plans are listed, with the free try)')."\n";

$preview = new Newsletter($content);
$renderer = app(NewsletterRenderer::class);
echo "\n== The email (as text) ==\n";
foreach (['en', 'ar'] as $locale) {
    echo '--- '.$locale.' --- Subject: '.$renderer->subject($preview, $locale)."\n\n";
    echo $renderer->text($preview, $locale, ['preview' => true], true)."\n\n";
}

if (! $apply) {
    echo "\nNOTHING SAVED (preview). Run newsletter-draft-save to save it as a draft.\n";
    exit(0);
}
if ($draft && ($draft->status !== Newsletter::STATUS_DRAFT || (int) $draft->sent_count > 0 || $draft->deliveries()->where('is_test', false)->exists())) {
    $fail("\"".DRAFT_NAME."\" (#{$draft->id}) has started sending, so it is left as it is.");
}
if ($draft) {
    $draft->fill($content)->save();
} else {
    $draft = Newsletter::create($content);
}
echo "\nSAVED AS A DRAFT: newsletter #{$draft->id}, \"".DRAFT_NAME."\". Nothing was sent.\n";
echo '  Admin: '.rtrim((string) config('app.url'), '/')."/admin/newsletter/{$draft->id}/edit\n";
