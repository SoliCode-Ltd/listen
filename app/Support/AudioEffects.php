<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Sound environments for the whole recording (echo, hall, phone, radio …).
 *
 * The speech engines give a clean voice only, so the effect is added after,
 * with ffmpeg filters on the server. The effected copy is a new file: the
 * clean voice in the speech cache is shared and never changed. Offered only
 * when the server has ffmpeg; if a run fails, the member gets the clean voice.
 */
final class AudioEffects
{
    /**
     * key => [icon, group, English name, Arabic name, English line, Arabic line, ffmpeg filter graph, tail seconds]
     *
     * Each environment is built the way a film mix places a voice in a
     * scene, in layers: the device the voice comes through, the room it is
     * in, how far it is from the listener, and the place's own background.
     *
     * The graph reads [in] (the voice at 44.1 kHz, mono, padded by the tail)
     * and ends in [out]. Two macros:
     *
     *   ROOM(name, send dB)  the voice stays dry in the centre and the room's
     *     reflections open around it in stereo. The room is a real-sized space
     *     simulated with its surface materials (resources/audio/environments/ir,
     *     made by scripts/audio/build_environments.py): its early reflections,
     *     its tail and the way its highs die away all come from that space.
     *     0 dB is the room exactly as heard at the simulated spot; lower brings
     *     the voice closer to the microphone.
     *   BED(name, level dB)  the place's background, a seamless loop that fades
     *     in and out with the recording (resources/audio/environments/bed, at
     *     -20 LUFS before the level).
     *
     * Every result is brought to the same loudness at the end, so switching
     * environments never jumps in level.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: float}>
     */
    public const ALL = [
        // Clean-up: the voice itself, no place.
        'studio' => ['g-sliders', 'clean', 'Studio polish', 'لمسة استوديو', 'Even level, clean low end, broadcast loudness', 'مستوى متوازن ونقاء وصوت بمعايير البث',
            '[in]highpass=f=75,equalizer=f=250:t=q:w=1.2:g=-1.5,equalizer=f=4000:t=q:w=1.4:g=1.5,acompressor=threshold=0.1:ratio=2.5:attack=8:release=160:makeup=1.6,treble=g=1.5:f=11000[out]', 0.0],
        'warm' => ['g-flame', 'clean', 'Warm presenter', 'دفء المذيع', 'Fuller low end and a close, intimate tone', 'قرار أعمق ونبرة قريبة دافئة',
            '[in]highpass=f=60,bass=g=3.5:f=130:w=0.8,equalizer=f=3000:t=q:w=1.5:g=-1,treble=g=-1.5:f=8000,acompressor=threshold=0.12:ratio=2.5:attack=12:release=200:makeup=1.6[out]', 0.0],
        'bright' => ['g-bulb', 'clean', 'Crisp and clear', 'نقاء ووضوح', 'More presence and air, every word cuts through', 'حضور أوضح وكل كلمة مسموعة بجلاء',
            '[in]highpass=f=90,equalizer=f=300:t=q:w=1:g=-2,equalizer=f=3200:t=q:w=1.2:g=2.5,treble=g=3:f=10000,acompressor=threshold=0.12:ratio=2.5:attack=8:release=140:makeup=1.5[out]', 0.0],

        // Places: real rooms, from a car to a cathedral.
        'room' => ['g-room', 'space', 'Small room', 'غرفة صغيرة', 'A short, natural room sound, like an office', 'صوت غرفة قصير وطبيعي كمكتب',
            '[in]highpass=f=70,ROOM(office,-10),BED(room_tone,-20)[out]', 0.5],
        'bathroom' => ['g-bath', 'space', 'Bathroom', 'حمّام', 'Hard tiles: bright, close reflections', 'بلاط صلب وانعكاسات قريبة لامعة',
            '[in]highpass=f=90,ROOM(bathroom,-15)[out]', 1.0],
        'hall' => ['g-hall', 'space', 'Concert hall', 'قاعة كبيرة', 'A large hall with a rich, smooth reverb', 'قاعة واسعة بصدى غني وناعم',
            '[in]highpass=f=70,ROOM(hall,-4),BED(room_tone,-24)[out]', 1.6],
        'church' => ['g-church', 'space', 'Cathedral', 'كاتدرائية', 'Stone walls and a long, majestic tail', 'جدران حجرية وصدى طويل مهيب',
            '[in]highpass=f=70,ROOM(cathedral,-8),BED(room_tone,-22)[out]', 3.0],
        'stadium' => ['g-stadium', 'space', 'Stadium', 'ملعب', 'A vast open arena with the crowd around you', 'ساحة مفتوحة واسعة والجمهور من حولك',
            '[in]highpass=f=120,ROOM(stadium,-4),BED(crowd,-6)[out]', 1.4],
        'cave' => ['g-cave', 'space', 'Cave', 'كهف', 'Deep, dark rock with water dripping', 'صخر عميق ومظلم وقطرات ماء تتساقط',
            '[in]highpass=f=80,ROOM(cave,-11),BED(cave,-12)[out]', 2.2],
        'echo' => ['g-echo', 'space', 'Mountain echo', 'صدى الجبل', 'Clear repeats that bounce from side to side, in the open wind', 'تكرار واضح يرتد بين الجهتين في هواء الجبل',
            '[in]highpass=f=80,asplit=2[ed][es];[es]highpass=f=300,lowpass=f=3200,asplit=4[e1][e2][e3][e4];'
            .'[e1]adelay=420,volume=-9dB[t1];[e2]adelay=840,volume=-13dB,lowpass=f=2600[t2];[e3]adelay=1260,volume=-18dB,lowpass=f=2100[t3];[e4]adelay=1680,volume=-24dB,lowpass=f=1700[t4];'
            .'[t1][t3]amix=inputs=2:normalize=0:duration=first[el];[t2][t4]amix=inputs=2:normalize=0:duration=first[er];[el][er]join=inputs=2:channel_layout=stereo[ew];'
            .'[ed]pan=stereo|c0=c0|c1=c0[edd];[edd][ew]amix=inputs=2:normalize=0:duration=first,BED(wind,-18)[out]', 2.1],
        'car' => ['g-car', 'space', 'Inside a car', 'داخل سيارة', 'A small padded cabin on the road', 'مقصورة صغيرة على الطريق',
            '[in]highpass=f=90,equalizer=f=220:t=q:w=1:g=1.5,ROOM(car,-12),BED(car_road,-11)[out]', 0.3],
        'distant' => ['g-far', 'space', 'Far away', 'من بعيد', 'A voice from across a big room', 'صوت قادم من آخر المكان',
            '[in]highpass=f=180,lowpass=f=4200,ROOM(warehouse,-14),BED(room_tone,-16)[out]', 2.0],
        'next_room' => ['g-wall', 'space', 'Next room', 'من الغرفة المجاورة', 'Muffled through a wall or a closed door', 'مكتوم خلف جدار أو باب مغلق',
            // The wall takes the highs; a trace of the consonants still comes through, so the words can be followed.
            '[in]highpass=f=90,equalizer=f=300:t=q:w=1:g=2,lowpass=f=850,lowpass=f=1500,asplit=2[na][nb];[nb]highpass=f=1600,lowpass=f=3200,volume=-22dB[nc];[na][nc]amix=inputs=2:normalize=0:duration=first,ROOM(living_room,-16),BED(room_tone,-20)[out]', 0.5],

        // Devices: how the voice sounds through equipment.
        'phone' => ['g-phone', 'device', 'Phone call', 'مكالمة هاتفية', 'Narrow band, like a voice on the phone', 'نطاق ضيق كصوت عبر الهاتف',
            '[in]highpass=f=320,highpass=f=320,lowpass=f=3300,lowpass=f=3300,aresample=8000,acrusher=bits=8:mode=log:aa=1:mix=0.35,aresample=44100,equalizer=f=1700:t=q:w=1.2:g=4,acompressor=threshold=0.08:ratio=5:attack=4:release=90:makeup=2.2,asoftclip=type=tanh[out]', 0.0],
        'walkie' => ['g-walkie', 'device', 'Walkie-talkie', 'لاسلكي', 'Gritty two-way radio with a squelch at each end', 'لاسلكي خشن مع وشيش في البداية والنهاية',
            '[in]highpass=f=450,highpass=f=450,lowpass=f=2900,lowpass=f=2900,aresample=8000,aresample=44100,acompressor=threshold=0.08:ratio=6:makeup=3,asoftclip=type=hard:threshold=0.6,acrusher=bits=10:mode=log:mix=0.3[v];anoisesrc=color=white:amplitude=0.012,highpass=f=500,lowpass=f=3000[h];[v][h]amix=inputs=2:duration=first:normalize=0,lowpass=f=3300[vh];anoisesrc=color=white:amplitude=0.35:duration=0.14,highpass=f=800,lowpass=f=4000,afade=t=out:st=0.06:d=0.08[s1];anoisesrc=color=white:amplitude=0.35:duration=0.2,highpass=f=800,lowpass=f=4000,afade=t=out:st=0.1:d=0.1[s2];[s1][vh][s2]concat=n=3:v=0:a=1[out]', 0.0],
        'radio' => ['g-radio', 'device', 'Old radio', 'راديو قديم', 'A warm, slightly crunchy broadcast of the past', 'بث دافئ بخشونة خفيفة من الماضي',
            '[in]highpass=f=250,highpass=f=250,lowpass=f=4500,lowpass=f=4500,equalizer=f=1400:t=q:w=1:g=2.5,acrusher=bits=12:mode=log:aa=1:mix=0.3,acompressor=threshold=0.12:ratio=3:makeup=1.8,BED(static,1)[out]', 0.0],
        'tv' => ['g-tv', 'device', 'Old TV', 'تلفاز قديم', 'A small speaker in a living room', 'سماعة صغيرة في غرفة معيشة',
            '[in]highpass=f=220,highpass=f=220,lowpass=f=6000,equalizer=f=1200:t=q:w=1:g=3,acompressor=threshold=0.12:ratio=3:makeup=1.6,ROOM(living_room,-14),BED(room_tone,-20)[out]', 0.5],
        'megaphone' => ['g-megaphone', 'device', 'Megaphone', 'مكبر صوت', 'Loud, harsh and boxy, out in the street', 'صوت عالٍ وحاد كمكبر الصوت في الشارع',
            '[in]highpass=f=500,highpass=f=500,lowpass=f=4200,equalizer=f=2200:t=q:w=1:g=5,asoftclip=type=tanh,ROOM(street,-9),BED(street,-14)[out]', 0.7],
        'announcement' => ['g-podium', 'device', 'Station announcement', 'نداء في محطة', 'Speakers in a big hall, like an airport call', 'سماعات في صالة واسعة كنداء المطار',
            '[in]highpass=f=300,highpass=f=300,lowpass=f=4800,equalizer=f=2400:t=q:w=1:g=3,acompressor=threshold=0.1:ratio=4:makeup=2,ROOM(terminal,-9),BED(terminal,-12)[out]', 2.0],
        'tape' => ['g-tape', 'device', 'Cassette tape', 'شريط كاسيت', 'Soft hiss and the gentle wobble of old tape', 'خشخشة خفيفة واهتزاز الأشرطة القديمة',
            '[in]highpass=f=90,lowpass=f=7000,vibrato=f=0.6:d=0.05[v];anoisesrc=color=pink:amplitude=0.006[n];[v][n]amix=inputs=2:duration=first:normalize=0[out]', 0.0],
        'vinyl' => ['g-vinyl', 'device', 'Vinyl record', 'أسطوانة قديمة', 'Crackle, dust and the colour of an old record', 'طقطقة وغبار ولون الأسطوانات القديمة',
            "[in]highpass=f=120,lowpass=f=5500,vibrato=f=0.55:d=0.02[v];aevalsrc=exprs='if(lt(random(0)\\,0.0007)\\,(random(1)*2-1)*0.45\\,0)':s=44100,lowpass=f=6000[c];anoisesrc=color=pink:amplitude=0.004[n];[v][c][n]amix=inputs=3:duration=first:normalize=0[out]", 0.0],

        // Creative: characters and moods.
        'underwater' => ['g-water', 'creative', 'Underwater', 'تحت الماء', 'Muffled and wavering, with bubbles around', 'مكتوم ومتموج والفقاعات من حوله',
            // Water takes the highs and makes the voice waver; a trace of the consonants stays so every word can be followed.
            '[in]highpass=f=90,asplit=2[ua][ub];[ub]highpass=f=1900,lowpass=f=3600,volume=-19dB[uc];[ua]equalizer=f=420:t=q:w=1:g=3,lowpass=f=1150,lowpass=f=2100[ud];[ud][uc]amix=inputs=2:normalize=0:duration=first,vibrato=f=0.9:d=0.12,chorus=0.85:0.9:18:0.25:0.5:1.0,ROOM(helmet,-20),BED(underwater,-14)[out]', 0.3],
        'robot' => ['g-bot', 'creative', 'Robot', 'روبوت', 'Flat, metallic machine voice', 'صوت آلي معدني بلا نبرة',
            "[in]afftfilt=real='hypot(re\\,im)*cos(0)':imag='hypot(re\\,im)*sin(0)':win_size=512:overlap=0.75,flanger=delay=2:depth=1:regen=40:speed=0.3,highpass=f=120,volume=6,ROOM(office,-14)[out]", 0.4],
        'astronaut' => ['g-rocket', 'creative', 'Astronaut', 'رائد فضاء', 'Inside a helmet, over a space radio', 'داخل خوذة عبر لاسلكي الفضاء',
            '[in]highpass=f=350,lowpass=f=3600,ROOM(helmet,-16),acompressor=threshold=0.1:ratio=4:makeup=2,acrusher=bits=11:mode=log:mix=0.2,BED(static,-2)[out]', 0.2],
        'dream' => ['g-moon', 'creative', 'Dream / memory', 'حلم / ذكرى', 'Soft, floating and far, like a flashback', 'ناعم وطافٍ وبعيد كمشهد استرجاع',
            '[in]lowpass=f=6500,chorus=0.8:0.9:40:0.3:0.3:1.2,ROOM(cathedral,-8),lowpass=f=7000[out]', 2.5],
    ];

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(?string $effect): bool
    {
        return $effect !== null && isset(self::ALL[$effect]);
    }

