<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A video a member sent to "Dub my video" (App\Http\Controllers\VideoDubController).
 */
class VideoDub extends Model
{
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** Taken away by an admin, who gave back what it took (VideoDubRunner::remove()). */
    public const REMOVED = 'removed';

    /** Dubs that took nothing in the end: not the free dub, not a video of the month, no credits. */
    public const GIVEN_BACK = [self::FAILED, self::REMOVED];

    protected $fillable = [
        'user_id',
        'status',
        'is_free',
        'dialect',
        'voice',
        'voice_key',
        'performance_style',
        'emotion',
        'original_name',
        'video_seconds',
        'folder',
        'has_clean',
        'has_preview',
        'reservation',
        'report',
        'error_message',
        'browser',
        'device',
        'files_deleted_at',
        'period_ym',
        'stage',
        'progress',
        'keep_background',
        'language',
    ];

    protected $casts = [
        'is_free' => 'boolean',
        'video_seconds' => 'float',
        'has_clean' => 'boolean',
        'has_preview' => 'boolean',
        'reservation' => 'array',
        'report' => 'array',
        'files_deleted_at' => 'datetime',
        'progress' => 'integer',
        'keep_background' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isWorking(): bool
    {
        return in_array($this->status, [self::QUEUED, self::PROCESSING], true);
    }

    /** Why it failed, in the reader's language (kept as a translation key; older rows as text). */
    public function errorText(): string
    {
        $message = (string) $this->error_message;

        return str_starts_with($message, 'ui.dub.') ? (string) __($message) : $message;
    }

    /** A file of this dub on the local disk: source.*, dub.mp4 or preview.mp4. */
    public function path(string $file): string
    {
        return $this->folder.'/'.$file;
    }
}
