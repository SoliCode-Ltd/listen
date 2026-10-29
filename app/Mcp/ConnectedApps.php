<?php

namespace App\Mcp;

use App\Models\ApiKey;
use App\Models\User;
use App\Support\Api\ApiScopes;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * AI apps a member connected with "Sign in with Listen" (OAuth on the MCP
 * server). Each one gets a key of its own (ApiKey::forConnectedApp) the
 * first time it gets a token, so its usage, its credit limit and the
 * request log work as for any key. Disconnecting it revokes the key and
 * every token the app holds for the member.
 */
final class ConnectedApps
{
    /** What a connected app may do: what the MCP server's tools need, nothing more. */
    public const SCOPES = [ApiScopes::TEXT_TO_SPEECH, ApiScopes::VOICES_READ, ApiScopes::HISTORY_READ, ApiScopes::USER_READ];

    /** The app's key for the member, made the first time it is needed. */
    public static function keyFor(User $user, string $clientId): ApiKey
    {
        $find = static fn (): ?ApiKey => ApiKey::query()->where('user_id', $user->id)->where('oauth_client_id', $clientId)->active()->first();
        if (($key = $find()) instanceof ApiKey) {
            return $key;
        }

        // The first time: one at a time per member, so two requests at once make one key.
        return DB::transaction(static function () use ($user, $clientId, $find): ApiKey {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (($key = $find()) instanceof ApiKey) {
                return $key;
            }

            $name = (string) (Passport::client()->newQuery()->whereKey($clientId)->value('name') ?? '');

            return ApiKey::forConnectedApp($user, $name, $clientId, self::SCOPES);
        });
    }

    public static function disconnect(ApiKey $key): void
    {
        if (! $key->isConnectedApp()) {
            return;
        }

        DB::transaction(static function () use ($key): void {
            $key->revoke();

            $tokens = Passport::token()->newQuery()->where('user_id', $key->user_id)->where('client_id', $key->oauth_client_id);
            $tokenIds = $tokens->pluck('id');
            $tokens->update(['revoked' => true]);
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
            Passport::authCode()->newQuery()->where('user_id', $key->user_id)->where('client_id', $key->oauth_client_id)->update(['revoked' => true]);
        });
    }
}
