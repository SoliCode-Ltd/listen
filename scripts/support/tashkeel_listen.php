<?php

/*
 * For the "Server deploy" workflow's tashkeel-listen mode, on STAGING only:
 * does a premium voice say what the vowel marks (tashkeel) say? It makes
 * short Arabic clips with the studio's own speech engine, for no member, and
 * a listening model says which reading of one word it heard. Owner-approved
 * (2026-11-23): up to 30 minutes of speech a round on the quota staging
 * shares with live; this round makes about 14, and stops by itself at 25.
 *
 * The words are homographs: one spelling, several readings (علم is ʿilm,
 * ʿalam, ʿalima or ʿallama). Each clip is one of:
 *   iso       the word alone, marked, after a carrier that hints at nothing
 *   plain     a sentence as members write it, no marks: does the voice choose
 *             the reading the sentence means?
 *   word      the same sentence with only that word marked
 *   full      the same sentence with every word marked
 *   wrong     the sentence with the word marked as the reading the sentence
 *             does NOT mean: a voice that obeys marks says the marked one
 *   wrong+dir the same, and the voice is told to follow the marks (the
 *             direction a member's saved pronunciation already sends)
 * The listener is asked for the sounds, not the meaning; "iso" and "wrong"
 * show how far it can be trusted, since there the meaning cannot help it.
 *
 * Prints one line a clip and the scores for each kind. The clips stay on the
 * public disk (tashkeel-listen/<run>/) to hear; nothing else is written.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/tashkeel_listen.php
 *   ONLY=iso,wrong VOICE=Kore LIMIT=10 ... php
 */

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioDuration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

ini_set('display_errors', 'stderr');
set_time_limit(2700);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (str_contains(strtolower((string) config('app.url')), 'listenai.studio')) {
    fwrite(STDERR, "This is the live site: tashkeel-listen runs on staging only.\n");
    exit(1);
}

// One spelling, its readings: id => [marked, as heard in Latin letters, meaning].
$groups = [
    'علم' => ['ilm' => ['عِلْم', 'ʿilm', 'knowledge'], 'alam' => ['عَلَم', 'ʿalam', 'flag'], 'alima' => ['عَلِمَ', 'ʿalima', 'he knew'], 'allama' => ['عَلَّمَ', 'ʿallama', 'he taught']],
    'كتب' => ['kutub' => ['كُتُب', 'kutub', 'books'], 'kataba' => ['كَتَبَ', 'kataba', 'he wrote'], 'kutiba' => ['كُتِبَ', 'kutiba', 'it was written']],
    'ملك' => ['malik' => ['مَلِك', 'malik', 'king'], 'mulk' => ['مُلْك', 'mulk', 'reign'], 'malak' => ['مَلَك', 'malak', 'angel']],
    'درس' => ['darasa' => ['دَرَسَ', 'darasa', 'he studied'], 'dars' => ['دَرْس', 'dars', 'lesson'], 'darrasa' => ['دَرَّسَ', 'darrasa', 'he taught']],
    'شعر' => ['shaar' => ['شَعْر', 'shaʿr', 'hair'], 'shiir' => ['شِعْر', 'shiʿr', 'poetry'], 'shaara' => ['شَعَرَ', 'shaʿara', 'he felt']],
    'عقد' => ['aqd' => ['عَقْد', 'ʿaqd', 'contract'], 'iqd' => ['عِقْد', 'ʿiqd', 'necklace'], 'aqada' => ['عَقَدَ', 'ʿaqada', 'he held']],
    'قدم' => ['qadam' => ['قَدَم', 'qadam', 'foot'], 'qaddama' => ['قَدَّمَ', 'qaddama', 'he presented'], 'qadima' => ['قَدِمَ', 'qadima', 'he arrived']],
    'رجل' => ['rajul' => ['رَجُل', 'rajul', 'man'], 'rijl' => ['رِجْل', 'rijl', 'leg']],
    'مدرسة' => ['madrasa' => ['مَدْرَسَة', 'madrasa', 'school'], 'mudarrisa' => ['مُدَرِّسَة', 'mudarrisa', 'woman teacher']],
    'ظهر' => ['zahara' => ['ظَهَرَ', 'ẓahara', 'it appeared'], 'zahr' => ['ظَهْر', 'ẓahr', 'back'], 'zuhr' => ['ظُهْر', 'ẓuhr', 'noon']],
    'جزر' => ['jazar' => ['جَزَر', 'jazar', 'carrots'], 'juzur' => ['جُزُر', 'juzur', 'islands']],
    'قبل' => ['qabla' => ['قَبْلَ', 'qabla', 'before'], 'qabila' => ['قَبِلَ', 'qabila', 'he accepted']],
    'سلم' => ['sullam' => ['سُلَّم', 'sullam', 'ladder'], 'sallama' => ['سَلَّمَ', 'sallama', 'he handed over'], 'silm' => ['سِلْم', 'silm', 'peace']],
    'دين' => ['dayn' => ['دَيْن', 'dayn', 'debt'], 'din' => ['دِين', 'dīn', 'religion']],
    'يعد' => ['yuaddu' => ['يُعَدُّ', 'yuʿadd(u)', 'is considered'], 'yuiddu' => ['يُعِدُّ', 'yuʿidd(u)', 'prepares'], 'yaidu' => ['يَعِدُ', 'yaʿid(u)', 'promises']],
    'نشر' => ['nashara' => ['نَشَرَ', 'nashara', 'he published'], 'nushira' => ['نُشِرَ', 'nushira', 'it was published']],
    'ولد' => ['wulida' => ['وُلِدَ', 'wulida', 'he was born'], 'walad' => ['وَلَد', 'walad', 'boy']],
];

