<?php
// Read-only: asks Gemini (text, with Google Search grounding, like the Gemini app)
// the bilingual prompt panel and reports whether Listen is named, which products are
// named instead and which pages Gemini read. Uses one of the live server's Gemini keys
// for text only: no audio, nothing written to the database. Never prints a key.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$keys = app(App\Services\Tts\GeminiKeyManager::class)->getAllKeys();
$key = (string) (end($keys)['key'] ?? '');
if ($key === '') {
    exit("No Gemini key on this server.\n");
}
$base = 'https://generativelanguage.googleapis.com/v1beta';

// The newest text models this key can use.
$models = [];
$page = '';
do {
    $r = Http::timeout(30)->get("$base/models", ['key' => $key, 'pageSize' => 200, 'pageToken' => $page]);
    foreach ((array) $r->json('models') as $m) {
        $name = (string) ($m['name'] ?? '');
        if (! in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) {
            continue;
        }
        if (! preg_match('#^models/gemini-(\d+(?:\.\d+)?)-(flash|pro)$#', $name, $mm)) {
            continue;
        }
        $models[$mm[2]][$name] = (float) $mm[1];
    }
    $page = (string) $r->json('nextPageToken');
} while ($page !== '');
$pick = function (string $kind) use ($models): ?string {
    $list = $models[$kind] ?? [];
    arsort($list);

    return array_key_first($list);
};
$flash = $pick('flash');
$pro = $pick('pro');
echo 'Now (UTC): ', now()->toDateTimeString(), "\n";
echo 'Models: flash=', $flash ?? 'none', ' pro=', $pro ?? 'none', "\n";
if ($flash === null) {
    exit("No flash text model found.\n");
}

$en = [
    'best Arabic text to speech',
    'best AI voice generator for Arabic dialects',
    'Arabic text to speech with Egyptian dialect',
    'Gulf Arabic text to speech for ads',
    'professional Arabic voiceover AI with emotions',
    'Arabic text to speech API for developers',
    'AI voice cloning in Arabic dialects',
    'Arabic AI dubbing tool for YouTube videos',
    'text to speech that can laugh and whisper in Arabic',
    'two-voice Arabic podcast AI generator',
    'Listen AI voice studio listenai.studio review',
    'lahjati vs listenai.studio',
    'alternatives to lahjati for Arabic TTS',
    'which Arabic TTS has the most dialects',
    'Arabic audiobook narration AI with Levantine dialect',
    'English text to speech with British and Australian accents and emotions',
    'Arabic TTS for e-learning with Modern Standard Arabic',
    'Saudi dialect AI voice for TikTok ads',
    'Moroccan Darija text to speech',
    'is listenai.studio good for professional use',
];
$ar = [
    'أفضل موقع تحويل النص إلى صوت باللهجات',
    'أفضل برنامج تعليق صوتي بالذكاء الاصطناعي عربي',
    'تحويل النص إلى صوت باللهجة المصرية',
    'تحويل النص إلى صوت باللهجة السعودية للإعلانات',
    'موقع تعليق صوتي احترافي بالعربي فيه مشاعر',
    'واجهة برمجية لتحويل النص العربي إلى صوت',
    'استنساخ الصوت باللهجات العربية',
    'دبلجة فيديو يوتيوب بالذكاء الاصطناعي بالعربي',
    'موقع تحويل النص لصوت يضحك ويهمس',
    'عمل بودكاست بصوتين بالذكاء الاصطناعي بالعربي',
    'منصة إسمع لتحويل النص إلى صوت رأي',
    'الفرق بين لهجاتي وإسمع',
    'بديل لهجاتي لتحويل النص إلى صوت',
    'أكثر موقع فيه لهجات عربية لتحويل النص إلى صوت',
    'صوت ذكاء اصطناعي باللهجة الشامية للكتب الصوتية',
    'تحويل النص إلى صوت بالفصحى للتعليم',
    'تحويل النص إلى صوت باللهجة المغربية الدارجة',
    'تحويل النص إلى صوت باللهجة الخليجية',
    'أفضل أداة ذكاء اصطناعي للتعليق الصوتي للإعلانات',
    'هل إسمع مناسب للاستخدام الاحترافي',
];

