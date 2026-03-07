<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoIdentityLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'identity_hash',
        'link_group',
        'identity_type',
        'confidence',
        'is_primary',
    ];

    protected $casts = [
        'confidence' => 'float',
        'is_primary' => 'boolean',
    ];
}
