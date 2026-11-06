<?php

namespace App\Support\Agents;

use App\Models\AgentConversation;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Storage;

/**
 * Each call's recording: the caller and the agent together, as the caller's browser heard them.
 * The call client (public/js/agent-call.js) records the call's own sound (its microphone and the
 * agent's voice, already in the browser), in small compressed parts sent every 10 s and at the
 * end; nothing more is asked of the voice service, so it costs no more there. The parts join
 * into one file on Listen's own disk (private, never public), which the admin plays on the call's
 * page. Kept KEEP_DAYS days.
 */
final class CallRecordings
{
    public const SETTING = 'agents_record_calls';

    public const DISK = 'local';

    public const KEEP_DAYS = 90;

    /** One part: 10 s of compressed sound is about 40 KB; more is not from the call client. */
    public const MAX_PART_BYTES = 400_000;

    /** A whole call (an hour at the client's 32 kbit/s is about 15 MB). */
    public const MAX_BYTES = 40_000_000;

    /** A part may still come this long after the call ended (the last one is sent as the call closes). */
    public const LATE_SECONDS = 300;

    /** The kinds of sound the browsers record in, and their file endings. */
    public const TYPES = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a'];

    public static function enabled(): bool
    {
        return self::ready() && (string) SiteSetting::get(self::SETTING, '1') !== '0';
    }

    /** The recording columns exist (not in the minute between a deploy and its migration). */
    public static function ready(): bool
    {
        return once(fn (): bool => rescue(fn (): bool => \Illuminate\Support\Facades\Schema::hasColumn('agent_conversations', 'recording_path'), false, false));
    }

    /** "audio/webm" for "audio/webm;codecs=opus"; null for anything else. */
    public static function type(?string $type): ?string
    {
        $base = strtolower(trim(explode(';', (string) $type)[0]));

        return isset(self::TYPES[$base]) ? $base : null;
    }

    /**
     * Adds one part of a call's recording, in order. A part sent twice is taken once; a part
     * out of order, too big, too late or of another kind is refused.
     *
     * @return string 'kept', 'again' (already had it) or the reason it was refused
     */
    public static function add(AgentConversation $call, int $part, string $bytes, ?string $type): string
    {
        $type = self::type($type);
        if (! self::enabled()) {
            return 'off';
        }
        if ($type === null || $bytes === '' || strlen($bytes) > self::MAX_PART_BYTES) {
            return 'bad';
        }
        if ($call->ended_at && $call->ended_at->lt(now()->subSeconds(self::LATE_SECONDS))) {
            return 'late';
        }
        $have = (int) $call->recording_parts;
        if ($part < $have) {
            return 'again';
        }
        if ($part > $have) {
            return 'order';
        }
        if ($part > 0 && $call->recording_type !== $type) {
            return 'bad';
        }
        if ((int) $call->recording_bytes + strlen($bytes) > self::MAX_BYTES) {
            return 'full';
        }
        // A recording starts as one: webm and ogg files open with their own marks, mp4 with "ftyp".
        if ($part === 0 && ! self::looksRight($type, $bytes)) {
            return 'bad';
        }
        $path = $call->recording_path ?: 'agent-recordings/'.$call->started_at->format('Y/m').'/'.$call->public_id.'.'.self::TYPES[$type];
        $disk = Storage::disk(self::DISK);
        if ($part === 0) {
            $disk->put($path, $bytes);
        } else {
            $full = $disk->path($path);
            if (! is_file($full) || file_put_contents($full, $bytes, FILE_APPEND | LOCK_EX) === false) {
                return 'bad';
            }
        }
        $call->forceFill([
            'recording_path' => $path,
            'recording_type' => $type,
            'recording_parts' => $have + 1,
            'recording_bytes' => (int) $call->recording_bytes + strlen($bytes),
        ])->save();

        return 'kept';
    }

    /**
     * The call's recording is whole (its last part came): packed again without re-encoding, so the
     * file carries its length and the admin's player can show it and jump to any point (a browser's
     * recording has neither). Left as it is when the server has no ffmpeg or the packing fails.
     */
    public static function finish(AgentConversation $call): bool
    {
        $file = self::file($call);
        $ffmpeg = \App\Support\AudioFormats::ffmpegPath();
        if ($file === null || $ffmpeg === null) {
            return false;
        }
        $format = match ($call->recording_type) {
            'audio/webm' => ['-f', 'webm'],
            'audio/ogg' => ['-f', 'ogg'],
            'audio/mp4' => ['-f', 'mp4', '-movflags', '+faststart'],
            default => null,
        };
        if ($format === null) {
            return false;
        }
        $tmp = $file.'.packing';
        try {
            $process = new \Symfony\Component\Process\Process(array_merge([$ffmpeg, '-v', 'error', '-y', '-i', $file, '-c', 'copy'], $format, [$tmp]));
            $process->setTimeout(60);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($tmp) || filesize($tmp) < 64) {
                @unlink($tmp);

                return false;
            }
            rename($tmp, $file);
            $call->forceFill(['recording_bytes' => (int) filesize($file)])->save();

            return true;
        } catch (\Throwable) {
            @unlink($tmp);

            return false;
        }
    }

    private static function looksRight(string $type, string $bytes): bool
    {
        return match ($type) {
            'audio/webm' => str_starts_with($bytes, "\x1A\x45\xDF\xA3"),
            'audio/ogg' => str_starts_with($bytes, 'OggS'),
            'audio/mp4' => substr($bytes, 4, 4) === 'ftyp',
            default => false,
        };
    }

    public static function has(AgentConversation $call): bool
    {
        return filled($call->recording_path) && Storage::disk(self::DISK)->exists((string) $call->recording_path);
    }

    /** The recording's file on the disk (for the admin to play), or null. */
    public static function file(AgentConversation $call): ?string
    {
        return self::has($call) ? Storage::disk(self::DISK)->path((string) $call->recording_path) : null;
    }

    public static function forget(AgentConversation $call): void
    {
        if (filled($call->recording_path)) {
            Storage::disk(self::DISK)->delete((string) $call->recording_path);
        }
    }

    /** Recordings older than KEEP_DAYS days go; the call's words stay. @return int how many went */
    public static function prune(): int
    {
        $gone = 0;
        AgentConversation::query()->whereNotNull('recording_path')->where('started_at', '<', now()->subDays(self::KEEP_DAYS))
            ->select(['id', 'recording_path'])->chunkById(500, function ($calls) use (&$gone): void {
                foreach ($calls as $call) {
                    Storage::disk(self::DISK)->delete((string) $call->recording_path);
                    $gone++;
                }
                AgentConversation::query()->whereIn('id', $calls->pluck('id'))->update(['recording_path' => null, 'recording_type' => null, 'recording_bytes' => 0]);
            });

        return $gone;
    }
}
