@php
    $record = $getRecord();
    $kind = $kind ?? 'sample';
    $path = $kind === 'sample' ? $record->source_audio_path : $record->consent_audio_path;
@endphp

@if (filled($path))
    <div class="space-y-1">
        <audio
            controls
            preload="none"
            src="{{ route('admin.cloned-voices.audio', ['voice' => $record->id, 'kind' => $kind]) }}"
            style="width: 100%; max-width: 440px;"
        ></audio>
        <div class="text-xs text-gray-500 dark:text-gray-400 break-all">{{ $path }}</div>
    </div>
@else
    <span class="text-sm text-gray-500 dark:text-gray-400">—</span>
@endif
