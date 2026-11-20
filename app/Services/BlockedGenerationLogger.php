<?php

namespace App\Services;

use App\Models\AudioGeneration;
use App\Models\BlockedGeneration;
use App\Models\User;
use App\Support\GenerationSource;
use App\Support\PaidMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BlockedGenerationLogger
{
    private const REQUEST_LOG_KEY = '_blocked_generation_log_hashes';

    // The browser extension's reader, which reads pages from other sites.
    private const READER_ROUTE = 'api.reader.queue';

    /** How a refusal by the reading of the text is named in the list (a refused word shows the word). */
    public const CHECK_LABEL = 'Meaning';

    private ?bool $tableExists = null;

    /**
     * @param  array{language?:string,term?:string}|null  $match
     */
    public function logFromValidation(string $text, ?array $match = null, ?string $attribute = null): void
    {
        $text = trim($text);
        if ($text === '' || ! $this->isReady()) {
            return;
        }

        $request = request();
        if (! $request instanceof Request) {
            return;
        }

        $routeName = $request->route()?->getName();
        $requestPath = '/'.ltrim((string) $request->path(), '/');
        $matchedLanguage = trim((string) ($match['language'] ?? '')) ?: null;
        $matchedTerm = trim((string) ($match['term'] ?? '')) ?: null;
        $userId = $this->resolveActorUserId($request);

        $fingerprint = sha1(implode('|', [
            (string) ($userId ?? 0),
            (string) $routeName,
            $requestPath,
            (string) $attribute,
            $matchedLanguage ?? '',
            $matchedTerm ?? '',
            mb_substr($text, 0, 250),
        ]));

        $logged = (array) $request->attributes->get(self::REQUEST_LOG_KEY, []);
        if (($logged[$fingerprint] ?? false) === true) {
            return;
        }
        $logged[$fingerprint] = true;
        $request->attributes->set(self::REQUEST_LOG_KEY, $logged);

        try {
            BlockedGeneration::query()->create([
                'user_id' => $userId,
                'route_name' => $routeName,
                'request_path' => $requestPath,
                'attribute' => $attribute,
                'input_text' => $text,
                'matched_term' => $matchedTerm,
                'matched_language' => $matchedLanguage,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 2000),
                'metadata' => [
                    'method' => $request->method(),
                    'locale' => app()->getLocale(),
                    'content_length' => mb_strlen($text),
                ],
            ]);

            // A developer API request carries text from the member's own users (a
            // chatbot, a site's visitors), and the extension reads pages from other
            // sites, so neither blocks the member's account by itself; both are
            // still logged here for the admin to review.
            if (! self::isApiRoute($routeName)) {
                $this->autoBlockUserAfterRepeatedAttempts($userId);
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to log blocked generation attempt.', [
                'route_name' => $routeName,
                'request_path' => $requestPath,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * A text the content check refused (App\Services\ContentSafety\TextContentCheck), found
     * while the voice was being made, so there is no request to read: the route is named
     * from where the voice was asked for, and counts toward the same rule as a refused word.
     *
     * @param  array{verdict?: string, category?: string, reason?: string}  $ruling
     */
    public function logFromContentCheck(AudioGeneration $generation, array $ruling, bool $voiceWasMade = false): void
    {
        $text = trim((string) $generation->input_text);
        if (! $this->isReady()) {
            return;
        }

        $source = (string) ($generation->textItem?->source_type ?? '');
        $routeName = match (true) {
            $generation->api_key_id !== null => 'v1.text-to-speech',
            $source === GenerationSource::EXTENSION => self::READER_ROUTE,
            default => 'dashboard.queue',
        };
        $userId = $generation->user_id ? (int) $generation->user_id : null;

        try {
            BlockedGeneration::query()->create([
                'user_id' => $userId,
                'route_name' => $routeName,
                'request_path' => null,
                'attribute' => 'content',
                'input_text' => $text !== '' ? $text : (string) $generation->style_prompt,
                'matched_term' => self::CHECK_LABEL.': '.(string) ($ruling['category'] ?? 'sexual'),
                'matched_language' => $generation->target_language ?: null,
                'ip_address' => null,
                'user_agent' => null,
                'metadata' => [
                    'check' => 'meaning',
                    'reason' => mb_substr((string) ($ruling['reason'] ?? ''), 0, 200),
                    'audio_generation_id' => (int) $generation->id,
                    'style_prompt' => filled($generation->style_prompt) ? mb_substr((string) $generation->style_prompt, 0, 2000) : null,
                    // Found on a later reading (the first could not be done): the voice had been made, and is now hidden.
                    'voice_was_made' => $voiceWasMade,
                    'content_length' => mb_strlen($text),
                ],
            ]);

            if (! self::isApiRoute($routeName)) {
                $this->autoBlockUserAfterRepeatedAttempts($userId);
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to log a text the content check refused.', [
                'audio_generation_id' => $generation->id,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    private static function isApiRoute(?string $routeName): bool
    {
        return is_string($routeName) && (str_starts_with($routeName, 'v1.') || $routeName === self::READER_ROUTE);
    }

    private function resolveActorUserId(Request $request): ?int
    {
        $user = $request->user();
        if ($user) {
            return (int) $user->id;
        }

        $attributeUserId = $request->attributes->get('listen_actor_user_id');
        if (is_numeric($attributeUserId)) {
            return (int) $attributeUserId;
        }

        return null;
    }

    private function autoBlockUserAfterRepeatedAttempts(?int $userId): void
    {
        if (! $userId) {
            return;
        }

        try {
            $user = User::query()->find($userId);
            if (! $user || (bool) $user->is_blocked) {
                return;
            }

            // Only attempts from the last day count, so one old false positive (or
            // one from before an unblock) never blocks the member later. Texts sent
            // through the developer API or read by the extension do not count.
            $attemptCount = (int) BlockedGeneration::query()
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->subDay())
                ->where(fn ($query) => $query->whereNull('route_name')->orWhere(fn ($named) => $named
                    ->where('route_name', 'not like', 'v1.%')
                    ->where('route_name', '<>', self::READER_ROUTE)))
                ->count();

            if ($attemptCount < 2) {
                return;
            }

            // Admins, members an admin unblocked or trusted, and members who pay or
            // have paid are never blocked by this: their attempts stay logged and
            // an admin decides.
            if ($user->is_admin || $user->multi_account_whitelisted || PaidMember::is($user)) {
                Log::warning('Repeated blocked wording from a member who is never blocked automatically; left for an admin.', [
                    'user_id' => $userId,
                    'attempts_last_day' => $attemptCount,
                ]);

                return;
            }

            app(BlockedUserService::class)->block($user);
        } catch (\Throwable $exception) {
            Log::warning('Failed to auto-block user after repeated blocked generations.', [
                'user_id' => $userId,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    private function isReady(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        $this->tableExists = Schema::hasTable('blocked_generations');

        return $this->tableExists;
    }
}
