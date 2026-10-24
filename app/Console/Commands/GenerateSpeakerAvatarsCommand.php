<?php

namespace App\Console\Commands;

use App\Models\Voice;
use App\Support\SpeakerAvatar;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * One illustrated avatar per speaker, all in the Listen brand style: a soft 3D
 * character with headphones on the violet-to-blue brand gradient. Each speaker
 * gets their own look (age, hair, clothing, expression) chosen from their name,
 * the speakers in the homepage samples dress for their dialect's region, and
 * the speakers added for every dialect have a look written for their place.
 *
 * Generating uses the Gemini image model (--key or GEMINI_TTS_API_KEY) and
 * writes public/images/speakers/{language}-{name}.webp.
 */
class GenerateSpeakerAvatarsCommand extends Command
{
    protected $signature = 'speakers:avatars
        {--key= : Gemini API key (default: GEMINI_TTS_API_KEY)}
        {--only=* : Only these speaker names}
        {--force : Replace avatars that already exist}
        {--dry-run : Print the character descriptions without generating}
        {--pause=2 : Seconds between requests}';

    protected $description = 'Generate the Listen speaker avatars (one per active speaker, brand style)';

    private const MODEL = 'gemini-3.1-flash-image';

    private const SIZE = 384;

    private const STYLE = 'Stylized 3D animated character portrait in the exact art style of the reference image: soft Pixar-like 3D render, '
        .'smooth skin shading, big expressive friendly eyes, soft studio lighting. Head and shoulders, centered, facing the viewer, '
        .'with a little space above the head. The background is a smooth full-bleed gradient from violet #7659FA (bottom-left) '
        .'to bright blue #3B82F6 (top-right), plain and empty: no sound waves, no bars, no shapes, no white margins, no circle, no frame, no text, no logo, no watermark. '
        .'Square image. No microphone, no hands. ';

    /**
     * Region of the dialect each homepage sample speaker uses, so their outfit fits.
     * Hero speakers also get a fixed expression.
     *
     * @var array<string, array{region?: string, look?: string}>
     */
    private const CAST = [
        // Arabic hero
        'ar:Sultan' => ['look' => 'Saudi man in his early 30s, white ghutra with a black agal, white thobe, short neat black beard, excited open-mouthed smile, eyebrows raised with enthusiasm'],
        'ar:Sumaya' => ['look' => 'Egyptian woman in her late 20s, dusty-rose hijab, cream blouse, laughing joyfully with eyes squinting'],
        'ar:Sawsan' => ['look' => 'Lebanese woman in her 30s, no hijab, shoulder-length wavy dark-brown hair, small gold earrings, mustard cardigan, amazed delighted expression, mouth slightly open'],
        'ar:Nawaf' => ['look' => 'Emirati man in his late 20s, white ghutra worn loosely without agal, white kandura, trimmed goatee, confident relaxed grin'],
        'ar:Aisha' => ['look' => 'Moroccan woman in her mid 20s, emerald-green hijab, embroidered beige top, big cheerful smile'],
        // English hero
        'en:Daisy' => ['look' => 'American woman in her late 20s, light skin, freckles, auburn hair in a messy bun, denim jacket, excited wide smile'],
        'en:Theodore' => ['look' => 'British man in his 50s, light skin, neatly combed grey hair, clean-shaven, tweed blazer over a navy turtleneck, calm charming smile'],
        'en:Miles' => ['look' => 'Australian man in his 30s, tanned light skin, sun-bleached messy blond hair, light stubble, teal surf t-shirt, laughing heartily'],
        'en:Luna' => ['look' => 'Indian woman in her mid 20s, long black hair in a side braid, small nose stud, coral kurta-style top, cheerful bright smile'],
        'en:Charlie' => ['look' => 'Nigerian man in his early 30s, dark skin, short twists hairstyle, clean-shaven, colourful patterned shirt, confident big smile'],
    ];

