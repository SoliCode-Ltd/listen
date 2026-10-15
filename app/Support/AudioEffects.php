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
     * The graph reads [in] (the voice at 44.1 kHz, mono, already padded by the
     * tail so a room can ring out) and ends in [out]; mono or stereo both work.
     *
     * ROOM(rt60, damping Hz, wet dB, pre-delay ms) is a stereo convolution
     * room: the voice stays in the centre, untouched, and the room opens
     * around it. Its impulse is two decorrelated noise tails (one per ear),
     * with highs that die out faster than lows as in a real room, a soft
     * onset instead of a click, and no low rumble. wet dB is the level of the
     * room against the voice: about -20 is a hint, -10 is clearly a big
     * space. Every result is brought to the same loudness at the end, so
     * switching environments never jumps in level.
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

        // Places: real rooms, from a small office to a cathedral. The voice stays
        // in front; only the room is added, kept low enough to never smear words.
        'room' => ['g-room', 'space', 'Small room', 'غرفة صغيرة', 'A short, natural room sound, like an office', 'صوت غرفة قصير وطبيعي كمكتب',
            '[in]highpass=f=70,ROOM(0.38,7000,-17,5)[out]', 0.4],
        'bathroom' => ['g-bath', 'space', 'Bathroom', 'حمّام', 'Hard tiles: bright, close reflections', 'بلاط صلب وانعكاسات قريبة لامعة',
            '[in]highpass=f=90,ROOM(0.75,9000,-11,2)[out]', 0.7],
        'hall' => ['g-hall', 'space', 'Concert hall', 'قاعة كبيرة', 'A large hall with a rich, smooth reverb', 'قاعة واسعة بصدى غني وناعم',
            '[in]highpass=f=70,ROOM(1.9,5500,-10,24)[out]', 1.6],
        'church' => ['g-church', 'space', 'Cathedral', 'كاتدرائية', 'Stone walls and a long, majestic tail', 'جدران حجرية وصدى طويل مهيب',
            '[in]highpass=f=70,ROOM(3.4,4200,-6,38)[out]', 2.0],
        'stadium' => ['g-stadium', 'space', 'Stadium', 'ملعب', 'A vast open arena around the voice', 'ساحة مفتوحة واسعة حول الصوت',
            '[in]highpass=f=120,ROOM(2.3,3600,-8,55)[out]', 1.8],
        'cave' => ['g-cave', 'space', 'Cave', 'كهف', 'Deep, dark space inside rock', 'فضاء عميق ومظلم داخل الصخر',
            '[in]highpass=f=80,ROOM(2.6,2400,-7,16)[out]', 1.8],
        'echo' => ['g-echo', 'space', 'Mountain echo', 'صدى الجبل', 'Clear repeats that bounce from side to side and fade', 'تكرار واضح يرتد بين الجهتين ويتلاشى',
            '[in]highpass=f=80,asplit=2[ed][es];[es]highpass=f=300,lowpass=f=3200,asplit=4[e1][e2][e3][e4];'
            .'[e1]adelay=420,volume=-9dB[t1];[e2]adelay=840,volume=-13dB,lowpass=f=2600[t2];[e3]adelay=1260,volume=-18dB,lowpass=f=2100[t3];[e4]adelay=1680,volume=-24dB,lowpass=f=1700[t4];'
            .'[t1][t3]amix=inputs=2:normalize=0:duration=first[el];[t2][t4]amix=inputs=2:normalize=0:duration=first[er];[el][er]join=inputs=2:channel_layout=stereo[ew];'
            .'[ed]pan=stereo|c0=c0|c1=c0[edd];[edd][ew]amix=inputs=2:normalize=0:duration=first[em];[em]ROOM(0.9,4500,-24,10)[out]', 2.1],
        'car' => ['g-car', 'space', 'Inside a car', 'داخل سيارة', 'A small padded cabin with a low road hum', 'مقصورة صغيرة مع هدير طريق خفيف',
            '[in]highpass=f=90,equalizer=f=220:t=q:w=1:g=2,lowpass=f=8000,ROOM(0.14,3800,-15,1)[v];anoisesrc=color=brown:amplitude=0.06:seed=7,lowpass=f=120,highpass=f=25[n];[v][n]amix=inputs=2:duration=first:normalize=0[out]', 0.2],
        'distant' => ['g-far', 'space', 'Far away', 'من بعيد', 'A voice from across a big room', 'صوت قادم من آخر المكان',
            '[in]highpass=f=200,lowpass=f=3200,volume=-6dB,ROOM(1.4,3000,-4,30)[out]', 1.3],
        'next_room' => ['g-wall', 'space', 'Next room', 'من الغرفة المجاورة', 'Muffled through a wall or a closed door', 'مكتوم خلف جدار أو باب مغلق',
            '[in]lowpass=f=520,lowpass=f=520,highpass=f=90,ROOM(0.5,1100,-9,8)[out]', 0.5],

        // Devices: how the voice sounds through equipment.
        'phone' => ['g-phone', 'device', 'Phone call', 'مكالمة هاتفية', 'Narrow band, like a voice on the phone', 'نطاق ضيق كصوت عبر الهاتف',
            '[in]highpass=f=320,highpass=f=320,lowpass=f=3300,lowpass=f=3300,equalizer=f=1700:t=q:w=1.2:g=4,acompressor=threshold=0.08:ratio=5:attack=4:release=90:makeup=2.2,asoftclip=type=tanh[out]', 0.0],
        'walkie' => ['g-walkie', 'device', 'Walkie-talkie', 'لاسلكي', 'Gritty two-way radio with a squelch at each end', 'لاسلكي خشن مع وشيش في البداية والنهاية',
            '[in]highpass=f=450,highpass=f=450,lowpass=f=2900,lowpass=f=2900,acompressor=threshold=0.08:ratio=6:makeup=3,asoftclip=type=hard:threshold=0.6,acrusher=bits=10:mode=log:mix=0.3[v];anoisesrc=color=white:amplitude=0.012,highpass=f=500,lowpass=f=3000[h];[v][h]amix=inputs=2:duration=first:normalize=0,lowpass=f=3300[vh];anoisesrc=color=white:amplitude=0.35:duration=0.14,highpass=f=800,lowpass=f=4000,afade=t=out:st=0.06:d=0.08[s1];anoisesrc=color=white:amplitude=0.35:duration=0.2,highpass=f=800,lowpass=f=4000,afade=t=out:st=0.1:d=0.1[s2];[s1][vh][s2]concat=n=3:v=0:a=1[out]', 0.0],
        'radio' => ['g-radio', 'device', 'Old radio', 'راديو قديم', 'A warm, slightly crunchy broadcast of the past', 'بث دافئ بخشونة خفيفة من الماضي',
            '[in]highpass=f=250,highpass=f=250,lowpass=f=4500,lowpass=f=4500,equalizer=f=1400:t=q:w=1:g=2.5,acrusher=bits=12:mode=log:aa=1:mix=0.3,acompressor=threshold=0.12:ratio=3:makeup=1.8[v];anoisesrc=color=pink:amplitude=0.004[n];[v][n]amix=inputs=2:duration=first:normalize=0[out]', 0.0],
        'tv' => ['g-tv', 'device', 'Old TV', 'تلفاز قديم', 'A small speaker in a living room', 'سماعة صغيرة في غرفة معيشة',
            '[in]highpass=f=220,highpass=f=220,lowpass=f=6000,equalizer=f=1200:t=q:w=1:g=3,acompressor=threshold=0.12:ratio=3:makeup=1.6,ROOM(0.4,4500,-17,6)[out]', 0.4],
        'megaphone' => ['g-megaphone', 'device', 'Megaphone', 'مكبر صوت', 'Loud, harsh and boxy, like a loudspeaker', 'صوت عالٍ وحاد كمكبر الصوت',
            '[in]highpass=f=600,highpass=f=600,lowpass=f=3800,equalizer=f=2000:t=q:w=1:g=6,asoftclip=type=hard:threshold=0.5,ROOM(0.6,3500,-20,12)[out]', 0.5],
        'announcement' => ['g-podium', 'device', 'Station announcement', 'نداء في محطة', 'Speakers in a big hall, like an airport call', 'سماعات في صالة واسعة كنداء المطار',
            '[in]highpass=f=350,highpass=f=350,lowpass=f=4200,equalizer=f=2200:t=q:w=1:g=2,acompressor=threshold=0.1:ratio=4:makeup=2,ROOM(2.3,3600,-7,45)[out]', 1.9],
        'tape' => ['g-tape', 'device', 'Cassette tape', 'شريط كاسيت', 'Soft hiss and the gentle wobble of old tape', 'خشخشة خفيفة واهتزاز الأشرطة القديمة',
            '[in]highpass=f=90,lowpass=f=7000,vibrato=f=0.6:d=0.05[v];anoisesrc=color=pink:amplitude=0.006[n];[v][n]amix=inputs=2:duration=first:normalize=0[out]', 0.0],
        'vinyl' => ['g-vinyl', 'device', 'Vinyl record', 'أسطوانة قديمة', 'Crackle, dust and the colour of an old record', 'طقطقة وغبار ولون الأسطوانات القديمة',
            "[in]highpass=f=120,lowpass=f=5500,vibrato=f=0.55:d=0.02[v];aevalsrc=exprs='if(lt(random(0)\\,0.0007)\\,(random(1)*2-1)*0.45\\,0)':s=44100,lowpass=f=6000[c];anoisesrc=color=pink:amplitude=0.004[n];[v][c][n]amix=inputs=3:duration=first:normalize=0[out]", 0.0],

        // Creative: characters and moods.
        'underwater' => ['g-water', 'creative', 'Underwater', 'تحت الماء', 'Muffled and wavering, as if under water', 'مكتوم ومتموج كأنه تحت الماء',
            '[in]lowpass=f=600,lowpass=f=600,chorus=0.6:0.9:50:0.4:0.25:2,ROOM(0.8,700,-8,10)[out]', 0.7],
        'robot' => ['g-bot', 'creative', 'Robot', 'روبوت', 'Flat, metallic machine voice', 'صوت آلي معدني بلا نبرة',
            "[in]afftfilt=real='hypot(re\\,im)*cos(0)':imag='hypot(re\\,im)*sin(0)':win_size=512:overlap=0.75,flanger=delay=2:depth=1:regen=40:speed=0.3,highpass=f=120,volume=6,ROOM(0.3,6000,-20,4)[out]", 0.3],
        'astronaut' => ['g-rocket', 'creative', 'Astronaut', 'رائد فضاء', 'Inside a helmet, over a space radio', 'داخل خوذة عبر لاسلكي الفضاء',
            '[in]highpass=f=350,lowpass=f=3600,ROOM(0.12,7000,-12,1),acompressor=threshold=0.1:ratio=4:makeup=2,acrusher=bits=11:mode=log:mix=0.2[out]', 0.15],
        'dream' => ['g-moon', 'creative', 'Dream / memory', 'حلم / ذكرى', 'Soft, floating and far, like a flashback', 'ناعم وطافٍ وبعيد كمشهد استرجاع',
            '[in]lowpass=f=5500,chorus=0.7:0.9:55:0.4:0.3:1.5,ROOM(2.8,3800,-7,30)[out]', 2.0],
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

    /**
     * The full ffmpeg graph: the voice resampled and padded with the tail,
     * then the effect with each ROOM(...) written out, then one loudness for
     * every environment and a true-peak ceiling.
     */
    public static function graph(string $graph, float $tail): string
    {
        $n = 0;
        $graph = (string) preg_replace_callback('/ROOM\(([\d.]+),(\d+),(-?[\d.]+),(\d+)\)/', function (array $m) use (&$n): string {
            return self::room('r'.$n++, (float) $m[1], (int) $m[2], (float) $m[3], (int) $m[4]);
        }, $graph);
        // A ROOM in the middle of a chain continues with a comma: give it a label.
        $graph = (string) preg_replace_callback('/(normalize=0:duration=first:weights=\'1 1\'),/', function (array $m) use (&$n): string {
            $label = 'c'.$n++;

            return $m[1]."[{$label}];[{$label}]";
        }, $graph);

        $head = '[0:a]aresample=44100,aformat=channel_layouts=mono'.($tail > 0 ? ',apad=pad_dur='.$tail : '').'[in];';

        return $head.$graph.';[out]aformat=channel_layouts=stereo,'.self::LOUDNESS.',aresample=44100,alimiter=limit=0.89:level=disabled[final]';
    }

    /** Every environment ends at the same loudness, the level of spoken-word streaming. */
    private const LOUDNESS = 'loudnorm=I=-16:TP=-1.5:LRA=11';

    /**
     * One stereo room: the dry voice in the centre plus a decorrelated
     * convolution tail around it. Highs decay about 2.5 times faster than
     * lows; the room's own low end is cut so it never muddies the words.
     */
    private static function room(string $id, float $rt60, int $damp, float $wetDb, int $preMs): string
    {
        $slow = round(6.91 / $rt60, 4);
        $fast = round(6.91 / ($rt60 * 0.4), 4);
        $long = round($rt60, 3);
        $short = round(max(0.05, $rt60 * 0.4), 3);
        $air = min(16000, $damp * 2);
        // Each ear gets its own noise (a different seed), so the room is wide, not a mono smear.
        $tail = fn (int $seed, float $k): string => "if(eq(n\\,0)\\,0*st({$seed}\\,{$seed}*7919)\\,0)+(random({$seed})*2-1)*exp(-{$k}*t)*(1-exp(-300*t))";

        return "asplit=2[{$id}d][{$id}s];"
            ."aevalsrc=exprs='".$tail(1, $slow).'|'.$tail(2, $slow)."':s=44100:d={$long},lowpass=f={$damp}[{$id}a];"
            ."aevalsrc=exprs='".$tail(3, $fast).'|'.$tail(4, $fast)."':s=44100:d={$short},highpass=f={$damp},lowpass=f={$air},volume=0.6[{$id}b];"
            ."[{$id}a][{$id}b]amix=inputs=2:normalize=0:duration=longest,highpass=f=160[{$id}i];"
            ."[{$id}s]adelay={$preMs},highpass=f=180,pan=stereo|c0=c0|c1=c0[{$id}p];"
            ."[{$id}p][{$id}i]afir=gtype=gn:irgain=1,highpass=f=200,volume={$wetDb}dB[{$id}w];"
            ."[{$id}d]pan=stereo|c0=c0|c1=c0[{$id}c];"
            ."[{$id}c][{$id}w]amix=inputs=2:normalize=0:duration=first:weights='1 1'";
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

        try {
            $process = new Process(array_merge(
                [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $disk->path($relativePath),
                    '-filter_complex', self::graph($graph, $tail), '-map', '[final]', '-ac', '2', '-ar', '44100'],
                $codec,
                [$absoluteTarget],
            ));
            $process->setTimeout(120);
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
