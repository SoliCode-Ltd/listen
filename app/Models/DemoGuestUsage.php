<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoGuestUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'identity_hash',
        'link_group',
        'used_chars',
        'abuse_score',
        'is_blocked',
        'metadata',
        'last_seen_at',
    ];

    protected $casts = [
        'used_chars' => 'integer',
        'abuse_score' => 'integer',
        'is_blocked' => 'boolean',
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];
}

