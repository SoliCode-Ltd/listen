@php
    $record = $getRecord();
    $kind = $kind ?? 'clean';
    $file = \App\Http\Controllers\AdminVideoDubFileController::FILES[$kind];
    // A paid dub kept in Google Drive plays from there (its clean copy; the marked one is only made for free dubs).
    $inDrive = $kind === 'clean' && filled($record->drive_file_id);
    $exists = $inDrive || ($record->files_deleted_at === null && \Illuminate\Support\Facades\Storage::disk('local')->exists($record->path($file)));
@endphp

@if ($exists)
    <video controls preload="metadata" playsinline style="width: 100%; max-width: 520px; border-radius: .5rem; background: #000;">
        <source src="{{ route('admin.video-dubs.file', ['dub' => $record->id, 'kind' => $kind], false) }}" type="video/mp4">
    </video>
    @if ($inDrive)
        <a href="{{ \App\Services\Dubbing\DriveArchive::fileUrl($record->drive_file_id) }}" target="_blank" rel="noopener" class="mt-1 block text-sm text-primary-600 hover:underline dark:text-primary-400">Open in Google Drive</a>
    @endif
@else
    <span class="text-sm text-gray-500 dark:text-gray-400">—</span>
@endif