$products = [
    'Lahajati' => '/lahajati|lahjati|lahjaty|لهجاتي/iu',
    'Nabarati' => '/nabarati|nabraty|نبرتي|نبراتي/iu',
    'ElevenLabs' => '/eleven\s?labs/iu',
    'Munsit' => '/munsit|منصت/iu',
    'Narakeet' => '/narakeet/iu',
    'Maestra' => '/maestra/iu',
    'Play.ht' => '/play\.?ht/iu',
    'Azure' => '/azure|microsoft/iu',
    'Google TTS' => '/google (cloud )?(text|tts)|google cloud/iu',
    'Amazon Polly' => '/polly/iu',
    'Murf' => '/\bmurf/iu',
    'Speechify' => '/speechify/iu',
    'CapCut' => '/capcut/iu',
    'SpeechGen' => '/speechgen/iu',
    'TTSMaker' => '/ttsmaker/iu',
    'Natural Readers' => '/natural ?reader/iu',
    'Soutrise' => '/soutrise/iu',
    'ArabyVoice' => '/araby ?voice/iu',
    'Darijat' => '/darijat/iu',
    'Sawt' => '/trysawt|\bsawt\b/iu',
    'Kanari/Audar/Navid' => '/kanari|audar|navid/iu',
    'Wavel' => '/wavel/iu',
    'Voicemaker' => '/voicemaker/iu',
    'HeyGen' => '/heygen/iu',
    'Rask' => '/rask/iu',
];
// Listen itself: the domain, the Arabic name with hamza, or "Listen" used as a product name.
$listen = '/listenai\.studio|إسمع|\bListen\b(?=\s*(?:\(|AI|Studio|voice|,|\.|:|–|-))|منصة اسمع|موقع اسمع/u';

$ask = function (string $model, string $prompt, bool $search) use ($base, $key): array {
    $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]]];
    if ($search) {
        $body['tools'] = [['google_search' => (object) []]];
    }
    for ($try = 0; $try < 3; $try++) {
        $r = Http::timeout(120)->post("$base/$model:generateContent?key=".urlencode($key), $body);
        if ($r->successful()) {
            break;
        }
        sleep(5 * ($try + 1));
    }
    if (! $r->successful()) {
        return ['error' => 'HTTP '.$r->status().' '.mb_substr((string) $r->body(), 0, 160)];
    }
    $c = $r->json('candidates.0') ?? [];
    $text = implode('', array_map(fn ($p) => (string) ($p['text'] ?? ''), (array) ($c['content']['parts'] ?? [])));
    $sources = [];
    foreach ((array) ($c['groundingMetadata']['groundingChunks'] ?? []) as $g) {
        $sources[] = (string) ($g['web']['title'] ?? '?');
    }

    return ['text' => $text, 'sources' => array_values(array_unique($sources)), 'queries' => (array) ($c['groundingMetadata']['webSearchQueries'] ?? [])];
};

$tally = ['asked' => 0, 'listen' => 0, 'products' => [], 'sources' => []];
$run = function (string $label, string $model, array $prompts, bool $search) use ($ask, $products, $listen, &$tally): void {
    echo "\n== $label ($model, ".($search ? 'with Google Search' : 'no search, model memory only').") ==\n";
    foreach ($prompts as $i => $prompt) {
        $a = $ask($model, $prompt, $search);
        $tally['asked']++;
        echo "\n[".($i + 1)."] $prompt\n";
        if (isset($a['error'])) {
            echo "  ERROR: {$a['error']}\n";

            continue;
        }
        $named = [];
        foreach ($products as $name => $re) {
            if (preg_match($re, $a['text'])) {
                $named[] = $name;
                $tally['products'][$name] = ($tally['products'][$name] ?? 0) + 1;
            }
        }
        $hit = preg_match_all($listen, $a['text'], $m, PREG_OFFSET_CAPTURE);
        if ($hit) {
            $tally['listen']++;
        }
        foreach ($a['sources'] as $s) {
            $tally['sources'][$s] = ($tally['sources'][$s] ?? 0) + 1;
        }
        echo '  Listen named: ', $hit ? 'YES' : 'no', "\n";
        echo '  Products named: ', $named ? implode(', ', $named) : '(none of the list)', "\n";
        echo '  Pages read: ', $a['sources'] ? implode(', ', $a['sources']) : '(none)', "\n";
        if ($a['queries']) {
            echo '  Searched for: ', implode(' | ', array_slice($a['queries'], 0, 6)), "\n";
        }
        if ($hit) {
            foreach (array_slice($m[0], 0, 3) as [$word, $at]) {
                $start = max(0, (int) $at - 140);
                echo '  > ', preg_replace('/\s+/u', ' ', mb_strcut($a['text'], $start, 360)), "\n";
            }
        }
        echo '  Answer starts: ', preg_replace('/\s+/u', ' ', mb_substr($a['text'], 0, 420)), "\n";
    }
};

$run('English, Gemini Flash', $flash, $en, true);
$run('Arabic, Gemini Flash', $flash, $ar, true);
$core = [$en[0], $ar[0], $en[10], $ar[10], $ar[11]];
if ($pro !== null) {
    $run('Core questions, Gemini Pro', $pro, $core, true);
}
$run('Core questions, model memory', $flash, $core, false);

echo "\n== Totals ==\n";
echo "Questions asked: {$tally['asked']}; answers naming Listen: {$tally['listen']}\n";
arsort($tally['products']);
echo "Products named (answers): \n";
foreach ($tally['products'] as $n => $c) {
    echo "  $n: $c\n";
}
arsort($tally['sources']);
echo "Pages Gemini read most (by site):\n";
foreach (array_slice($tally['sources'], 0, 40, true) as $n => $c) {
    echo "  $n: $c\n";
}
