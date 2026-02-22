<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoGuestUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'identity_hash',
        'used_chars',
        'last_seen_at',
    ];

    protected $casts = [
        'used_chars' => 'integer',
        'last_seen_at' => 'datetime',
    ];
}

