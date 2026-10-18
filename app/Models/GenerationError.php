<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that went wrong while a member was generating audio, kept so the
 * owner can see it later in the admin (Generation Errors).
 */
class GenerationError extends Model
{
    public const SOURCE_DASHBOARD = 'dashboard';
    public const SOURCE_EXTENSION = 'extension';
    public const SOURCE_DEMO = 'demo';
    public const SOURCE_VOICE_CLONING = 'voice_cloning';
    public const SOURCE_FALLBACK = 'fallback';
    public const SOURCE_API = 'api';

    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    protected $fillable = [
        'source',
        'severity',
        'user_id',
        'subject_type',
        'subject_id',
        'provider',
        'voice_name',
        'voice_tier',
        'target_language',
        'dialect',
        'exception_class',
        'message',
        'user_message',
        'context',
        'occurrences',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurrences' => 'integer',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_DASHBOARD => 'Dashboard',
            self::SOURCE_EXTENSION => 'Extension',
            self::SOURCE_DEMO => 'Demo',
            self::SOURCE_VOICE_CLONING => 'Voice cloning',
            self::SOURCE_FALLBACK => 'Backup voice used',
            self::SOURCE_API => 'Developer API',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
