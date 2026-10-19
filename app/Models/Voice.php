<?php

namespace App\Models;

use App\Support\VoiceCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voice extends Model
{
    use HasFactory;

    protected $fillable = [
        'language',
        'gender',
        'tier',
        'display_name',
        'internal_voice_id',
        'is_active',
        // Voice studio fields
        'provider',
        'provider_voice_id',
        'dialect',
        'country',
        'supported_emotions',
        'preview_audio_path',
        'sort_order',
        'is_featured',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
        'supported_emotions' => 'array',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        // VoiceCatalog keeps the voices it read for the request: a change is seen at once.
        static::saved(fn () => VoiceCatalog::forgetVoiceMeta());
        static::deleted(fn () => VoiceCatalog::forgetVoiceMeta());
    }
}
