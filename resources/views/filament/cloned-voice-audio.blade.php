@php
    $record = $getRecord();
    $kind = $kind ?? 'sample';
    $path = $kind === 'sample' ? $record->source_audio_path : $record->consent_audio_path;
    $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'webm' => 'audio/webm',
        'flac' => 'audio/flac',
        'm4a', 'mp4' => 'audio/mp4',
        default => null,
    };
@endphp

@if (filled($path))
    <div class="space-y-1">
        <audio controls preload="metadata" style="width: 100%; max-width: 440px;">
            <source
                src="{{ route('admin.cloned-voices.audio', ['voice' => $record->id, 'kind' => $kind], false) }}"
                @if ($mime) type="{{ $mime }}" @endif
            >
        </audio>
        <div class="text-xs text-gray-500 dark:text-gray-400 break-all">{{ $path }}</div>
    </div>
@else
    <span class="text-sm text-gray-500 dark:text-gray-400">—</span>
@endif
