@php
    $record = $getRecord();
    $kind = $kind ?? 'clean';
    $file = \App\Http\Controllers\AdminVideoDubFileController::FILES[$kind];
    $exists = $record->files_deleted_at === null && \Illuminate\Support\Facades\Storage::disk('local')->exists($record->path($file));
@endphp

@if ($exists)
    <video controls preload="metadata" playsinline style="width: 100%; max-width: 520px; border-radius: .5rem; background: #000;">
        <source src="{{ route('admin.video-dubs.file', ['dub' => $record->id, 'kind' => $kind], false) }}" type="video/mp4">
    </video>
@else
    <span class="text-sm text-gray-500 dark:text-gray-400">—</span>
@endif