// [spelling, sentence as written, the word in it, its reading, the word marked, the sentence fully marked, (the wrong reading, the word marked wrongly)]
$sentences = [
    ['علم', 'رفع الجنود علم بلادهم فوق القلعة.', 'علم', 'alam', 'عَلَم', 'رَفَعَ الْجُنُودُ عَلَمَ بِلَادِهِمْ فَوْقَ الْقَلْعَة.', 'ilm', 'عِلْم'],
    ['علم', 'طلب العلم واجب على كل إنسان.', 'العلم', 'ilm', 'العِلْم', 'طَلَبُ الْعِلْمِ وَاجِبٌ عَلَى كُلِّ إِنْسَان.', 'alam', 'العَلَم'],
    ['علم', 'علم المدير بالخبر بعد يومين.', 'علم', 'alima', 'عَلِمَ', 'عَلِمَ الْمُدِيرُ بِالْخَبَرِ بَعْدَ يَوْمَيْن.'],
    ['علم', 'علم الأب ابنه السباحة في الصيف.', 'علم', 'allama', 'عَلَّمَ', 'عَلَّمَ الْأَبُ ابْنَهُ السِّبَاحَةَ فِي الصَّيْف.'],
    ['كتب', 'في المكتبة كتب كثيرة عن التاريخ.', 'كتب', 'kutub', 'كُتُب', 'فِي الْمَكْتَبَةِ كُتُبٌ كَثِيرَةٌ عَنِ التَّارِيخ.', 'kataba', 'كَتَبَ'],
    ['كتب', 'كتب الصحفي مقالاً طويلاً عن المدينة.', 'كتب', 'kataba', 'كَتَبَ', 'كَتَبَ الصُّحُفِيُّ مَقَالًا طَوِيلًا عَنِ الْمَدِينَة.', 'kutub', 'كُتُب'],
    ['كتب', 'كتب على الباب اسم صاحب البيت.', 'كتب', 'kutiba', 'كُتِبَ', 'كُتِبَ عَلَى الْبَابِ اسْمُ صَاحِبِ الْبَيْت.'],
    ['ملك', 'زار ملك البلاد المدينة أمس.', 'ملك', 'malik', 'مَلِك', 'زَارَ مَلِكُ الْبِلَادِ الْمَدِينَةَ أَمْس.', 'mulk', 'مُلْك'],
    ['ملك', 'انتهى ملك هذه الأسرة بعد قرنين.', 'ملك', 'mulk', 'مُلْك', 'انْتَهَى مُلْكُ هَذِهِ الْأُسْرَةِ بَعْدَ قَرْنَيْن.', 'malik', 'مَلِك'],
    ['درس', 'درس أحمد الطب في القاهرة.', 'درس', 'darasa', 'دَرَسَ', 'دَرَسَ أَحْمَدُ الطِّبَّ فِي الْقَاهِرَة.'],
    ['درس', 'كان درس اليوم عن الكواكب.', 'درس', 'dars', 'دَرْس', 'كَانَ دَرْسُ الْيَوْمِ عَنِ الْكَوَاكِب.', 'darasa', 'دَرَسَ'],
    ['درس', 'درس الأستاذ طلابه قواعد النحو.', 'درس', 'darrasa', 'دَرَّسَ', 'دَرَّسَ الْأُسْتَاذُ طُلَّابَهُ قَوَاعِدَ النَّحْو.'],
    ['شعر', 'قص الحلاق شعر الطفل.', 'شعر', 'shaar', 'شَعْر', 'قَصَّ الْحَلَّاقُ شَعْرَ الطِّفْل.', 'shiir', 'شِعْر'],
    ['شعر', 'يحب جدي شعر المتنبي.', 'شعر', 'shiir', 'شِعْر', 'يُحِبُّ جَدِّي شِعْرَ الْمُتَنَبِّي.', 'shaar', 'شَعْر'],
    ['شعر', 'شعر المسافر بالتعب بعد الرحلة.', 'شعر', 'shaara', 'شَعَرَ', 'شَعَرَ الْمُسَافِرُ بِالتَّعَبِ بَعْدَ الرِّحْلَة.'],
    ['عقد', 'وقع الطرفان عقد البيع أمس.', 'عقد', 'aqd', 'عَقْد', 'وَقَّعَ الطَّرَفَانِ عَقْدَ الْبَيْعِ أَمْس.', 'iqd', 'عِقْد'],
    ['عقد', 'أهداها زوجها عقداً من اللؤلؤ.', 'عقداً', 'iqd', 'عِقْدًا', 'أَهْدَاهَا زَوْجُهَا عِقْدًا مِنَ اللُّؤْلُؤ.', 'aqd', 'عَقْدًا'],
    ['عقد', 'عقد الوزير مؤتمراً صحفياً.', 'عقد', 'aqada', 'عَقَدَ', 'عَقَدَ الْوَزِيرُ مُؤْتَمَرًا صُحُفِيًّا.'],
    ['قدم', 'كسر اللاعب قدمه في المباراة.', 'قدمه', 'qadam', 'قَدَمَه', 'كَسَرَ اللَّاعِبُ قَدَمَهُ فِي الْمُبَارَاة.'],
    ['قدم', 'قدم المدير تقريره إلى المجلس.', 'قدم', 'qaddama', 'قَدَّمَ', 'قَدَّمَ الْمُدِيرُ تَقْرِيرَهُ إِلَى الْمَجْلِس.', 'qadima', 'قَدِمَ'],
    ['قدم', 'قدم الوفد من الرياض صباح اليوم.', 'قدم', 'qadima', 'قَدِمَ', 'قَدِمَ الْوَفْدُ مِنَ الرِّيَاضِ صَبَاحَ الْيَوْم.', 'qaddama', 'قَدَّمَ'],
    ['رجل', 'دخل رجل طويل إلى المحل.', 'رجل', 'rajul', 'رَجُل', 'دَخَلَ رَجُلٌ طَوِيلٌ إِلَى الْمَحَلّ.'],
    ['رجل', 'يشكو المريض من ألم في رجله اليسرى.', 'رجله', 'rijl', 'رِجْلِه', 'يَشْكُو الْمَرِيضُ مِنْ أَلَمٍ فِي رِجْلِهِ الْيُسْرَى.', 'rajul', 'رَجُلِه'],
    ['مدرسة', 'تعمل أختي مدرسة للغة العربية.', 'مدرسة', 'mudarrisa', 'مُدَرِّسَة', 'تَعْمَلُ أُخْتِي مُدَرِّسَةً لِلُّغَةِ الْعَرَبِيَّة.'],
    ['مدرسة', 'بنيت مدرسة جديدة في الحي.', 'مدرسة', 'madrasa', 'مَدْرَسَة', 'بُنِيَتْ مَدْرَسَةٌ جَدِيدَةٌ فِي الْحَيّ.', 'mudarrisa', 'مُدَرِّسَة'],
    ['ظهر', 'ظهر القمر بعد غياب الشمس.', 'ظهر', 'zahara', 'ظَهَرَ', 'ظَهَرَ الْقَمَرُ بَعْدَ غِيَابِ الشَّمْس.'],
    ['ظهر', 'يشكو من ألم في أسفل ظهره.', 'ظهره', 'zahr', 'ظَهْرِه', 'يَشْكُو مِنْ أَلَمٍ فِي أَسْفَلِ ظَهْرِه.'],
    ['ظهر', 'نلتقي بعد صلاة الظهر.', 'الظهر', 'zuhr', 'الظُّهْر', 'نَلْتَقِي بَعْدَ صَلَاةِ الظُّهْر.', 'zahr', 'الظَّهْر'],
    ['جزر', 'تحب الأرانب أكل الجزر.', 'الجزر', 'jazar', 'الجَزَر', 'تُحِبُّ الْأَرَانِبُ أَكْلَ الْجَزَر.', 'juzur', 'الجُزُر'],
    ['جزر', 'تتكون الدولة من مئات الجزر الصغيرة.', 'الجزر', 'juzur', 'الجُزُر', 'تَتَكَوَّنُ الدَّوْلَةُ مِنْ مِئَاتِ الْجُزُرِ الصَّغِيرَة.'],
    ['قبل', 'قبل الطالب العرض فوراً.', 'قبل', 'qabila', 'قَبِلَ', 'قَبِلَ الطَّالِبُ الْعَرْضَ فَوْرًا.'],
    ['قبل', 'وصلنا قبل غروب الشمس.', 'قبل', 'qabla', 'قَبْلَ', 'وَصَلْنَا قَبْلَ غُرُوبِ الشَّمْس.'],
    ['سلم', 'صعد العامل على السلم.', 'السلم', 'sullam', 'السُّلَّم', 'صَعِدَ الْعَامِلُ عَلَى السُّلَّم.', 'silm', 'السِّلْم'],
    ['سلم', 'سلم الموظف الملف إلى مديره.', 'سلم', 'sallama', 'سَلَّمَ', 'سَلَّمَ الْمُوَظَّفُ الْمِلَفَّ إِلَى مُدِيرِه.'],
    ['دين', 'عليه دين كبير للبنك.', 'دين', 'dayn', 'دَيْن', 'عَلَيْهِ دَيْنٌ كَبِيرٌ لِلْبَنْك.', 'din', 'دِين'],
    ['دين', 'الإسلام دين التسامح.', 'دين', 'din', 'دِين', 'الْإِسْلَامُ دِينُ التَّسَامُح.'],
    ['يعد', 'يعد هذا الكتاب من أهم المراجع.', 'يعد', 'yuaddu', 'يُعَدُّ', 'يُعَدُّ هَذَا الْكِتَابُ مِنْ أَهَمِّ الْمَرَاجِع.'],
    ['يعد', 'يعد الطباخ العشاء الآن.', 'يعد', 'yuiddu', 'يُعِدُّ', 'يُعِدُّ الطَّبَّاخُ الْعَشَاءَ الْآن.'],
    ['يعد', 'يعد الأب أولاده بهدية.', 'يعد', 'yaidu', 'يَعِدُ', 'يَعِدُ الْأَبُ أَوْلَادَهُ بِهَدِيَّة.'],
    ['نشر', 'نشر الخبر في جميع الصحف.', 'نشر', 'nushira', 'نُشِرَ', 'نُشِرَ الْخَبَرُ فِي جَمِيعِ الصُّحُف.'],
    ['نشر', 'نشر الكاتب روايته الأولى.', 'نشر', 'nashara', 'نَشَرَ', 'نَشَرَ الْكَاتِبُ رِوَايَتَهُ الْأُولَى.'],
    ['ولد', 'ولد الشاعر في دمشق.', 'ولد', 'wulida', 'وُلِدَ', 'وُلِدَ الشَّاعِرُ فِي دِمَشْق.', 'walad', 'وَلَد'],
];

