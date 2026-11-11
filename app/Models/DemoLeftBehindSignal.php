<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One way a deleted account can be recognised again: a browser, a device, its
 * sign-up network, its inbox or a demo fingerprint, as a sha256 hash only.
 */
class DemoLeftBehindSignal extends Model
{
    public const BROWSER = 'browser';

    public const DEVICE = 'device';

    public const NETWORK = 'network';

    public const MAILBOX = 'mailbox';

    public const FINGERPRINT = 'fingerprint';

    public $timestamps = false;

    protected $fillable = [
        'demo_left_behind_id',
        'kind',
        'signal',
        'touch',
        'account_created_at',
    ];

    protected $casts = [
        'touch' => 'boolean',
        'account_created_at' => 'datetime',
    ];

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