    public static function available(): bool
    {
        return AudioFormats::serverCanConvert();
    }

    public static function label(string $effect, bool $arabic): string
    {
        return (string) (self::ALL[$effect][$arabic ? 3 : 2] ?? $effect);
    }

    /**
     * Rows for the effect select (value, label), in the page's language.
     *
     * @return array<int, array{value: string, label: string, label_ar: string}>
     */
    public static function options(): array
    {
        $rows = [];
        foreach (self::ALL as $key => $row) {
            $rows[] = ['value' => $key, 'label' => $row[2], 'label_ar' => $row[3]];
        }

        return $rows;
    }

    /**
     * @var array<string, array{en: string, ar: string}>
     */
    public const GROUPS = [
        'clean' => ['en' => 'Voice polish', 'ar' => 'تحسين الصوت'],
        'space' => ['en' => 'Places', 'ar' => 'أماكن'],
        'device' => ['en' => 'Devices', 'ar' => 'أجهزة'],
        'creative' => ['en' => 'Creative', 'ar' => 'مؤثرات إبداعية'],
    ];

    /**
     * What the picker shows for each effect.
     *
     * @return array<string, array{i: string, g: string, d: string}>
     */
    public static function forPicker(bool $arabic): array
    {
        $out = [];
        foreach (self::ALL as $key => $row) {
            $out[$key] = ['i' => $row[0], 'g' => $row[1], 'd' => $arabic ? $row[5] : $row[4]];
        }

        return $out;
    }

