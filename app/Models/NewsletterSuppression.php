<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterSuppression extends Model
{
    protected $fillable = [
        'email',
        'unsubscribed_at',
    ];

    protected $casts = [
        'unsubscribed_at' => 'datetime',
    ];
}