    public function handle(): int
    {
        $key = trim((string) ($this->option('key') ?: config('services.gemini_tts.api_key')));
        $dry = (bool) $this->option('dry-run');
        if ($key === '' && ! $dry) {
            $this->error('No Gemini API key. Pass --key or set GEMINI_TTS_API_KEY.');

            return self::FAILURE;
        }

        $only = array_filter((array) $this->option('only'));
        $regions = $this->sampleRegions();
        $reference = is_file(resource_path('avatars/style-reference.webp'))
            ? base64_encode((string) file_get_contents(resource_path('avatars/style-reference.webp')))
            : null;

        @mkdir(public_path(SpeakerAvatar::DIR), 0775, true);
        $speakers = Voice::query()->where('is_active', true)->orderBy('language')->orderBy('display_name')->get(['display_name', 'language', 'gender'])
            ->unique(fn (Voice $v): string => SpeakerAvatar::slug((string) $v->display_name, (string) $v->language));

        $failed = 0;
        foreach ($speakers as $voice) {
            $name = trim((string) $voice->display_name);
            if ($name === '' || ($only !== [] && ! in_array($name, $only, true))) {
                continue;
            }
            $target = public_path(SpeakerAvatar::path($name, (string) $voice->language));
            if (is_file($target) && ! $this->option('force') && ! $dry) {
                continue;
            }

            $look = $this->describe($name, (string) $voice->language, (string) $voice->gender, $regions);
            if ($dry) {
                $this->line("{$voice->language} {$name}: {$look}");

                continue;
            }

            try {
                $image = $this->request($key, self::STYLE.'Character: '.$look, $reference);
                $this->saveWebp($image, $target);
                $this->info("made  {$voice->language} {$name}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("fail  {$voice->language} {$name}: ".$e->getMessage());
            }
            sleep(max(0, (int) $this->option('pause')));
        }

        if ($failed > 0) {
            $this->warn("{$failed} avatar(s) failed. Run again to retry only the missing ones.");
        }

        return self::SUCCESS;
    }

    /**
     * A character description for the speaker, the same every run.
     *
     * @param  array<string, string>  $regions
     */
    private function describe(string $name, string $language, string $gender, array $regions): string
    {
        $arabic = str_starts_with($language, 'ar');
        $castKey = ($arabic ? 'ar:' : 'en:').$name;
        $headphones = $this->pick($name, 7, ['black', 'white', 'lavender', 'mint green', 'coral', 'silver', 'navy']);
        $wear = $this->pick($name, 11, ['on the head', 'on the head', 'on the head', 'resting around the neck']);
        $suffix = " Wearing {$headphones} over-ear headphones {$wear}.";

        if (isset(self::CAST[$castKey]['look'])) {
            return self::CAST[$castKey]['look'].'.'.$suffix;
        }
        // The speakers added for every dialect and accent are drawn for their place and voice.
        $own = $this->looks()[$castKey] ?? null;
        if ($own !== null) {
            return $own.'.'.$suffix;
        }

        $female = strtolower($gender) === 'female';
        $region = $regions[$castKey] ?? null;
        $age = str_replace('their', $female ? 'her' : 'his', $this->pick($name, 3, ['in their early 20s', 'in their late 20s', 'in their 30s', 'in their 40s', 'in their 50s']));
        $expression = $this->pick($name, 5, [
            'warm friendly smile', 'big joyful laugh', 'confident grin', 'calm gentle smile',
            'curious look with one eyebrow raised and a small smile', 'excited open-mouthed smile', 'soft shy smile', 'playful smirk',
        ]);
        $glasses = $this->pick($name, 13, ['', '', '', '', ', stylish round glasses', ', thin-framed glasses']);

        if ($arabic) {
            if ($female) {
                $hijab = $region === 'maghreb' || $region === 'levant'
                    ? $this->pick($name, 17, [true, false, false])
                    : $this->pick($name, 17, [true, true, true, false]);
                $color = $this->pick($name, 19, ['beige', 'black', 'dusty rose', 'navy', 'emerald green', 'lavender', 'soft white', 'mustard', 'teal', 'burgundy']);
                $hair = $hijab ? "a neatly wrapped {$color} hijab" : $this->pick($name, 23, ['long dark wavy hair', 'shoulder-length curly black hair', 'sleek dark ponytail', 'long straight brown hair']);
                $outfit = $this->pick($name, 29, ['an elegant black abaya', 'a cream knit sweater', 'a denim jacket', 'a tailored blazer', 'a pastel blouse', 'an embroidered top']);
                $nation = $this->regionPeople($region, true);

                return "{$nation} woman {$age}, {$hair}, {$outfit}{$glasses}, {$expression}.{$suffix}";
            }
            $gulf = $region === 'gulf' || ($region === null && $this->pick($name, 31, [true, false]));
            $outfit = $gulf
                ? $this->pick($name, 37, ['a white ghutra with a black agal and a white thobe', 'a red-and-white checkered shemagh with a black agal and a white thobe', 'a white ghutra worn loosely and a white kandura'])
                : $this->pick($name, 37, ['a navy hoodie', 'an olive bomber jacket over a white t-shirt', 'a light grey sweater', 'a smart dark blazer and open-collar shirt', 'a denim jacket', 'a linen shirt']);
            $hair = $gulf ? '' : $this->pick($name, 41, ['short black hair, ', 'curly dark hair, ', 'short fade haircut, ', 'wavy hair, ', 'slicked-back hair, ']);
            $beard = $this->pick($name, 43, ['a full beard', 'a short neat beard', 'light stubble', 'a moustache', 'clean-shaven']);
            $nation = $this->regionPeople($region, false);

            return "{$nation} man {$age}, {$hair}{$beard}, {$outfit}{$glasses}, {$expression}.{$suffix}";
        }

        // English speakers: an international cast, without Arabic dress.
        $people = $this->englishPeople($region ?? $this->pick($name, 47, ['us', 'gb', 'ca', 'au', 'ie', 'za', 'in', 'ng', 'jm', 'sg', 'nz', 'mixed']));
        if ($female) {
            $hair = $this->pick($name, 53, ['long straight hair', 'curly hair in a high puff', 'a short bob', 'long braids', 'wavy shoulder-length hair', 'a pixie cut', 'a low bun']);
            $outfit = $this->pick($name, 59, ['a cosy knit sweater', 'a denim jacket', 'a blazer over a white tee', 'a turtleneck', 'a flannel shirt', 'a cardigan', 'a leather jacket']);

            return "{$people} woman {$age}, {$hair}, {$outfit}{$glasses}, {$expression}.{$suffix}";
        }
        $hair = $this->pick($name, 61, ['short neat hair', 'messy curly hair', 'a buzz cut', 'a quiff', 'shoulder-length hair tied back', 'a beanie over short hair', 'swept-back hair']);
        $face = $this->pick($name, 67, ['clean-shaven', 'clean-shaven', 'light stubble', 'a trimmed moustache']);
        $outfit = $this->pick($name, 71, ['a hoodie', 'a flannel shirt', 'a crisp white shirt', 'a bomber jacket', 'a crew-neck sweater', 'a denim shirt', 'a varsity jacket']);

        return "{$people} man {$age}, {$hair}, {$face}, {$outfit}{$glasses}, {$expression}.{$suffix}";
    }

    /** @var array<string, string>|null */
    private ?array $looks = null;

    /**
     * The look of each speaker in database/seeders/data/more_speakers.php, keyed "ar:Name" / "en:Name".
     *
     * @return array<string, string>
     */
    private function looks(): array
    {
        if ($this->looks === null) {
            $this->looks = [];
            foreach ((require database_path('seeders/data/more_speakers.php'))['speakers'] as $speaker) {
                $this->looks[$speaker['language'].':'.$speaker['name']] = rtrim($speaker['look'], '.');
            }
        }

        return $this->looks;
    }

    private function regionPeople(?string $region, bool $female): string
    {
        return match ($region) {
            'gulf' => 'Gulf Arab',
            'levant' => 'Levantine Arab',
            'nile' => 'Egyptian',
            'iraq_yemen' => 'Iraqi or Yemeni Arab',
            'maghreb' => 'North African',
            'sudan' => 'Sudanese',
            default => 'Arab',
        };
    }

    private function englishPeople(string $country): string
    {
        return match (strtolower($country)) {
            'us' => 'American',
            'gb' => 'British',
            'ca' => 'Canadian',
            'au' => 'Australian',
            'ie' => 'Irish',
            'nz' => 'New Zealander',
            'za' => 'South African',
            'in' => 'Indian',
            'ng' => 'Nigerian, dark-skinned,',
            'jm' => 'Jamaican, dark-skinned,',
            'sg' => 'Singaporean, East Asian,',
            'ar' => 'Middle Eastern, in modern Western clothes,',
            default => 'mixed-heritage',
        };
    }

    /**
     * Region of the dialect each sample speaker uses, keyed "ar:Name" / "en:Name".
     *
     * @return array<string, string>
     */
    private function sampleRegions(): array
    {
        $data = require database_path('seeders/data/showcase_samples.php');
        $regions = [];
        foreach ($data['samples'] as $sample) {
            $language = $sample['language'];
            $speakers = $sample['kind'] === 'dialogue'
                ? $sample['speakers']
                : [['name' => $sample['speaker'], 'dialect' => $sample['dialect']]];
            foreach ($speakers as $speaker) {
                $dialect = (string) ($speaker['dialect'] ?? $sample['dialect']);
                $region = $language === 'ar'
                    ? (str_starts_with($dialect, 'ar-SD') ? 'sudan' : (\App\Support\ArabicDialects::ALL[$dialect]['region'] ?? null))
                    : strtolower((string) \App\Support\EnglishAccents::countryOf($dialect));
                if ($region) {
                    $regions[$language.':'.$speaker['name']] ??= $region;
                }
            }
        }

        return $regions;
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $options
     * @return T
     */
    private function pick(string $name, int $salt, array $options): mixed
    {
        return $options[crc32($salt.':'.strtolower($name)) % count($options)];
    }

    private function request(string $key, string $prompt, ?string $reference): string
    {
        $parts = [];
        if ($reference !== null) {
            $parts[] = ['inline_data' => ['mime_type' => 'image/webp', 'data' => $reference]];
        }
        $parts[] = ['text' => $prompt];
        $endpoint = rtrim((string) config('services.gemini_tts.endpoint'), '/').'/models/'.self::MODEL.':generateContent';
        $error = 'no attempt';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::timeout(120)->withHeaders(['x-goog-api-key' => $key])->post($endpoint, [
                    'contents' => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig' => ['responseModalities' => ['IMAGE']],
                ]);
            } catch (ConnectionException $e) {
                $error = $e->getMessage();
                sleep(5);

                continue;
            }
            if ($response->successful()) {
                foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
                    if (is_string($part['inlineData']['data'] ?? null)) {
                        return (string) base64_decode($part['inlineData']['data'], true);
                    }
                }
                $error = 'reply had no image';
            } else {
                $error = 'HTTP '.$response->status().': '.mb_substr((string) $response->json('error.message', $response->body()), 0, 200);
                if (in_array($response->status(), [400, 403], true)) {
                    break;
                }
            }
            sleep($response->status() === 429 ? 30 : 3);
        }

        throw new \RuntimeException($error);
    }

    private function saveWebp(string $bytes, string $target): void
    {
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            throw new \RuntimeException('Image could not be read.');
        }
        $side = min(imagesx($source), imagesy($source));
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagecopyresampled($canvas, $source, 0, 0, (int) ((imagesx($source) - $side) / 2), (int) ((imagesy($source) - $side) / 2), self::SIZE, self::SIZE, $side, $side);
        imagewebp($canvas, $target, 82);
        imagedestroy($canvas);
        imagedestroy($source);
    }
}
