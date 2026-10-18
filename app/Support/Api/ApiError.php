<?php

namespace App\Support\Api;

use RuntimeException;

/**
 * An error the developer API answers with, always in the same shape:
 * {"error": {"type", "code", "message", "param", "request_id"}}.
 */
class ApiError extends RuntimeException
{
    public const TYPE_AUTHENTICATION = 'authentication_error';

    public const TYPE_PERMISSION = 'permission_error';

    public const TYPE_INVALID_REQUEST = 'invalid_request_error';

    public const TYPE_QUOTA = 'quota_error';

    public const TYPE_RATE_LIMIT = 'rate_limit_error';

    public const TYPE_API = 'api_error';

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra  more fields for the error object
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $param = null,
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function missingKey(): self
    {
        return new self(401, self::TYPE_AUTHENTICATION, 'missing_api_key',
            'No API key was sent. Send your key in the X-Listen-Api-Key header or as "Authorization: Bearer <key>".');
    }

    public static function invalidKey(): self
    {
        return new self(401, self::TYPE_AUTHENTICATION, 'invalid_api_key',
            'The API key is not valid or has been revoked.');
    }

    public static function accountBlocked(): self
    {
        return new self(403, self::TYPE_PERMISSION, 'account_suspended',
            'This account is suspended. Contact support for help.');
    }

    public static function notInPlan(): self
    {
        return new self(403, self::TYPE_PERMISSION, 'api_access_not_in_plan',
            'Your current plan does not include API access. Upgrade your plan to use the API.');
    }

    public static function notDuringTrial(): self
    {
        return new self(403, self::TYPE_PERMISSION, 'api_not_available_during_trial',
            'The API starts once your first payment has gone through. It is not available during a free trial.');
    }

    public static function missingScope(string $scope): self
    {
        return new self(403, self::TYPE_PERMISSION, 'missing_permission',
            "This API key does not have the \"{$scope}\" permission.", null, [], ['required_permission' => $scope]);
    }

    public static function notFound(string $what = 'resource', ?string $param = null): self
    {
        return new self(404, self::TYPE_INVALID_REQUEST, $what === 'resource' ? 'not_found' : "{$what}_not_found",
            $what === 'resource' ? 'The requested resource does not exist.' : 'No '.str_replace('_', ' ', $what).' was found with this id.', $param);
    }

    public static function invalid(string $code, string $message, ?string $param = null, int $status = 422): self
    {
        return new self($status, self::TYPE_INVALID_REQUEST, $code, $message, $param);
    }

    public static function featureNotInPlan(string $message, ?string $param = null): self
    {
        return new self(403, self::TYPE_PERMISSION, 'feature_not_in_plan', $message, $param);
    }
}