$only = array_filter(array_map('trim', explode(',', (string) getenv('ONLY'))));
$limit = (int) (getenv('LIMIT') ?: 0);
$voice = (string) (getenv('VOICE') ?: 'Kore');
$wants = fn (string $kind): bool => $only === [] || in_array($kind, $only, true);

// Every clip to make: [id, kind, text to speak, the sentence unmarked, the word, spelling, the reading to hear, the reading the sentence means, told to follow marks]
$clips = [];
foreach ($groups as $spelling => $readings) {
    foreach ($readings as $id => [$marked]) {
        $clips[] = ["iso-{$id}", 'iso', 'الكلمة التالية هي: '.$marked.'.', 'الكلمة التالية هي: '.$spelling.'.', $spelling, $spelling, $id, null, false];
    }
}
foreach ($sentences as $n => $s) {
    [$spelling, $plain, $word, $reading, $marked, $full] = $s;
    $one = fn (string $with): string => (string) preg_replace('/(?<!\p{L})'.preg_quote($word, '/').'(?!\p{L})/u', $with, $plain, 1);
    $clips[] = ["s{$n}-plain", 'plain', $plain, $plain, $word, $spelling, $reading, $reading, false];
    $clips[] = ["s{$n}-word", 'word', $one($marked), $plain, $word, $spelling, $reading, $reading, false];
    $clips[] = ["s{$n}-full", 'full', $full, $plain, $word, $spelling, $reading, $reading, false];
    if (isset($s[6])) {
        $clips[] = ["s{$n}-wrong", 'wrong', $one($s[7]), $plain, $word, $spelling, $s[6], $reading, false];
        $clips[] = ["s{$n}-wrongdir", 'wrong+dir', $one($s[7]), $plain, $word, $spelling, $s[6], $reading, true];
    }
}
$clips = array_values(array_filter($clips, fn (array $c): bool => $wants($c[1])));
if ($limit > 0) {
    $clips = array_slice($clips, 0, $limit);
}