    /** Where the rooms (ir/) and backgrounds (bed/) live. */
    public static function assetPath(string $kind, string $name): string
    {
        return resource_path('audio/environments/'.$kind.'/'.$name.'.flac');
    }

    /**
     * The full ffmpeg graph: the voice resampled and padded with the tail,
     * the effect with each ROOM(...) and BED(...) written out, a fade at the
     * very end when the length is known, then one loudness for every
     * environment and a true-peak ceiling.
     *
     * $inputs receives the extra files the graph reads, in order: input 1
     * is the first of them (input 0 is the voice). Backgrounds loop.
     *
     * @param  array<int, array{path: string, loop: bool}>  $inputs
     */
    public static function graph(string $graph, float $tail, array &$inputs = [], float $seconds = 0.0, ?float $gainDb = null, bool $measure = false): string
    {
        $inputs = [];
        $n = 0;
        $total = $seconds > 0 ? $seconds + $tail : 0.0;
        $input = function (string $kind, string $name, bool $loop) use (&$inputs): int {
            $inputs[] = ['path' => self::assetPath($kind, $name), 'loop' => $loop];

            return count($inputs);
        };

        $graph = (string) preg_replace_callback('/ROOM\(([a-z_]+),(-?[\d.]+)\)/', function (array $m) use (&$n, $input): string {
            return self::room('r'.$n++, $input('ir', $m[1], false), (float) $m[2]);
        }, $graph);
        $graph = (string) preg_replace_callback('/BED\(([a-z_]+),([+-]?[\d.]+)\)/', function (array $m) use (&$n, $input, $total): string {
            return self::bed('b'.$n++, $input('bed', $m[1], true), (float) $m[2], $total);
        }, $graph);
        // A macro in the middle of a chain continues with a comma: give it a label.
        $graph = (string) preg_replace_callback('/(normalize=0:duration=first:weights=\'1 1\'),/', function (array $m) use (&$n): string {
            $label = 'c'.$n++;

            return $m[1]."[{$label}];[{$label}]";
        }, $graph);

        $head = '[0:a]aresample=44100,aformat=channel_layouts=mono'.($tail > 0 ? ',apad=pad_dur='.$tail : '').'[in];';
        $fade = $tail > 0 ? 0.5 : 0.05;
        $end = $total > $fade + 0.1 ? ',afade=t=out:st='.round($total - $fade, 3).':d='.$fade : '';

        // Loudness: measured on a first run, then one fixed gain for the whole
        // file (so a background never swells when the voice stops), with a
        // peak limiter. Without a measurement, a single-pass normaliser.
        $level = match (true) {
            $measure => 'ebur128=framelog=quiet',
            $gainDb !== null => 'volume='.round(max(-24.0, min(30.0, $gainDb)), 2).'dB,alimiter=limit=0.89:level=disabled',
            default => self::LOUDNESS.',aresample=44100,alimiter=limit=0.89:level=disabled',
        };

        return $head.$graph.';[out]aformat=channel_layouts=stereo'.$end.','.$level.'[final]';
    }

