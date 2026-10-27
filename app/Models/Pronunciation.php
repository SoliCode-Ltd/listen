<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One word of a member's pronunciations: how every new voice says it (App\Support\Pronunciations).
 */
class Pronunciation extends Model
{
    protected $fillable = ['user_id', 'word', 'word_key', 'spoken'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
