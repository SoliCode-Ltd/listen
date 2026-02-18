<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class TtsService
{
    /**
     * @return array{audio_path:string,duration_seconds:int|null,provider:string}
     */
    public function synthesize(
        string $text,
        string $targetLanguage,
        ?string $voiceName = null,
        bool $preferFast = false,
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('TTS text is empty.');
        }

        $mode = $preferFast ? 'fast' : 'quality';
        $hash = sha1($mode.'|'.$targetLanguage.'|'.$text);

        if ($cached = $this->findCachedAudio($hash, $targetLanguage)) {
            return [
                'audio_path' => $cached,
                'duration_seconds' => $this->getDurationSeconds(Storage::disk('public')->path($cached)),
                'provider' => 'cache',
            ];
        }

        if ($preferFast) {
            $generated = $this->synthesizeWithEspeak($text, $targetLanguage);
        } else {
            $generated = $this->synthesizeWithChatterbox($text, $targetLanguage);
            if (! $generated) {
                // Safety fallback if Chatterbox service is unavailable.
                $generated = $this->synthesizeWithEspeak($text, $targetLanguage);
            }
        }

        $cachedPath = $this->storeInCache($generated['audio_path'], $hash, $targetLanguage);

        return [
            'audio_path' => $cachedPath,
            'duration_seconds' => $generated['duration_seconds'] ?? $this->getDurationSeconds(Storage::disk('public')->path($cachedPath)),
            'provider' => $generated['provider'] ?? 'unknown',
        ];
    }

    private function synthesizeWithChatterbox(string $text, string $targetLanguage): ?array
    {
        $baseUrl = rtrim((string) config('services.chatterbox.url', 'http://127.0.0.1:8123'), '/');
        $timeout = (int) config('services.chatterbox.timeout_seconds', 240);

        try {
            $response = Http::timeout($timeout)->post("{$baseUrl}/synthesize", [
                'text' => mb_substr($text, 0, 20000),
                'target_language' => $targetLanguage,
            ]);

            if (! $response->ok()) {
                return null;
            }

            $json = $response->json();
            if (! is_array($json) || empty($json['audio_file'])) {
                return null;
            }

            $audioFile = basename((string) $json['audio_file']);
            $relativePath = 'audio/'.$audioFile;
            $absolutePath = Storage::disk('public')->path($relativePath);

            // Some filesystems need a tiny delay before file visibility.
            $tries = 0;
            while (! is_file($absolutePath) && $tries < 30) {
                usleep(150000);
                $tries++;
            }

            if (! is_file($absolutePath)) {
                return null;
            }

            return [
                'audio_path' => $relativePath,
                'duration_seconds' => isset($json['duration_seconds']) ? (int) $json['duration_seconds'] : null,
                'provider' => (string) ($json['provider'] ?? 'chatterbox'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function synthesizeWithEspeak(string $text, string $targetLanguage): array
    {
        $bin = trim((string) shell_exec('command -v espeak-ng || command -v espeak || true'));
        if ($bin === '') {
            throw new \RuntimeException('No TTS provider available.');
        }

        $voice = $targetLanguage === 'ar' ? 'ar' : 'en';

        $filename = 'audio/'.now()->format('Ymd_His').'_'.Str::random(10).'.wav';
        $absolute = Storage::disk('public')->path($filename);

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }

        $process = new Process([$bin, '-v', $voice, '-w', $absolute, mb_substr($text, 0, 12000)]);
        $process->setTimeout(90);
        $process->mustRun();

        return [
            'audio_path' => $filename,
            'duration_seconds' => $this->getDurationSeconds($absolute),
            'provider' => basename($bin),
        ];
    }

    private function findCachedAudio(string $hash, string $targetLanguage): ?string
    {
        $disk = Storage::disk('public');
        $paths = [
            "audio/cache/{$targetLanguage}_{$hash}.wav",
            "audio/cache/{$targetLanguage}_{$hash}.mp3",
        ];

        foreach ($paths as $path) {
            if ($disk->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function storeInCache(string $sourcePath, string $hash, string $targetLanguage): string
    {
        $disk = Storage::disk('public');
        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'wav';
        $cachePath = "audio/cache/{$targetLanguage}_{$hash}.{$ext}";

        if (! $disk->exists($cachePath)) {
            $dir = dirname($disk->path($cachePath));
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $disk->copy($sourcePath, $cachePath);
        }

        return $cachePath;
    }

    private function getDurationSeconds(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'wav') {
            return $this->getWavDurationSeconds($path);
        }

        // Quick fallback when metadata parsing is unavailable.
        return null;
    }

    private function getWavDurationSeconds(string $path): ?int
    {
        if (! is_file($path) || filesize($path) < 44) {
            return null;
        }

        $fp = fopen($path, 'rb');
        if (! $fp) {
            return null;
        }

        $header = fread($fp, 44);
        fclose($fp);

        if (strlen($header) < 44) {
            return null;
        }

        $channels = unpack('v', substr($header, 22, 2))[1] ?? 1;
        $sampleRate = unpack('V', substr($header, 24, 4))[1] ?? 22050;
        $bitsPerSample = unpack('v', substr($header, 34, 2))[1] ?? 16;

        $dataBytes = max(0, filesize($path) - 44);
        $bytesPerSecond = max(1, (int) ($sampleRate * $channels * ($bitsPerSample / 8)));

        return (int) ceil($dataBytes / $bytesPerSecond);
    }
}