    /** Every environment ends at the same loudness, the level of spoken-word streaming. */
    public const TARGET_LUFS = -16.0;

    private const LOUDNESS = 'loudnorm=I=-16:TP=-1.5:LRA=11';

    /**
     * The dry voice in the centre plus the room's reflections in stereo.
     * The room's own low end is cut so it never muddies the words.
     */
    private static function room(string $id, int $ir, float $sendDb): string
    {
        return "asplit=2[{$id}d][{$id}s];"
            ."[{$ir}:a]aresample=44100,aformat=sample_fmts=fltp:channel_layouts=stereo[{$id}i];"
            ."[{$id}s]highpass=f=110,pan=stereo|c0=c0|c1=c0[{$id}p];"
            ."[{$id}p][{$id}i]afir=".self::firRaw().",highpass=f=160,volume={$sendDb}dB[{$id}w];"
            ."[{$id}d]pan=stereo|c0=c0|c1=c0[{$id}c];"
            ."[{$id}c][{$id}w]amix=inputs=2:normalize=0:duration=first:weights='1 1'";
    }

    /**
     * The place's background under the voice, faded in and out with the
     * recording. As in a film mix, it dips a few dB while someone speaks and
     * comes back in the pauses, so it never covers the words.
     */
    private static function bed(string $id, int $bed, float $levelDb, float $total): string
    {
        $fadeOut = $total > 1.2 ? ',afade=t=out:st='.round($total - 0.8, 3).':d=0.8' : '';

        return "aformat=channel_layouts=stereo,asplit=2[{$id}v][{$id}k];"
            ."[{$bed}:a]aresample=44100,aformat=sample_fmts=fltp:channel_layouts=stereo,volume={$levelDb}dB,afade=t=in:d=0.6{$fadeOut}[{$id}b];"
            ."[{$id}b][{$id}k]sidechaincompress=threshold=0.05:ratio=2.5:attack=40:release=650:knee=4[{$id}d];"
            ."[{$id}v][{$id}d]amix=inputs=2:normalize=0:duration=first:weights='1 1'";
    }

