<?php

namespace App\Support;

use App\Mcp\McpErrors;
use App\Support\Api\ApiErrorRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The month's credits are not enough for this request (PlanUsageGuard). The same
 * error as before for every caller, with what is left and what was needed beside
 * it, so the studio can offer extra credits instead of only saying so.
 */
class OutOfCredits extends ValidationException
{
    public int $remaining = 0;

    public int $needed = 0;

    public static function make(string $message, int $remaining, int $needed): self
    {
        /** @var self $exception */
        $exception = self::withMessages(['content' => $message]);
        $exception->remaining = max(0, $remaining);
        $exception->needed = max(0, $needed);

        return $exception;
    }

    /** JSON callers get the usual validation error plus out_of_credits; anyone else, the usual page. */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() || ApiErrorRenderer::appliesTo($request) || McpErrors::appliesTo($request)) {
            return null;
        }

        return response()->json([
            'message' => (string) collect($this->errors())->flatten()->first(),
            'errors' => $this->errors(),
            'out_of_credits' => true,
            'credits' => ['remaining' => $this->remaining, 'needed' => $this->needed],
        ], $this->status);
    }
}
