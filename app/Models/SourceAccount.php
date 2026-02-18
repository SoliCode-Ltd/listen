<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SourceAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'label',
        'identifier',
        'settings',
        'last_synced_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
