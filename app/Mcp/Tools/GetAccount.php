<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\UserController;
use App\Mcp\McpCaller;
use App\Support\Api\ApiScopes;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The plan, credits and limits (GET /v1/user/subscription).
 */
#[Name('get_account')]
#[Title('Get account')]
#[Description('Show the person\'s Listen plan, how many credits are left and when they renew, and the longest text one request can take. Check it before a long or repeated job.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetAccount extends ListenTool
{
    protected function scope(): string
    {
        return ApiScopes::USER_READ;
    }

    protected function run(Request $request, McpCaller $caller): Response|ResponseFactory
    {
        $account = $caller->call(UserController::class, 'subscription', 'GET', '/user/subscription');
        $credits = (array) $account['credits'];
        $keyLimit = $account['api_key']['monthly_credit_limit'] ?? null;

        $lines = array_filter([
            'Plan: '.$account['plan']['name'].'.',
            'Credits left: '.number_format((int) $credits['remaining']).' of '.number_format((int) $credits['limit'])
                .($credits['resets_at'] !== null ? ', renewing on '.substr((string) $credits['resets_at'], 0, 10) : '')
                .($credits['expires_at'] !== null ? ', usable until '.substr((string) $credits['expires_at'], 0, 10) : '').'.',
            'Each second of audio uses '.(int) $credits['credits_per_second'].' credits.',
            $keyLimit !== null ? 'This connection may use '.number_format((int) $keyLimit).' credits a month; '.number_format((int) $account['api_key']['credits_used_this_month']).' used so far.' : null,
            'Longest text per request: '.number_format((int) $account['limits']['max_characters_per_async_request']).' characters.',
        ]);

        return Response::make([Response::text(implode("\n", $lines))])->withStructuredContent($account);
    }
}
