<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * One developer API request, as the admin sees it (System → API requests).
 */
class ApiRequestLog extends Model
{
    public const UPDATED_AT = null;

    /** Days a request stays in the log. */
    public const KEEP_DAYS = 90;

    /**
     * Requests without a valid key one address may add to the log a minute:
     * enough to see a member's broken integration, never a flood of junk.
     */
    public const ANONYMOUS_PER_ADDRESS_PER_MINUTE = 30;

    protected $fillable = [
        'request_id',
        'user_id',
        'api_key_id',
        'method',
        'path',
        'endpoint',
        'status',
        'error_code',
        'duration_ms',
        'history_item_id',
        'credits',
        'characters',
        'replayed',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'status' => 'integer',
        'duration_ms' => 'integer',
        'history_item_id' => 'integer',
        'credits' => 'integer',
        'characters' => 'integer',
        'replayed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * Writes the log row for a finished request. Never lets logging break the
     * answer (a missing table or a full disk only leaves a warning).
     */
    public static function record(Request $request, Response $response, string $requestId, float $startedAt): void
    {
        try {
            $apiKey = $request->attributes->get('api_key');
            if (! $apiKey instanceof ApiKey) {
                $anonymous = 'v1-log-anonymous:'.$request->ip();
                if (RateLimiter::tooManyAttempts($anonymous, self::ANONYMOUS_PER_ADDRESS_PER_MINUTE)) {
                    return;
                }
                RateLimiter::hit($anonymous, 60);
            }
            // What a request said about itself (the MCP server notes the tool it ran
            // and the generation it made, which its JSON-RPC answer does not show).
            $noted = (array) $request->attributes->get('api_log', []);
            $error = $noted['error_code'] ?? null;
            $historyItemId = $noted['history_item_id'] ?? $response->headers->get('X-History-Item-Id');
            if ($noted === [] && ($response->getStatusCode() >= 400 || $historyItemId === null)) {
                $body = json_decode((string) $response->getContent(), true);
                if (is_array($body)) {
                    $error = is_array($body['error'] ?? null) ? ($body['error']['code'] ?? null) : null;
                    $historyItemId ??= $body['history_item_id'] ?? ($body['error']['history_item_id'] ?? null);
                }
            }
            $credits = $noted['credits'] ?? $response->headers->get('X-Credits-Used');
            $characters = $noted['characters'] ?? $response->headers->get('X-Character-Count');

            self::query()->create([
                'request_id' => $requestId,
                'user_id' => $apiKey instanceof ApiKey ? $apiKey->user_id : null,
                'api_key_id' => $apiKey instanceof ApiKey ? $apiKey->id : null,
                'method' => $request->method(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                'endpoint' => $noted['endpoint'] ?? $request->route()?->getName(),
                'status' => $response->getStatusCode(),
                'error_code' => $error !== null ? mb_substr((string) $error, 0, 60) : null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'history_item_id' => is_numeric($historyItemId) ? (int) $historyItemId : null,
                'credits' => is_numeric($credits) ? (int) $credits : null,
                'characters' => is_numeric($characters) ? (int) $characters : null,
                'replayed' => $response->headers->get('Idempotent-Replayed') === 'true',
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not log a developer API request.', ['request_id' => $requestId, 'error' => $e->getMessage()]);
        }
    }

    /** Removes rows older than $before, a batch at a time, so the table is never locked for long. */
    public static function pruneOlderThan(\DateTimeInterface $before): void
    {
        do {
            $ids = self::query()->where('created_at', '<', $before)->orderBy('id')->limit(2000)->pluck('id');
            if ($ids->isNotEmpty()) {
                self::query()->whereKey($ids->all())->delete();
            }
        } while ($ids->count() === 2000);
    }
}
