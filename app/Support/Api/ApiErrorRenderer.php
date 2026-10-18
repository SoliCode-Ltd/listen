<?php

namespace App\Support\Api;

use App\Models\ApiRequestLog;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns anything thrown while serving /v1 into the API's one error shape,
 * so clients never see an HTML page or a framework message.
 */
final class ApiErrorRenderer
{
    public static function appliesTo(Request $request): bool
    {
        return $request->is('v1') || $request->is('v1/*');
    }

    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get('api_request_id');
        if (! is_string($id) || $id === '') {
            $id = 'req_'.Str::lower(Str::random(24));
            $request->attributes->set('api_request_id', $id);
        }

        return $id;
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        $error = self::toApiError($e);
        $response = self::respond($error, $request);

        // A path that matches no route never reaches ApiRequestContext: logged here.
        if ($request->route() === null) {
            ApiRequestLog::record($request, $response, self::requestId($request), (float) $request->server('REQUEST_TIME_FLOAT', microtime(true)));
        }

        return $response;
    }

    public static function respond(ApiError $error, Request $request): JsonResponse
    {
        $requestId = self::requestId($request);

        $body = [
            'type' => $error->type,
            'code' => $error->errorCode,
            'message' => $error->getMessage(),
            'param' => $error->param,
        ] + $error->extra + [
            'request_id' => $requestId,
        ];

        return new JsonResponse(['error' => $body], $error->status, $error->headers + [
            'X-Request-Id' => $requestId,
            'Cache-Control' => 'no-store',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function toApiError(Throwable $e): ApiError
    {
        if ($e instanceof ApiError) {
            return $e;
        }

        if ($e instanceof ValidationException) {
            $errors = $e->errors();
            $param = array_key_first($errors);
            $message = $param !== null ? (string) ($errors[$param][0] ?? 'The request is not valid.') : 'The request is not valid.';

            return new ApiError(422, ApiError::TYPE_INVALID_REQUEST, 'validation_failed', $message, $param !== null ? (string) $param : null, [], [
                'errors' => $errors,
            ]);
        }

        if ($e instanceof AuthenticationException) {
            return ApiError::missingKey();
        }

        if ($e instanceof ThrottleRequestsException) {
            $headers = array_map(static fn ($value): string => (string) $value, $e->getHeaders());

            return new ApiError(429, ApiError::TYPE_RATE_LIMIT, 'rate_limit_exceeded',
                'Too many requests a minute for your account. Wait for the time in the Retry-After header, then try again.', null, $headers);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return ApiError::notFound();
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return new ApiError(405, ApiError::TYPE_INVALID_REQUEST, 'method_not_allowed',
                'This endpoint does not accept this HTTP method.', null, array_map('strval', $e->getHeaders()));
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return match (true) {
                $status === 413 => new ApiError(413, ApiError::TYPE_INVALID_REQUEST, 'payload_too_large', 'The request body is too large.'),
                $status === 401 => ApiError::invalidKey(),
                $status === 403 => new ApiError(403, ApiError::TYPE_PERMISSION, 'forbidden', 'You do not have access to this resource.'),
                $status === 503 => new ApiError(503, ApiError::TYPE_API, 'service_unavailable', 'The service is temporarily unavailable. Please try again shortly.'),
                $status >= 500 => new ApiError($status, ApiError::TYPE_API, 'internal_error', 'Something went wrong on our side. Please try again.'),
                default => new ApiError($status, ApiError::TYPE_INVALID_REQUEST, 'bad_request', 'The request could not be processed.'),
            };
        }

        return new ApiError(500, ApiError::TYPE_API, 'internal_error',
            'Something went wrong on our side. Please try again; if it keeps happening, contact support with the request_id.');
    }
}
