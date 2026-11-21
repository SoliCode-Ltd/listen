<?php

namespace App\Models;

use App\Support\Acquisition\Channels;
use App\Support\Acquisition\Touch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * How a member found the site, kept when their account was made (App\Services\Acquisition\VisitSources).
 *
 * channel/detail: the last place they came from before signing up (an ad, a search, a link...), or
 * their first visit when nothing came after it. first_channel/first_detail: their first visit.
 */
class MemberSource extends Model
{
    public const SIGNED_UP_WEB = 'web';

    public const SIGNED_UP_ANDROID = 'android';

    public const SIGNED_UP_IOS = 'ios';

    /** @var array<string, string> */
    public const SIGNED_UP_LABELS = [
        self::SIGNED_UP_WEB => 'Website',
        self::SIGNED_UP_ANDROID => 'Android app',
        self::SIGNED_UP_IOS => 'iPhone app',
    ];

    protected $fillable = [
        'user_id', 'signed_up_in', 'channel', 'detail', 'campaign', 'landing_path', 'referrer_host', 'came_at',
        'first_channel', 'first_detail', 'first_campaign', 'first_landing_path', 'first_referrer_host', 'first_came_at', 'visits',
    ];

    protected $casts = [
        'came_at' => 'datetime',
        'first_came_at' => 'datetime',
        'visits' => 'array',
    ];

    private static ?bool $ready = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether the table is there (before its migration has run, nothing is kept). Only a yes is remembered. */
    public static function ready(): bool
    {
        if (self::$ready === true) {
            return true;
        }

        try {
            return self::$ready = Schema::hasTable('member_sources');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The record for a new member: from the visits the site remembered for their browser (VisitSources::read),
     * if any, and where they signed up (null: the website; android or ios: the phone app).
     *
     * @param  array{f?: mixed, l?: mixed}|null  $visits
     */
    public static function fromVisits(int $userId, ?array $visits, ?string $platform = null): self
    {
        $first = Touch::fromStored($visits['f'] ?? null);
        $last = Touch::fromStored($visits['l'] ?? null);
        // The last visit with a source, or the first visit when nothing came after it.
        $decisive = $last !== null && $last->hasSource() ? $last : $first;
        $signedUpIn = in_array($platform, [self::SIGNED_UP_ANDROID, self::SIGNED_UP_IOS], true) ? $platform : self::SIGNED_UP_WEB;

        $sorted = $decisive !== null ? Channels::classify($decisive) : ['channel' => Channels::UNKNOWN, 'detail' => null, 'campaign' => null];
        $firstSorted = $first !== null ? Channels::classify($first) : null;

        // Made in the app with nothing from the website before: the app is where they came from.
        if ($signedUpIn !== self::SIGNED_UP_WEB && in_array($sorted['channel'], [Channels::DIRECT, Channels::UNKNOWN], true)) {
            $sorted = ['channel' => Channels::APP, 'detail' => self::SIGNED_UP_LABELS[$signedUpIn], 'campaign' => null];
            $firstSorted ??= $sorted;
        }

        return new self([
            'user_id' => $userId,
            'signed_up_in' => $signedUpIn,
            'channel' => $sorted['channel'],
            'detail' => $sorted['detail'] !== null ? mb_substr($sorted['detail'], 0, 80) : null,
            'campaign' => $sorted['campaign'] !== null ? mb_substr($sorted['campaign'], 0, 120) : null,
            'landing_path' => $decisive?->path,
            'referrer_host' => $decisive !== null && $decisive->host !== '' ? $decisive->host : null,
            'came_at' => $decisive !== null && $decisive->at > 0 ? Carbon::createFromTimestamp($decisive->at) : null,
            'first_channel' => $firstSorted['channel'] ?? null,
            'first_detail' => isset($firstSorted['detail']) ? mb_substr((string) $firstSorted['detail'], 0, 80) : null,
            'first_campaign' => isset($firstSorted['campaign']) ? mb_substr((string) $firstSorted['campaign'], 0, 120) : null,
            'first_landing_path' => $first?->path,
            'first_referrer_host' => $first !== null && $first->host !== '' ? $first->host : null,
            'first_came_at' => $first !== null && $first->at > 0 ? Carbon::createFromTimestamp($first->at) : null,
            'visits' => $first !== null ? array_filter(['f' => $first->toArray(), 'l' => $last?->toArray()]) : null,
        ]);
    }

    public function channelLabel(): string
    {
        return Channels::label($this->channel);
    }

    public function signedUpInLabel(): string
    {
        return self::SIGNED_UP_LABELS[$this->signed_up_in] ?? self::SIGNED_UP_LABELS[self::SIGNED_UP_WEB];
    }
}
