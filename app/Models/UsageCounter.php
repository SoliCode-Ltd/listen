<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageCounter extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'period_ym',
        'total_minutes',
        'total_chars',
        'bonus_chars',
        'total_requests',
    ];

    protected $casts = [
        'bonus_chars' => 'integer',
    ];
}
