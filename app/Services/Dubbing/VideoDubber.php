<?php

namespace App\Services\Dubbing;

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioDuration;
use App\Support\AudioFormats;
use App\Support\TtsStudioConfig;
use App\Support\VoiceFlag;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Dubs a short video into an Arabic dialect (or English accent):
 *
 *   1. the sound is taken out of the video;
 *   2. one request to the text model writes out what is said, in timed
 *      segments, with who says it (and whether a man or a woman), how they
 *      say it (an emotion from the studio's list and a short direction, as
 *      a voice actor would be given), and a dubbed line in the chosen
 *      dialect that fits the same time, with the laughs, sighs and pauses
 *      of the original as the studio's sound tags; it also says whether
 *      there is music or other sound behind the speech;
 *   3. each line is voiced by the studio's own speech engine: each speaker
 *      in their own voice (the member's choice for the main one, a voice
 *      of the same kind for the others) and, unless the member chose a
 *      style or an emotion for the whole video, in the original's mood;
 *   4. each line is placed at its segment's start (sped up a little, at
 *      most MAX_TEMPO, when it runs past the next one). Where the video has
 *      music or other sound, it stays, as dubbing studios keep it: the old
 *      voices are taken out of the whole track by a separation model
 *      (VoiceSeparator, run on this server while steps 2 and 3 wait on the
 *      network) and the new voices go over what is left. Where the model is
 *      not set up or busy, the old voice is taken out under each new line by
 *      filters (only the sides of a stereo mix stay there; a mono track goes
 *      quiet) and the original plays between lines. A preview copy carries
 *      the Listen mark, which means encoding the picture again.
 *
 * It returns what each step took: the model's tokens, the characters voiced,
 * the server's processing time and the files' sizes.
 */
final class VideoDubber
{
    /** The text models tried in turn, as the dialect rewrite does. */
    public const MODELS = ['gemini-3.5-flash', 'gemini-3.6-flash'];

    /** The most a line is sped up to fit its place. */
    public const MAX_TEMPO = 1.3;

    /** Sound tags a dubbed line may carry where the original has the sound (GeminiVocalTags). */
    public const SOUND_TAGS = ['laugh', 'chuckle', 'giggle', 'sigh', 'gasp', 'breath', 'whispers', 'shout', 'cry', 'sob', 'groan', 'hmm', 'short pause', 'long pause'];

    /** Seconds over which the original fades between itself and its carved copy around each line. */
    private const RAMP = 0.15;

    /**
     * A voice line into stereo at its own level in both sides: ffmpeg's own
     * conversion (and the one it slips in to make a mix's inputs agree)
     * turns each side of a mono track 3 dB down.
     */
    private const VOICE_STEREO = 'aformat=channel_layouts=mono,pan=stereo|c0=c0|c1=c0';

    /** The separation model's sample rate (resources/dubbing/separate.py). */
    private const SEPARATION_RATE = 44100;

    public function __construct(private GeminiKeyManager $keys, private GeminiTtsProvider $tts, private ?VoiceSeparator $separator = null) {}

