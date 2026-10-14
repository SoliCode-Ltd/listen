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
     * The graph reads [in] (the voice at 44.1 kHz, already padded by the tail
     * so a room can ring out) and ends in [out]. ROOM(rt60, damping Hz, wet,
     * pre-delay ms) is a convolution reverb whose impulse is a decaying noise
     * burst shaped like a real room: rt60 is the time it takes to fall 60 dB.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: float}>
     */
    public const ALL = [
        // Clean-up: the voice itself, no place.
        'studio' => ['g-sliders', 'clean', 'Studio polish', 'لمسة استوديو', 'Even level, clean low end, broadcast loudness', 'مستوى متوازن ونقاء وصوت بمعايير البث',
            '[in]highpass=f=70,acompressor=threshold=0.125:ratio=3:attack=10:release=150:makeup=1.5[out]', 0.0],
        'warm' => ['g-flame', 'clean', 'Warm presenter', 'دفء المذيع', 'Fuller low end and a close, intimate tone', 'قرار أعمق ونبرة قريبة دافئة',
            '[in]highpass=f=60,bass=g=4:f=140:w=0.8,treble=g=-2:f=7000,acompressor=threshold=0.14:ratio=2.5:attack=15:release=200:makeup=1.4[out]', 0.0],
        'bright' => ['g-bulb', 'clean', 'Crisp and clear', 'نقاء ووضوح', 'More presence and air, every word cuts through', 'حضور أوضح وكل كلمة مسموعة بجلاء',
            '[in]highpass=f=90,equalizer=f=3500:t=q:w=1:g=3.5,treble=g=3:f=9000,acompressor=threshold=0.14:ratio=2.5:attack=8:release=120:makeup=1.3[out]', 0.0],

        // Places: real rooms, from a small office to a cathedral.
        'room' => ['g-room', 'space', 'Small room', 'غرفة صغيرة', 'A short, natural room sound, like an office', 'صدى قصير طبيعي كغرفة مكتب',
            '[in]ROOM(0.45,6500,0.30,8)[out]', 0.45],
        'bathroom' => ['g-bath', 'space', 'Bathroom', 'حمّام', 'Hard tiles: bright, close reflections', 'بلاط صلب وانعكاسات قريبة لامعة',
            '[in]ROOM(0.9,9500,0.45,4)[out]', 0.8],
        'hall' => ['g-hall', 'space', 'Concert hall', 'قاعة كبيرة', 'A large hall with a rich, smooth reverb', 'قاعة واسعة بصدى غني وناعم',
            '[in]ROOM(2.0,5000,0.42,25)[out]', 1.6],
        'church' => ['g-church', 'space', 'Cathedral', 'كاتدرائية', 'Stone walls and a long, majestic tail', 'جدران حجرية وصدى طويل مهيب',
            '[in]ROOM(3.8,4200,0.55,40),volume=0.8[out]', 2.0],
        'stadium' => ['g-stadium', 'space', 'Stadium', 'ملعب', 'Open-air arena with a far, slapping echo', 'ساحة مفتوحة بصدى بعيد يرتد',
            '[in]aecho=0.9:0.6:320|640:0.32|0.16,ROOM(2.4,3800,0.4,60),volume=1.6[out]', 1.4],
        'cave' => ['g-cave', 'space', 'Cave', 'كهف', 'Deep, dark echoes inside rock', 'صدى عميق ومظلم داخل الصخر',
            '[in]aecho=0.85:0.75:180|420:0.35|0.22,ROOM(3.0,2600,0.55,30)[out]', 1.6],
        'echo' => ['g-echo', 'space', 'Mountain echo', 'صدى الجبل', 'Clear repeats that bounce back and fade', 'تكرار واضح يرتد ويتلاشى',
            '[in]aecho=0.85:0.8:450|900|1350:0.45|0.25|0.12,volume=1.5[out]', 0.6],
        'car' => ['g-car', 'space', 'Inside a car', 'داخل سيارة', 'A small padded cabin with a low road hum', 'مقصورة صغيرة مع هدير طريق خفيف',
            '[in]lowpass=f=7000,ROOM(0.22,3500,0.35,2)[v];anoisesrc=color=brown:amplitude=0.05,lowpass=f=140[n];[v][n]amix=inputs=2:duration=first:normalize=0[out]', 0.2],
        'distant' => ['g-far', 'space', 'Far away', 'من بعيد', 'A voice from across a big room', 'صوت قادم من آخر المكان',
            '[in]lowpass=f=2600,highpass=f=180,volume=0.55,ROOM(1.4,2800,0.9,35)[out]', 1.2],
        'next_room' => ['g-wall', 'space', 'Next room', 'من الغرفة المجاورة', 'Muffled through a wall or a closed door', 'مكتوم خلف جدار أو باب مغلق',
            '[in]lowpass=f=480,highpass=f=90,ROOM(0.6,1200,0.5,10),volume=0.75[out]', 0.5],

        // Devices: how the voice sounds through equipment.
        'phone' => ['g-phone', 'device', 'Phone call', 'مكالمة هاتفية', 'Narrow band, like a voice on the phone', 'نطاق ضيق كصوت عبر الهاتف',
            '[in]highpass=f=300,lowpass=f=3400,equalizer=f=1800:t=q:w=1.2:g=4,acompressor=threshold=0.1:ratio=4:makeup=2,asoftclip=type=tanh[out]', 0.0],
        'walkie' => ['g-walkie', 'device', 'Walkie-talkie', 'لاسلكي', 'Gritty two-way radio with a squelch at each end', 'لاسلكي خشن مع وشيش في البداية والنهاية',
            '[in]highpass=f=450,lowpass=f=2900,acompressor=threshold=0.08:ratio=6:makeup=3,asoftclip=type=hard:threshold=0.6,acrusher=bits=10:mode=log:mix=0.3[v];anoisesrc=color=white:amplitude=0.012,highpass=f=500,lowpass=f=3000[h];[v][h]amix=inputs=2:duration=first:normalize=0,lowpass=f=3300[vh];anoisesrc=color=white:amplitude=0.35:duration=0.14,highpass=f=800,lowpass=f=4000,afade=t=out:st=0.06:d=0.08[s1];anoisesrc=color=white:amplitude=0.35:duration=0.2,highpass=f=800,lowpass=f=4000,afade=t=out:st=0.1:d=0.1[s2];[s1][vh][s2]concat=n=3:v=0:a=1[out]', 0.0],
        'radio' => ['g-radio', 'device', 'Old radio', 'راديو قديم', 'A warm, slightly crunchy broadcast of the past', 'بث دافئ بخشونة خفيفة من الماضي',
            '[in]highpass=f=250,lowpass=f=4500,acrusher=bits=12:mode=log:aa=1:mix=0.35,acompressor=threshold=0.15:ratio=3:makeup=1.6[v];anoisesrc=color=pink:amplitude=0.004[n];[v][n]amix=inputs=2:duration=first:normalize=0[out]', 0.0],
        'tv' => ['g-tv', 'device', 'Old TV', 'تلفاز قديم', 'A small speaker in a living room', 'سماعة صغيرة في غرفة معيشة',
            '[in]highpass=f=200,lowpass=f=6000,equalizer=f=1200:t=q:w=1:g=3,ROOM(0.35,4500,0.18,6)[out]', 0.3],
        'megaphone' => ['g-megaphone', 'device', 'Megaphone', 'مكبر صوت', 'Loud, harsh and boxy, like a loudspeaker', 'صوت عالٍ وحاد كمكبر الصوت',
            '[in]highpass=f=600,lowpass=f=3800,equalizer=f=2000:t=q:w=1:g=6,asoftclip=type=hard:threshold=0.5,volume=1.4[out]', 0.0],
        'announcement' => ['g-podium', 'device', 'Station announcement', 'نداء في محطة', 'Speakers in a big hall, like an airport call', 'سماعات في صالة واسعة كنداء المطار',
            '[in]highpass=f=350,lowpass=f=4200,acompressor=threshold=0.1:ratio=4:makeup=2,ROOM(2.4,3500,0.5,45),volume=0.75[out]', 1.8],
        'tape' => ['g-tape', 'device', 'Cassette tape', 'شريط كاسيت', 'Soft hiss and the gentle wobble of old tape', 'خشخشة خفيفة واهتزاز الأشرطة القديمة',
            '[in]highpass=f=90,lowpass=f=7000,vibrato=f=0.6:d=0.05[v];anoisesrc=color=pink:amplitude=0.006[n];[v][n]amix=inputs=2:duration=first:normalize=0[out]', 0.0],
        'vinyl' => ['g-vinyl', 'device', 'Vinyl record', 'أسطوانة قديمة', 'Crackle, dust and the colour of an old record', 'طقطقة وغبار ولون الأسطوانات القديمة',
            "[in]highpass=f=120,lowpass=f=5500,vibrato=f=0.55:d=0.02[v];aevalsrc=exprs='if(lt(random(0)\\,0.0007)\\,(random(1)*2-1)*0.45\\,0)':s=44100,lowpass=f=6000[c];anoisesrc=color=pink:amplitude=0.004[n];[v][c][n]amix=inputs=3:duration=first:normalize=0[out]", 0.0],

        // Creative: characters and moods.
        'underwater' => ['g-water', 'creative', 'Underwater', 'تحت الماء', 'Muffled and wavering, as if under water', 'مكتوم ومتموج كأنه تحت الماء',
            '[in]lowpass=f=550,chorus=0.6:0.9:50:0.4:0.25:2,ROOM(0.8,700,0.5,10),volume=0.9[out]', 0.6],
        'robot' => ['g-bot', 'creative', 'Robot', 'روبوت', 'Flat, metallic machine voice', 'صوت آلي معدني بلا نبرة',
            "[in]afftfilt=real='hypot(re\\,im)*cos(0)':imag='hypot(re\\,im)*sin(0)':win_size=512:overlap=0.75,flanger=delay=2:depth=1:regen=40:speed=0.3,highpass=f=120,volume=6[out]", 0.0],
        'astronaut' => ['g-rocket', 'creative', 'Astronaut', 'رائد فضاء', 'Inside a helmet, over a space radio', 'داخل خوذة عبر لاسلكي الفضاء',
            '[in]highpass=f=350,lowpass=f=3600,ROOM(0.18,7000,0.35,1),acompressor=threshold=0.1:ratio=4:makeup=2,acrusher=bits=11:mode=log:mix=0.2[out]', 0.15],
        'dream' => ['g-moon', 'creative', 'Dream / memory', 'حلم / ذكرى', 'Soft, floating and far, like a flashback', 'ناعم وطافٍ وبعيد كمشهد استرجاع',
            '[in]lowpass=f=5000,chorus=0.7:0.9:55:0.4:0.3:1.5,ROOM(2.8,3500,0.6,30)[out]', 2.0],
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
     * then the effect, with ROOM(...) written out as a convolution reverb.
     */
    public static function graph(string $graph, float $tail): string
    {
        $n = 0;
        $graph = (string) preg_replace_callback('/ROOM\(([\d.]+),(\d+),([\d.]+),(\d+)\)/', function (array $m) use (&$n): string {
            $id = 'r'.$n++;
            [, $rt60, $damp, $wet, $pre] = $m;
            // exp(-6.91 t / rt60) falls by 60 dB at rt60.
            $decay = round(6.91 / (float) $rt60, 4);

            return "asplit=2[{$id}d][{$id}s];"
                ."aevalsrc=exprs='(random(0)*2-1)*exp(-{$decay}*t)':s=44100:d={$rt60},lowpass=f={$damp},highpass=f=150[{$id}i];"
                ."[{$id}s]adelay={$pre}[{$id}p];[{$id}p][{$id}i]afir=gtype=gn:irgain=1[{$id}w];"
                ."[{$id}d][{$id}w]amix=inputs=2:weights='1 {$wet}':normalize=0:duration=first";
        }, $graph);
        // A ROOM in the middle of a chain continues with a comma: give it a label.
        $graph = (string) preg_replace_callback('/(normalize=0:duration=first),/', function (array $m) use (&$n): string {
            $label = 'c'.$n++;

            return $m[1]."[{$label}];[{$label}]";
        }, $graph);

        $head = '[0:a]aresample=44100,aformat=channel_layouts=mono'.($tail > 0 ? ',apad=pad_dur='.$tail : '').'[in];';

        return $head.$graph.';[out]alimiter=limit=0.95:level=disabled[final]';
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
                    '-filter_complex', self::graph($graph, $tail), '-map', '[final]', '-ac', '1', '-ar', '44100'],
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
