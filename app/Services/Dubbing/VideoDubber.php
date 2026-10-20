<?php

namespace App\Services\Dubbing;

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioDuration;
use App\Support\AudioFormats;
use App\Support\VoiceFlag;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Dubs a short video into another language or dialect (prototype, for
 * measuring what it costs):
 *
 *   1. the speech is taken out of the video;
 *   2. one request to the text model writes out what is said, in timed
 *      segments, with a dubbed line for each in the chosen dialect, short
 *      enough to be spoken in about the same time;
 *   3. each dubbed line is voiced by the studio's own speech engine;
 *   4. each line is placed at its segment's start (sped up a little, at
 *      most MAX_TEMPO, when it runs past the next one) and the new voice
 *      replaces the speech in the video. A preview copy carries the Listen
 *      mark, which means encoding the picture again.
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

    public function __construct(private GeminiKeyManager $keys, private GeminiTtsProvider $tts) {}

    /**
     * @param  array{language: string, dialect: string, voice: string, style?: string, emotion?: string, keep_original?: float, preview?: bool, out_dir: string, name: string}  $o
     * @return array<string, mixed>
     */
    public function dub(string $video, array $o): array
    {
        $ffmpeg = AudioFormats::ffmpegPath() ?? throw new \RuntimeException('ffmpeg is not available on this server.');
        $report = ['video' => basename($video), 'dialect' => $o['dialect'], 'voice' => $o['voice'], 'input_bytes' => filesize($video)];
        $started = microtime(true);
        $tmp = sys_get_temp_dir().'/dub-'.bin2hex(random_bytes(5));
        @mkdir($tmp, 0700, true);
        $made = [];

        try {
            $report['video_seconds'] = $this->duration($ffmpeg, $video);

            // 1. The speech, small: mono, 16 kHz, 32 kbps.
            $speech = $tmp.'/speech.mp3';
            $report['extract'] = $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $video, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '32k', $speech]);

            // 2. What is said, timed, and the dubbed lines.
            $t = microtime(true);
            [$segments, $usage, $model] = $this->transcribeAndRewrite((string) file_get_contents($speech), $o['language'], $o['dialect']);
            $report['transcribe'] = ['seconds' => round(microtime(true) - $t, 2), 'model' => $model] + $usage;
            $report['segments'] = count($segments);
            if ($segments === []) {
                throw new \RuntimeException('No speech was found in the video.');
            }

            // 3. Each line voiced, with the site's own rewrite off: the lines are already in the dialect.
            $t = microtime(true);
            $rewrite = config('services.gemini_tts.dialect_rewrite');
            config(['services.gemini_tts.dialect_rewrite' => false]);
            $chars = 0;
            try {
                foreach ($segments as $i => $segment) {
                    $result = $this->tts->synthesize($segment['dubbed'], $o['language'], array_filter([
                        'voice_name' => $o['voice'],
                        'dialect' => $o['dialect'],
                        'performance_style' => $o['style'] ?? null,
                        'emotion' => $o['emotion'] ?? null,
                    ]));
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

            // 4. Placed and put back onto the picture.
            $plan = self::plan($segments, $report['video_seconds']);
            $report['fit'] = ['sped_up' => count(array_filter($plan, fn ($p) => $p['tempo'] > 1.0)), 'max_tempo' => max(array_column($plan, 'tempo')),
                'overrun_seconds' => round(array_sum(array_column($plan, 'overrun')), 2)];
            @mkdir($o['out_dir'], 0755, true);
            $clean = $o['out_dir'].'/'.$o['name'].'.mp4';
            $report['mux'] = $this->run($this->muxCommand($ffmpeg, $video, $plan, $report['video_seconds'], (float) ($o['keep_original'] ?? 0), $clean, null));
            $report['output_bytes'] = filesize($clean);
            $report['output'] = $clean;
            if ($o['preview'] ?? true) {
                $preview = $o['out_dir'].'/'.$o['name'].'-preview.mp4';
                $report['preview_encode'] = $this->run($this->muxCommand($ffmpeg, $video, $plan, $report['video_seconds'], (float) ($o['keep_original'] ?? 0), $preview, $this->markFile($ffmpeg, $tmp)));
                $report['preview_bytes'] = filesize($preview);
                $report['preview'] = $preview;
            }
            $report['lines'] = array_map(fn ($s, $p) => ['start' => $s['start'], 'end' => $s['end'], 'original' => $s['original'], 'dubbed' => $s['dubbed'],
                'voiced_seconds' => round($s['voiced_seconds'], 2), 'tempo' => $p['tempo']], $segments, $plan);
            $report['total_seconds'] = round(microtime(true) - $started, 2);

            return $report;
        } finally {
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
     * Where each voiced line goes: its segment's start, sped up (at most
     * MAX_TEMPO) when it would run into the next line or past the video's end.
     *
     * @param  array<int, array{start: float, end: float, voiced_seconds: float, audio?: string}>  $segments
     * @return array<int, array{audio: ?string, delay_ms: int, tempo: float, overrun: float}>
     */
    public static function plan(array $segments, float $videoSeconds): array
    {
        $out = [];
        $count = count($segments);
        foreach (array_values($segments) as $i => $s) {
            $start = max(0.0, (float) $s['start']);
            $next = $i + 1 < $count ? (float) array_values($segments)[$i + 1]['start'] : $videoSeconds;
            $room = max(0.3, $next - $start);
            $length = max(0.01, (float) $s['voiced_seconds']);
            $tempo = $length > $room ? min(self::MAX_TEMPO, $length / $room) : 1.0;
            $out[] = [
                'audio' => $s['audio'] ?? null,
                'delay_ms' => (int) round($start * 1000),
                'tempo' => round($tempo, 3),
                // What still runs past its room after speeding up.
                'overrun' => max(0.0, round($length / $tempo - $room, 2)),
            ];
        }

        return $out;
    }

    /**
     * One request: the speech, timed in segments, with a dubbed line for each.
     *
     * @return array{0: array<int, array{start: float, end: float, original: string, dubbed: string}>, 1: array<string, int>, 2: string}
     */
    private function transcribeAndRewrite(string $audio, string $language, string $dialect): array
    {
        $target = $language === 'ar'
            ? 'Arabic in the '.VoiceFlag::label($dialect, false).' dialect, written the way people there really speak (not Modern Standard Arabic unless that is the dialect asked for)'
            : 'English with a '.VoiceFlag::label($dialect, false).' accent and wording';
        if ($dialect === 'ar-XA') {
            $target = 'Modern Standard Arabic (Fusha), clear and natural';
        }
        $prompt = "You are dubbing a video. Listen to its audio.\n"
            ."1. Split what is said into segments at natural sentence or phrase boundaries, each 2 to 10 seconds long, "
            ."with its start and end in seconds from the beginning of the audio.\n"
            ."2. For each segment give the original words, and a dubbed line in {$target} that says the same thing naturally "
            ."and can be spoken in about the same time as the original segment: keep it as short as the original, shorter if needed.\n"
            .'Return only JSON: {"segments": [{"start": 0.0, "end": 3.2, "original": "...", "dubbed": "..."}]}. '
            .'Ignore music and noise. If nothing is spoken, return {"segments": []}.';
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
                $segments = [];
                foreach ((array) ($data['segments'] ?? []) as $s) {
                    $dubbed = trim((string) ($s['dubbed'] ?? ''));
                    if ($dubbed !== '' && is_numeric($s['start'] ?? null)) {
                        $segments[] = ['start' => (float) $s['start'], 'end' => (float) ($s['end'] ?? $s['start']), 'original' => trim((string) ($s['original'] ?? '')), 'dubbed' => $dubbed];
                    }
                }
                usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);

                return [$segments, self::usage((array) $response->json('usageMetadata')), $model];
            }
        }

        throw new \RuntimeException('The speech could not be written out ('.$last.').');
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
     * @param  array<int, array{audio: ?string, delay_ms: int, tempo: float}>  $plan
     * @return array<int, string>
     */
    private function muxCommand(string $ffmpeg, string $video, array $plan, float $seconds, float $keepOriginal, string $out, ?string $mark): array
    {
        $cmd = [$ffmpeg, '-v', 'error', '-y', '-i', $video];
        foreach ($plan as $p) {
            array_push($cmd, '-i', (string) $p['audio']);
        }
        $filters = [];
        $labels = '';
        foreach ($plan as $i => $p) {
            $tempo = $p['tempo'] > 1.0 ? 'atempo='.$p['tempo'].',' : '';
            $filters[] = '['.($i + 1).':a]aformat=sample_rates=48000:channel_layouts=stereo,'.$tempo.'adelay=delays='.$p['delay_ms'].':all=1[s'.$i.']';
            $labels .= '[s'.$i.']';
        }
        $filters[] = $labels.'amix=inputs='.count($plan).':normalize=0:dropout_transition=0[dub]';
        $audioOut = '[dub]';
        if ($keepOriginal > 0) {
            $filters[] = '[0:a]volume='.$keepOriginal.'[bg]';
            $filters[] = '[bg][dub]amix=inputs=2:normalize=0:dropout_transition=0[mix]';
            $audioOut = '[mix]';
        }
        $videoMap = ['-map', '0:v:0', '-c:v', 'copy'];
        if ($mark !== null) {
            $markInput = count($plan) + 1;
            array_push($cmd, '-i', $mark);
            $filters[] = '['.$markInput.':v]format=rgba,colorchannelmixer=aa=0.75[mk]';
            $filters[] = '[0:v][mk]overlay=W-w-28:H-h-24[v]';
            $videoMap = ['-map', '[v]', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p'];
        }

        return array_merge($cmd, ['-filter_complex', implode(';', $filters)], $videoMap,
            ['-map', $audioOut, '-c:a', 'aac', '-b:a', '160k', '-t', (string) $seconds, '-movflags', '+faststart', $out]);
    }

    /** The Listen mark for the preview: the site's own logo, made a PNG the width of a corner badge. */
    private function markFile(string $ffmpeg, string $tmp): string
    {
        $png = $tmp.'/mark.png';
        $source = public_path('images/email-logo-en.png');
        $this->run([$ffmpeg, '-v', 'error', '-y', '-i', $source, '-vf', 'scale=220:-1', $png]);

        return $png;
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
