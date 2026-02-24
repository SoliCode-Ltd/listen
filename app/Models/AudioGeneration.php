<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioGeneration extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'text_item_id',
        'input_text',
        'source_language',
        'target_language',
        'voice_tier',
        'voice_name',
        'translate',
        'audio_path',
        'duration_seconds',
        'status',
        'error_message',
    ];

    protected $casts = [
        'translate' => 'boolean',
        'duration_seconds' => 'integer',
    ];

    public function textItem(): BelongsTo
    {
        return $this->belongsTo(TextItem::class);
    }
}
