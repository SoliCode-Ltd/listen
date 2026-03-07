<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoFraudSignal extends Model
{
    use HasFactory;

    protected $fillable = [
        'identity_hash',
        'signal_type',
        'signal_data',
    ];

    protected $casts = [
        'signal_data' => 'array',
    ];
}
