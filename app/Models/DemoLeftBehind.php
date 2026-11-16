<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a deleted account had used of the free demo (App\Services\DemoSamePerson::leaveBehind).
 */
class DemoLeftBehind extends Model
{
    protected $table = 'demo_left_behind';

    protected $fillable = [
        'former_user_id',
        'account_created_at',
        'demo_chars',
        'design_used',
        'free_dub_used',
        'free_stt_used',
        'free_call_seconds',
        'deleted_by',
    ];

    protected $casts = [
        'account_created_at' => 'datetime',
        'demo_chars' => 'integer',
        'design_used' => 'boolean',
        'free_dub_used' => 'boolean',
        'free_stt_used' => 'boolean',
        'free_call_seconds' => 'integer',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(DemoLeftBehindSignal::class);
    }
}
