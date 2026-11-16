<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One transcript: see the transcriptions migration. The
 * seconds it used of the member's month (charged_seconds) are held when it
 * starts and given back when it fails (App\Services\Transcription\TranscriptionFlow).
 *
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property string $title
 * @property string $source
 * @property string $status
 * @property float $seconds
 * @property int $charged_seconds
 * @property bool $is_free
 */
class Transcription extends Model
{
    use SoftDeletes;

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** Still working: counts against the member's files at once. */
    public const WORKING = [self::QUEUED, self::PROCESSING];

    /** Where the audio came from. */
    public const SOURCES = ['upload', 'record', 'url', 'api', 'mcp', 'demo'];

    protected $fillable = [
        'user_id', 'code', 'title', 'source', 'source_url', 'audio_path', 'audio_deleted_at', 'seconds', 'language_requested', 'language', 'dialect',
        'status', 'parts', 'parts_done', 'work', 'paragraphs', 'segments', 'speaker_names', 'translations', 'charged_seconds', 'is_free', 'period_ym',
        'priority', 'api_key_id', 'requests', 'usage', 'cost_usd', 'model', 'notes', 'error_code', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'seconds' => 'float',
        'work' => 'array',
        'paragraphs' => 'array',
        'segments' => 'array',
        'speaker_names' => 'array',
        'translations' => 'array',
        'charged_seconds' => 'integer',
        'is_free' => 'boolean',
        'priority' => 'boolean',
        'parts' => 'integer',
        'parts_done' => 'integer',
        'requests' => 'integer',
        'usage' => 'array',
        'cost_usd' => 'float',
        'notes' => 'array',
        'audio_deleted_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $hidden = ['work'];

    protected static function booted(): void
    {
        static::creating(function (self $t): void {
            $t->code = $t->code ?: 'tr_'.Str::lower(Str::random(24));
        });
    }

    /** Saved as UTF-8 (not \u escapes): Arabic words can be searched, and take half the room. */
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isWorking(): bool
    {
        return in_array($this->status, self::WORKING, true);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** The name a speaker letter is shown with: the member's own name for it, or "Speaker A". */
    public function speakerName(string $letter, bool $arabic = false): string
    {
        $own = trim((string) (($this->speaker_names ?? [])[$letter] ?? ''));

        return $own !== '' ? $own : ($arabic ? 'المتحدث '.$letter : 'Speaker '.$letter);
    }

    /** How many different speakers the transcript has. */
    public function speakerCount(): int
    {
        return count(array_unique(array_column((array) $this->paragraphs, 'speaker')));
    }

    /** The minutes it used, as whole minutes and seconds (0 for a failed one). */
    public function usedSeconds(): int
    {
        return $this->is_free || $this->status === self::FAILED ? 0 : (int) $this->charged_seconds;
    }
}
