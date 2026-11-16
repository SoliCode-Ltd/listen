@php
    $record = $getRecord();
    $kept = $record->audio_deleted_at === null && $record->audio_path
        && \Illuminate\Support\Facades\Storage::disk(\App\Services\Transcription\TranscriptionFlow::DISK)->exists((string) $record->audio_path);
@endphp

@if ($kept)
    <audio controls preload="metadata" style="width: 100%; max-width: 560px;">
        <source src="{{ route('admin.transcripts.audio', ['code' => $record->code], false) }}" type="audio/mpeg">
    </audio>
@else
    <span class="text-sm text-gray-500 dark:text-gray-400">—</span>
@endif