    /**
     * The rooms are stored at their true level (the direct sound would be
     * 1.0), so the convolution must not rescale them. The option for that
     * changed name between ffmpeg versions.
     */
    private static function firRaw(): string
    {
        static $option = null;
        if ($option === null) {
            $option = '';
            $ffmpeg = AudioFormats::ffmpegPath();
            if ($ffmpeg !== null) {
                try {
                    $help = new Process([$ffmpeg, '-hide_banner', '-h', 'filter=afir']);
                    $help->setTimeout(10);
                    $help->run();
                    $text = $help->getOutput().$help->getErrorOutput();
                    $option = str_contains($text, 'irnorm') ? 'irnorm=-1' : (str_contains($text, 'gtype') ? 'gtype=none' : '');
                } catch (\Throwable) {
                    $option = '';
                }
            }
        }

        return $option !== '' ? $option : 'irgain=1';
    }

    /**
     * The gain that brings this rendering to the target loudness, from a
     * first run that only measures. Null when it could not be measured.
     *
     * @param  array<int, string>  $source
     * @param  array<int, string>  $extra
     */
    private static function measureGain(array $source, array $extra, string $filter): ?float
    {
        try {
            $process = new Process(array_merge($source, ['-loglevel', 'info', '-nostats'], $extra, ['-filter_complex', $filter, '-map', '[final]', '-f', 'null', '-']));
            $process->setTimeout(300);
            $process->run();
            $log = $process->getErrorOutput();
            $log = (string) substr($log, (int) strrpos($log, 'Summary:'));
            if (! $process->isSuccessful() || preg_match('/I:\s+(-?[\d.]+) LUFS/', $log, $m) !== 1) {
                return null;
            }
            $measured = (float) $m[1];

            return $measured > -70 ? self::TARGET_LUFS - $measured : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Adds an effect to a recording on the public disk. Returns the new file's
     * path on that disk, or null when it could not be done (the caller keeps
     * the clean recording).
     */
    public static function apply(string $relativePath, string $effect): ?string
    {
        if (! self::isValid($effect)) {
            return null;
        }
        $ffmpeg = AudioFormats::ffmpegPath();
        $disk = Storage::disk('public');
        if ($ffmpeg === null || ! $disk->exists($relativePath)) {
            return null;
        }

        $extension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION)) === 'wav' ? 'wav' : 'mp3';
        $codec = $extension === 'wav' ? ['-codec:a', 'pcm_s16le'] : ['-codec:a', 'libmp3lame', '-b:a', '192k'];
        $target = 'audio/effects/'.pathinfo($relativePath, PATHINFO_FILENAME).'-'.$effect.'-'.bin2hex(random_bytes(4)).'.'.$extension;
        $absoluteTarget = $disk->path($target);
        if (! is_dir(dirname($absoluteTarget))) {
            mkdir(dirname($absoluteTarget), 0775, true);
        }
        [, , , , , , $graph, $tail] = self::ALL[$effect];
        $seconds = (float) (AudioDuration::ofStoredFile($relativePath) ?? 0.0) + ($effect === 'walkie' ? 0.34 : 0.0);
        $inputs = [];
        $filter = self::graph($graph, $tail, $inputs, $seconds);
        $extra = [];
        foreach ($inputs as $file) {
            if (! is_file($file['path'])) {
                Log::warning('Audio effect asset missing; keeping the clean recording.', ['effect' => $effect, 'file' => $file['path']]);

                return null;
            }
            $extra = array_merge($extra, $file['loop'] ? ['-stream_loop', '-1'] : [], ['-i', $file['path']]);
        }

        try {
            $source = [$ffmpeg, '-hide_banner', '-y', '-i', $disk->path($relativePath)];
            $gain = self::measureGain($source, $extra, self::graph($graph, $tail, $inputs, $seconds, null, true));
            if ($gain !== null) {
                $filter = self::graph($graph, $tail, $inputs, $seconds, $gain);
            }
            $process = new Process(array_merge(
                $source,
                ['-loglevel', 'error'],
                $extra,
                ['-filter_complex', $filter, '-map', '[final]', '-ac', '2', '-ar', '44100'],
                $codec,
                [$absoluteTarget],
            ));
            $process->setTimeout(300);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($absoluteTarget) || filesize($absoluteTarget) < 1000) {
                Log::warning('Audio effect failed; keeping the clean recording.', ['effect' => $effect, 'error' => trim($process->getErrorOutput())]);
                if (is_file($absoluteTarget)) {
                    unlink($absoluteTarget);
                }

                return null;
            }

            return $target;
        } catch (\Throwable $e) {
            Log::warning('Audio effect failed; keeping the clean recording.', ['effect' => $effect, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
