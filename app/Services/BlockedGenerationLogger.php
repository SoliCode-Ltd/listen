<?php

namespace App\Services;

use App\Models\BlockedGeneration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BlockedGenerationLogger
{
    private const REQUEST_LOG_KEY = '_blocked_generation_log_hashes';

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

            $this->autoBlockUserAfterRepeatedAttempts($userId);
        } catch (\Throwable $exception) {
            Log::warning('Failed to log blocked generation attempt.', [
                'route_name' => $routeName,
                'request_path' => $requestPath,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
            ]);
        }
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

            $attemptCount = (int) BlockedGeneration::query()
                ->where('user_id', $userId)
                ->count();

            if ($attemptCount < 2) {
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