    /**
     * $o['voice'] reads the main speaker; $o['voices'] (speech engine voices
     * by gender) gives the others theirs. An empty style or emotion follows
     * the video, line by line. $o['background'] 'replace' drops the
     * original sound entirely; otherwise music and effects stay.
     * $o['progress'] hears each step: listening, voicing (line, of lines),
     * mixing, marking.
     *
     * @param  array{language: string, dialect: string, voice: string, voices?: array<string, array<int, string>>, style?: ?string, emotion?: ?string, background?: string, preview?: bool, out_dir: string, name: string, progress?: callable(string, int, int): void}  $o
     * @return array<string, mixed>
     */
    public function dub(string $video, array $o): array
    {
        $ffmpeg = AudioFormats::ffmpegPath() ?? throw new \RuntimeException('ffmpeg is not available on this server.');
        $report = ['video' => basename($video), 'dialect' => $o['dialect'], 'voice' => $o['voice'], 'input_bytes' => filesize($video)];
        $progress = $o['progress'] ?? static function (): void {};
        $started = microtime(true);
        $tmp = sys_get_temp_dir().'/dub-'.bin2hex(random_bytes(5));
        @mkdir($tmp, 0700, true);
        $made = [];
        $separator = $this->separator ?? app(VoiceSeparator::class);
        $separating = null;

        try {
            $report['video_seconds'] = $this->duration($ffmpeg, $video);

            // 1. The sound, small: mono, 16 kHz, 32 kbps.
            $progress('listening', 0, 1);
            $speech = $tmp.'/speech.mp3';
            $report['extract'] = $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $video, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '32k', $speech]);
            // The whole sound, for the model that takes the voices out: it works while the lines are heard and voiced.
            $wantsBackground = ($o['background'] ?? 'keep') !== 'replace' && VoiceSeparator::active() && $this->probe($video)['sound'];
            if ($wantsBackground) {
                $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $video, '-vn', '-af', self::stereo($this->probe($video)['channels']).',aresample='.self::SEPARATION_RATE, '-f', 'f32le', $tmp.'/sound.f32']);
                $separating = $separator->start($tmp.'/sound.f32', $tmp.'/background.f32');
            }

            // 2. What is said, by whom and how, timed, and the dubbed lines.
            $t = microtime(true);
            $heard = $this->transcribeAndRewrite((string) file_get_contents($speech), $o['language'], $o['dialect']);
            $segments = $heard['segments'];
            $report['transcribe'] = ['seconds' => round(microtime(true) - $t, 2), 'model' => $heard['model']] + $heard['usage'];
            $report['segments'] = count($segments);
            $report['background'] = $heard['background'];
            if ($segments === []) {
                throw new \RuntimeException('No speech was found in the video.');
            }

            // 3. Each line voiced, with the site's own rewrite off: the lines are already in the dialect.
            $voices = self::assignVoices($heard['speakers'], $segments, $o['voice'], (array) ($o['voices'] ?? []));
            $report['speakers'] = array_values(array_map(fn (array $s): array => $s + ['voice' => $voices[$s['id']] ?? $o['voice']], $heard['speakers']));
            $t = microtime(true);
            $rewrite = config('services.gemini_tts.dialect_rewrite');
            config(['services.gemini_tts.dialect_rewrite' => false]);
            $chars = 0;
            try {
                foreach ($segments as $i => $segment) {
                    $progress('voicing', $i, count($segments));
                    $result = $this->tts->synthesize($segment['dubbed'], $o['language'], self::voiceConfig($segment, $voices[$segment['speaker']] ?? $o['voice'], $o));
                    $made[] = $result['audio_path'];
                    $segments[$i]['audio'] = Storage::disk('public')->path($result['audio_path']);
                    $segments[$i]['voiced_seconds'] = AudioDuration::ofStoredFile($result['audio_path']) ?? (float) ($result['duration_seconds'] ?? 0);
                    $chars += mb_strlen($segment['dubbed']);
                }
            } finally {
                config(['services.gemini_tts.dialect_rewrite' => $rewrite]);
            }
            $report['voicing'] = ['seconds' => round(microtime(true) - $t, 2), 'characters' => $chars, 'requests' => count($segments),
                'voiced_seconds' => round(array_sum(array_column($segments, 'voiced_seconds')), 2)];

            // 4. Placed and put back onto the picture, over what else the video plays.
            $progress('mixing', 0, 1);
            $plan = self::plan($segments, $report['video_seconds']);
            $report['fit'] = ['sped_up' => count(array_filter($plan, fn ($p) => $p['tempo'] > 1.0)), 'max_tempo' => max(array_column($plan, 'tempo')),
                'overrun_seconds' => round(array_sum(array_column($plan, 'overrun')), 2)];
            $keep = ($o['background'] ?? 'keep') !== 'replace' && $heard['background'] !== 'none' && $this->probe($video)['sound'];
            $report['kept_background'] = $keep;
            $background = null;
            if ($keep && $wantsBackground) {
                $t = microtime(true);
                // Busy with another dub when this one began: it waits its turn here, a few minutes at most.
                $background = $separating !== null ? $separator->finish($separating) : $separator->run($tmp.'/sound.f32', $tmp.'/background.f32', 240);
                $separating = null;
                $report['separation'] = ['method' => $background !== null ? 'model' : 'filters', 'waited_seconds' => round(microtime(true) - $t, 2)];
            } elseif ($keep) {
                $report['separation'] = ['method' => 'filters'];
            }
            @mkdir($o['out_dir'], 0755, true);
            $clean = $o['out_dir'].'/'.$o['name'].'.mp4';
            $report['mux'] = $this->run($this->muxCommand($ffmpeg, $video, $plan, $report['video_seconds'], $keep, $clean, null, $background));
            $report['output_bytes'] = filesize($clean);
            $report['output'] = $clean;
            if ($o['preview'] ?? true) {
                $progress('marking', 0, 1);
                $preview = $o['out_dir'].'/'.$o['name'].'-preview.mp4';
                $report['preview_encode'] = $this->run($this->muxCommand($ffmpeg, $video, $plan, $report['video_seconds'], $keep, $preview, $this->markFile($ffmpeg, $tmp, $video), $background));
                $report['preview_bytes'] = filesize($preview);
                $report['preview'] = $preview;
            }
            $report['lines'] = array_map(fn ($s, $p) => ['start' => $s['start'], 'end' => $s['end'], 'speaker' => $s['speaker'], 'emotion' => $s['emotion'],
                'delivery' => $s['delivery'], 'original' => $s['original'], 'dubbed' => $s['dubbed'],
                'voiced_seconds' => round($s['voiced_seconds'], 2), 'tempo' => $p['tempo']], $segments, $plan);
            $report['total_seconds'] = round(microtime(true) - $started, 2);

            return $report;
        } finally {
            if ($separating !== null) {
                $separator->cancel($separating);
            }
            foreach ($made as $path) {
                Storage::disk('public')->delete($path);
            }
            foreach (glob($tmp.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmp);
        }
    }

    /**
     * How one line is read: in its speaker's voice and the dialect; in the
     * style and emotion the member chose for the whole video, or else as the
     * original was said (its emotion and the direction the model wrote).
     *
     * @param  array{emotion: ?string, delivery: string}  $segment
     * @param  array<string, mixed>  $o
     * @return array<string, string>
     */
    public static function voiceConfig(array $segment, string $voice, array $o): array
    {
        $style = trim((string) ($o['style'] ?? ''));
        $emotion = trim((string) ($o['emotion'] ?? ''));
        $config = ['voice_name' => $voice, 'dialect' => $o['dialect']];
        if ($style !== '' || $emotion !== '') {
            return $config + array_filter(['performance_style' => $style, 'emotion' => $emotion !== '' ? $emotion : ($segment['emotion'] ?? '')]);
        }

        // Following the video: its own mood, said the way the original says it.
        $mood = (string) ($segment['emotion'] ?? '');
        $direction = trim($segment['delivery'].($mood !== '' ? ' Overall feeling: '.$mood.'.' : ''));

        return $config + array_filter(['emotion' => $mood, 'style_prompt' => $direction]);
    }

    /**
     * Which voice reads each speaker: the member's choice for the one who
     * says the most; each other speaker the next voice of their gender that
     * is not taken yet (the member's voice when there is none left).
     *
     * @param  array<int, array{id: string, gender: string}>  $speakers
     * @param  array<int, array{speaker: string, start: float, end: float}>  $segments
     * @param  array<string, array<int, string>>  $pool  speech engine voices by gender
     * @return array<string, string>
     */
    public static function assignVoices(array $speakers, array $segments, string $main, array $pool): array
    {
        $talk = [];
        foreach ($segments as $s) {
            $talk[$s['speaker']] = ($talk[$s['speaker']] ?? 0) + max(0.1, $s['end'] - $s['start']);
        }
        arsort($talk);
        $lead = (string) array_key_first($talk);
        $voices = [$lead => $main];
        $taken = [$main => true];
        foreach ($speakers as $speaker) {
            if (isset($voices[$speaker['id']])) {
                continue;
            }
            $pick = null;
            foreach ((array) ($pool[$speaker['gender']] ?? []) as $candidate) {
                if (! isset($taken[$candidate])) {
                    $pick = $candidate;
                    break;
                }
            }
            $voices[$speaker['id']] = $pick ?? $main;
            $taken[$voices[$speaker['id']]] = true;
        }

        return $voices;
    }

    /**
     * Where each voiced line goes: its segment's start, sped up (at most
     * MAX_TEMPO) when it would run into the next line or past the video's end.
     * Each also says until when its voice plays.
     *
     * @param  array<int, array{start: float, end: float, voiced_seconds: float, audio?: string}>  $segments
     * @return array<int, array{audio: ?string, delay_ms: int, tempo: float, overrun: float, from: float, until: float}>
     */
    public static function plan(array $segments, float $videoSeconds): array
    {
        $out = [];
        $segments = array_values($segments);
        $count = count($segments);
        foreach ($segments as $i => $s) {
            $start = max(0.0, (float) $s['start']);
            $next = $i + 1 < $count ? (float) $segments[$i + 1]['start'] : $videoSeconds;
            $room = max(0.3, $next - $start);
            $length = max(0.01, (float) $s['voiced_seconds']);
            $tempo = $length > $room ? min(self::MAX_TEMPO, $length / $room) : 1.0;
            $out[] = [
                'audio' => $s['audio'] ?? null,
                'delay_ms' => (int) round($start * 1000),
                'tempo' => round($tempo, 3),
                // What still runs past its room after speeding up.
                'overrun' => max(0.0, round($length / $tempo - $room, 2)),
                // The original's voice is carved out from where the line starts to where
                // the original or the new line ends, whichever is later.
                'from' => round($start, 3),
                'until' => round(max((float) ($s['end'] ?? $start), $start + $length / $tempo), 3),
            ];
        }

        return $out;
    }

    /**
     * One request: the speech, timed in segments, by speaker and mood, with a
     * dubbed line for each, and what plays behind it.
     *
     * @return array{segments: array<int, array{start: float, end: float, speaker: string, emotion: ?string, delivery: string, original: string, dubbed: string}>, speakers: array<int, array{id: string, gender: string}>, background: string, usage: array<string, int>, model: string}
     */
    private function transcribeAndRewrite(string $audio, string $language, string $dialect): array
    {
        $target = $language === 'ar'
            ? 'Arabic in the '.VoiceFlag::label($dialect, false).' dialect, written the way people there really speak (not Modern Standard Arabic unless that is the dialect asked for)'
            : 'English with a '.VoiceFlag::label($dialect, false).' accent and wording';
        if ($dialect === 'ar-XA') {
            $target = 'Modern Standard Arabic (Fusha), clear and natural';
        }
        $emotions = implode(', ', TtsStudioConfig::emotionValues());
        $tags = implode(', ', array_map(fn (string $tag): string => '<'.$tag.'>', self::SOUND_TAGS));
        $prompt = "You are preparing a professional dub of a video. Listen to its audio closely.\n"
            ."1. Split what is said into segments at natural sentence or phrase boundaries, each 2 to 10 seconds long and spoken by one person, "
            ."with its start and end in seconds from the beginning of the audio.\n"
            ."2. Tell the people apart: give each a letter (A, B, ...) and say whether they sound male or female.\n"
            ."3. For each segment say how it is spoken, as a director would brief a voice actor: \"emotion\" is the one word from this list "
            ."that fits best: {$emotions}; \"delivery\" is a short direction in English (energy, pace, loudness, attitude), at most 15 words, "
            ."for example \"bright and fast, smiling, building to a big finish\".\n"
            ."4. For each segment write a dubbed line in {$target} that says the same thing naturally, as a native speaker would say it in that moment, "
            ."and can be spoken in about the same time as the original: keep it as short as the original, shorter if needed. Keep names, brands and "
            ."numbers right. Where the speaker laughs, sighs, gasps, whispers, shouts or pauses, put the matching tag at that place in the dubbed line, "
            ."only from these: {$tags}.\n"
            ."5. Say what plays besides the speech: \"music\" if there is music, \"ambient\" for other sound (crowd, street, room, effects), \"none\" if only the voice.\n"
            .'Return only JSON: {"background": "music", "speakers": [{"id": "A", "gender": "male"}], "segments": [{"start": 0.0, "end": 3.2, "speaker": "A", '
            .'"emotion": "excited", "delivery": "...", "original": "...", "dubbed": "..."}]}. If nothing is spoken, return {"background": "music", "speakers": [], "segments": []}.';
        $base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $body = [
            'contents' => [['role' => 'user', 'parts' => [
                ['inline_data' => ['mime_type' => 'audio/mpeg', 'data' => base64_encode($audio)]],
                ['text' => $prompt],
            ]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.3, 'thinkingConfig' => ['thinkingLevel' => 'low']],
        ];

        $last = 'no key';
        foreach (self::MODELS as $model) {
            foreach ($this->keys->keysInOrder($model) as $key) {
                $response = Http::timeout(120)->withHeaders(['x-goog-api-key' => $key['key']])->post($base.'/models/'.$model.':generateContent', $body);
                if ($response->status() === 400 && str_contains(strtolower($response->body()), 'thinking')) {
                    unset($body['generationConfig']['thinkingConfig']);
                    $response = Http::timeout(120)->withHeaders(['x-goog-api-key' => $key['key']])->post($base.'/models/'.$model.':generateContent', $body);
                }
                $this->keys->recordUsage($key['name']);
                if (in_array($response->status(), [404], true)) {
                    $last = 'model '.$model.' not found';

                    continue 2;
                }
                if (! $response->ok()) {
                    $last = 'HTTP '.$response->status();

                    continue;
                }
                $raw = (string) $response->json('candidates.0.content.parts.0.text');
                $data = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $raw)), true);

                return self::readHeard(is_array($data) ? $data : []) + ['usage' => self::usage((array) $response->json('usageMetadata')), 'model' => $model];
            }
        }

        throw new \RuntimeException('The speech could not be written out ('.$last.').');
    }

    /**
     * The model's answer made safe: lines with words and a start, in order;
     * a speaker for every line and a gender for every speaker; an emotion
     * only from the studio's list; a background of music, ambient or none.
     *
     * @param  array<string, mixed>  $data
     * @return array{segments: array<int, array<string, mixed>>, speakers: array<int, array{id: string, gender: string}>, background: string}
     */
    public static function readHeard(array $data): array
    {
        $emotions = array_flip(TtsStudioConfig::emotionValues());
        $genders = [];
        foreach ((array) ($data['speakers'] ?? []) as $speaker) {
            $id = mb_substr(trim((string) ($speaker['id'] ?? '')), 0, 8);
            if ($id !== '') {
                $genders[$id] = strtolower((string) ($speaker['gender'] ?? '')) === 'female' ? 'female' : 'male';
            }
        }
        $segments = [];
        foreach ((array) ($data['segments'] ?? []) as $s) {
            $dubbed = trim((string) ($s['dubbed'] ?? ''));
            if ($dubbed === '' || ! is_numeric($s['start'] ?? null)) {
                continue;
            }
            $speaker = mb_substr(trim((string) ($s['speaker'] ?? '')), 0, 8) ?: 'A';
            $genders[$speaker] ??= 'male';
            $emotion = strtolower(trim((string) ($s['emotion'] ?? '')));
            $segments[] = [
                'start' => (float) $s['start'],
                'end' => max((float) $s['start'], (float) ($s['end'] ?? $s['start'])),
                'speaker' => $speaker,
                'emotion' => isset($emotions[$emotion]) ? $emotion : null,
                'delivery' => mb_substr(trim((string) ($s['delivery'] ?? '')), 0, 160),
                'original' => trim((string) ($s['original'] ?? '')),
                'dubbed' => $dubbed,
            ];
        }
        usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);
        $background = strtolower(trim((string) ($data['background'] ?? '')));

        return [
            'segments' => $segments,
            'speakers' => array_values(array_map(fn (string $id, string $gender): array => ['id' => $id, 'gender' => $gender], array_keys($genders), $genders)),
            // When the model does not say, the original sound is kept: dropping music is the worse mistake.
            'background' => in_array($background, ['music', 'ambient', 'none'], true) ? $background : 'ambient',
        ];
    }

    /**
     * The model's own count of what the request used.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, int>
     */
    public static function usage(array $meta): array
    {
        $audio = 0;
        foreach ((array) ($meta['promptTokensDetails'] ?? []) as $detail) {
            if (strtoupper((string) ($detail['modality'] ?? '')) === 'AUDIO') {
                $audio += (int) ($detail['tokenCount'] ?? 0);
            }
        }

        return [
            'input_tokens' => (int) ($meta['promptTokenCount'] ?? 0),
            'audio_tokens' => $audio,
            'output_tokens' => (int) ($meta['candidatesTokenCount'] ?? 0),
            'thinking_tokens' => (int) ($meta['thoughtsTokenCount'] ?? 0),
        ];
    }

    /**
     * The new voice over the picture. With $keepBackground the video's other
     * sound stays: $background, the sound with its voices taken out by the
     * model (raw float stereo), plays all through; without it, the original
     * plays between lines and under each line only its sides (the old voice,
     * in the middle, goes, and with it a mono track's music) so no old voice
     * is heard under a new one.
     *
     * @param  array<int, array{audio: ?string, delay_ms: int, tempo: float, from: float, until: float}>  $plan
     * @param  array{path: string, margin: int}|null  $mark
     * @return array<int, string>
     */
    private function muxCommand(string $ffmpeg, string $video, array $plan, float $seconds, bool $keepBackground, string $out, ?array $mark, ?string $background = null): array
    {
        $cmd = [$ffmpeg, '-v', 'error', '-y', '-i', $video];
        foreach ($plan as $p) {
            array_push($cmd, '-i', (string) $p['audio']);
        }
        $next = count($plan) + 1;
        $filters = [];
        $labels = '';
        foreach ($plan as $i => $p) {
            $tempo = $p['tempo'] > 1.0 ? 'atempo='.$p['tempo'].',' : '';
            $filters[] = '['.($i + 1).':a]'.self::VOICE_STEREO.',aresample=48000,'.$tempo.'adelay=delays='.$p['delay_ms'].':all=1[s'.$i.']';
            $labels .= '[s'.$i.']';
        }
        $filters[] = $labels.'amix=inputs='.count($plan).':normalize=0:dropout_transition=0[dub]';
        $audioOut = '[dub]';
        if ($keepBackground && $background !== null) {
            // The video's sound without its voices, all through, the new voices over it.
            array_push($cmd, '-f', 'f32le', '-ar', (string) self::SEPARATION_RATE, '-ac', '2', '-i', $background);
            $filters[] = '['.$next.':a]aformat=sample_rates=48000:channel_layouts=stereo[bg]';
            $filters[] = '[bg][dub]amix=inputs=2:normalize=0:dropout_transition=0,alimiter=limit=0.95[mix]';
            $audioOut = '[mix]';
            $next++;
        } elseif ($keepBackground) {
            $under = self::underLines($plan);
            $filters[] = '[0:a]'.self::stereo($this->probe($video)['channels']).',aresample=48000,asplit=2[oa][ob]';
            // Between lines: the original as it is.
            $filters[] = "[oa]volume='1-({$under})':eval=frame[clear]";
            // Under lines: only the sides of a stereo mix, where music spreads and a voice does not (a mono track goes quiet).
            $filters[] = "[ob]pan=stereo|c0=c0-c1|c1=c1-c0,volume='{$under}':eval=frame[carved]";
            $filters[] = '[clear][carved][dub]amix=inputs=3:normalize=0:dropout_transition=0,alimiter=limit=0.95[mix]';
            $audioOut = '[mix]';
        }
        $videoMap = ['-map', '0:v:0', '-c:v', 'copy'];
        if ($mark !== null) {
            $markInput = $next;
            array_push($cmd, '-i', $mark['path']);
            $filters[] = '['.$markInput.':v]format=rgba,colorchannelmixer=aa=0.92[mk]';
            $filters[] = '[0:v][mk]overlay=W-w-'.$mark['margin'].':H-h-'.$mark['margin'].'[v]';
            $videoMap = ['-map', '[v]', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p'];
        }

        return array_merge($cmd, ['-filter_complex', implode(';', $filters)], $videoMap,
            ['-map', $audioOut, '-c:a', 'aac', '-b:a', '160k', '-t', (string) $seconds, '-movflags', '+faststart', $out]);
    }

    /**
     * An expression of the time t, for ffmpeg's volume filter: 1 while a new
     * line plays (or the old one it replaces), 0 between lines, fading over
     * RAMP seconds either side.
     *
     * @param  array<int, array{from: float, until: float}>  $plan
     */
    public static function underLines(array $plan): string
    {
        $r = self::RAMP;
        $parts = [];
        foreach ($plan as $p) {
            $a = round($p['from'] - $r, 3);
            $b = round($p['until'] + $r, 3);
            $parts[] = "clip((t-{$a})/{$r},0,1)*clip(({$b}-t)/{$r},0,1)";
        }

        return $parts === [] ? '0' : 'min(1,'.implode('+', $parts).')';
    }

    /**
     * The Listen mark for the preview: the site's logo with a soft shadow, sized to the picture
     * (about a quarter of its short side) so it looks the same on a phone clip and a wide one.
     *
     * @return array{path: string, margin: int}
     */
    private function markFile(string $ffmpeg, string $tmp, string $video): array
    {
        $process = new Process([$ffmpeg, '-hide_banner', '-i', $video]);
        $process->setTimeout(30)->run();
        $short = preg_match('/Video:.*?, (\d{2,5})x(\d{2,5})/', $process->getErrorOutput(), $m) ? min((int) $m[1], (int) $m[2]) : 720;
        $png = $tmp.'/mark.png';
        $width = max(120, (int) round($short * 0.26 / 2) * 2);
        $this->run([$ffmpeg, '-v', 'error', '-y', '-i', public_path('images/dub-mark.png'), '-vf', 'scale='.$width.':-1:flags=lanczos', $png]);

        return ['path' => $png, 'margin' => max(10, (int) round($short * 0.03))];
    }

    /**
     * A video's sound into stereo, at its own level: a mono track the same in
     * both sides, a stereo one as it is, more channels mixed down.
     */
    public static function stereo(int $channels): string
    {
        return $channels === 1 ? 'pan=stereo|c0=c0|c1=c0' : 'aformat=channel_layouts=stereo';
    }

    /**
     * How long a video runs, whether it has a picture and a sound track, and
     * how many channels the sound has; throws when it cannot be read at all.
     *
     * @return array{seconds: float, picture: bool, sound: bool, channels: int}
     */
    public function probe(string $video): array
    {
        $ffmpeg = AudioFormats::ffmpegPath() ?? throw new \RuntimeException('ffmpeg is not available on this server.');
        $process = new Process([$ffmpeg, '-hide_banner', '-i', $video]);
        $process->setTimeout(30)->run();
        $info = $process->getErrorOutput();

        $layout = preg_match('/Stream #.*Audio:[^\n]*?Hz, ([^,\n]+)/', $info, $m) ? trim($m[1]) : 'stereo';
        $channels = match (true) {
            $layout === 'mono' => 1,
            $layout === 'stereo' => 2,
            preg_match('/^(\d+) channels/', $layout, $c) === 1 => (int) $c[1],
            default => 6,
        };

        return ['seconds' => $this->duration($ffmpeg, $video), 'picture' => preg_match('/Stream #.*Video:/', $info) === 1,
            'sound' => preg_match('/Stream #.*Audio:/', $info) === 1, 'channels' => $channels];
    }

    private function duration(string $ffmpeg, string $video): float
    {
        $process = new Process([$ffmpeg, '-hide_banner', '-i', $video]);
        $process->setTimeout(30)->run();
        if (! preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $process->getErrorOutput(), $m)) {
            throw new \RuntimeException('The video could not be read.');
        }

        return round($m[1] * 3600 + $m[2] * 60 + (float) $m[3], 2);
    }

    /**
     * Runs ffmpeg and says how long it took, on the clock and on the CPU.
     *
     * @param  array<int, string>  $cmd
     * @return array{seconds: float, cpu_seconds: float}
     */
    private function run(array $cmd): array
    {
        $before = getrusage(1);
        $t = microtime(true);
        $process = new Process($cmd);
        $process->setTimeout(600)->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed: '.mb_substr(trim($process->getErrorOutput()), 0, 300));
        }
        $after = getrusage(1);
        $cpu = ($after['ru_utime.tv_sec'] + $after['ru_stime.tv_sec'] - $before['ru_utime.tv_sec'] - $before['ru_stime.tv_sec'])
            + ($after['ru_utime.tv_usec'] + $after['ru_stime.tv_usec'] - $before['ru_utime.tv_usec'] - $before['ru_stime.tv_usec']) / 1e6;

        return ['seconds' => round(microtime(true) - $t, 2), 'cpu_seconds' => round($cpu, 2)];
    }
}
