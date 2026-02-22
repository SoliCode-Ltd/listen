<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