$run = now()->utc()->format('Ymd-His');
$disk = Storage::disk('public');
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
mt_srand(7);

/** Which reading of the word the listener heard: its id, or "x" for none of them. */
$hear = function (string $bytes, string $sentence, string $word, array $readings) use ($base, $key): array {
    $ids = array_keys($readings);
    shuffle($ids);
    $letters = [];
    $lines = '';
    foreach ($ids as $i => $id) {
        $letters[chr(65 + $i)] = $id;
        $lines .= chr(65 + $i).') '.$readings[$id][1].' — '.$readings[$id][0]."\n";
    }
    $prompt = "Listen to this Arabic recording. The speaker reads: «{$sentence}»\n"
        ."Focus only on the word written «{$word}». Arabic spelling does not show its short vowels, so it can be said in several ways. "
        ."Which way does the speaker actually say it? Judge by the sounds you hear (the vowels after each consonant, and whether a consonant is doubled), "
        ."NOT by which reading would make sense in the sentence: the speaker may be saying the one that does not fit.\n"
        .$lines
        ."X) none of these, or it cannot be heard\n"
        .'Return only JSON: {"heard": "<the word as it sounded, in Latin letters>", "choice": "<one letter>"}';
    foreach (['gemini-3.5-flash', 'gemini-2.5-flash'] as $model) {
        $r = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$model.':generateContent', [
            'contents' => [['role' => 'user', 'parts' => [
                ['inline_data' => ['mime_type' => str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/mpeg', 'data' => base64_encode($bytes)]],
                ['text' => $prompt],
            ]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
        ]);
        $json = json_decode((string) $r->json('candidates.0.content.parts.0.text'), true);
        if ($r->ok() && is_array($json)) {
            $letter = strtoupper(trim((string) ($json['choice'] ?? 'X')));

            return ['id' => $letters[$letter] ?? 'x', 'heard' => mb_substr((string) ($json['heard'] ?? ''), 0, 30)];
        }
    }

    return ['id' => 'error', 'heard' => ''];
};

echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "; voice {$voice}; ", count($clips), " clip(s); clips at ", $disk->url('tashkeel-listen/'.$run.'/'), "\n";
$scores = [];
$seconds = 0.0;
foreach ($clips as [$id, $kind, $text, $plain, $word, $spelling, $want, $means, $told]) {
    if ($seconds > 25 * 60) {
        echo "Stopped: 25 minutes of speech made.\n";
        break;
    }
    try {
        $made = app(GeminiTtsProvider::class)->synthesize($text, 'ar', [
            'voice_name' => $voice, 'dialect' => 'ar-XA', 'performance_style' => 'neutral', 'emotion' => 'neutral',
        ] + ($told ? ['pronounce' => array_values(array_filter(explode(' ', $text), fn (string $w): bool => (bool) preg_match('/[\x{064B}-\x{0652}]/u', $w)))] : []));
        $bytes = (string) $disk->get($made['audio_path']);
        $seconds += AudioDuration::ofStoredFile($made['audio_path']) ?? 0;
        $keep = 'tashkeel-listen/'.$run.'/'.$id.'.'.pathinfo($made['audio_path'], PATHINFO_EXTENSION);
        $disk->move($made['audio_path'], $keep);
        $heard = $hear($bytes, $plain, $word, $groups[$spelling]);
    } catch (Throwable $e) {
        echo sprintf("%-14s %-9s ERROR %s\n", $id, $kind, mb_substr($e->getMessage(), 0, 160));
        $scores[$kind]['error'] = ($scores[$kind]['error'] ?? 0) + 1;

        continue;
    }
    // "meant": on a wrongly marked word, the voice said what the sentence means instead of the marks.
    $result = $heard['id'] === $want ? 'ok' : ($means !== null && $means !== $want && $heard['id'] === $means ? 'meant' : ($heard['id'] === 'error' ? 'error' : 'other'));
    $scores[$kind][$result] = ($scores[$kind][$result] ?? 0) + 1;
    echo sprintf("%-14s %-9s want %-9s heard %-9s (%s) %s\n", $id, $kind, $want, $heard['id'], $heard['heard'], $result === 'ok' ? '' : '<< '.$result.'  '.basename($keep));
}

echo "\nSpeech made: ", round($seconds / 60, 1), " minutes.\n";
foreach ($scores as $kind => $s) {
    $n = array_sum($s) - ($s['error'] ?? 0);
    echo sprintf("%-10s %3d clips: said the reading asked for %3d (%d%%)%s%s%s\n", $kind, array_sum($s), $s['ok'] ?? 0, $n > 0 ? round(100 * ($s['ok'] ?? 0) / $n) : 0,
        isset($s['meant']) ? ', said what the sentence means instead '.$s['meant'] : '', isset($s['other']) ? ', another reading or unclear '.$s['other'] : '', isset($s['error']) ? ', failed '.$s['error'] : '');
}
