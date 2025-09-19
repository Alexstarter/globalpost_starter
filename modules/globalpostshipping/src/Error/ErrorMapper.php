<?php

namespace GlobalPostShipping\Error;

use GlobalPostShipping\SDK\GlobalPostClientException;
use Throwable;

class ErrorMapper
{
    private const STATUS_MAP = [
        400 => ['code' => 'api_bad_request', 'message' => 'GlobalPost rejected the request as invalid.'],
        401 => ['code' => 'api_auth_failed', 'message' => 'GlobalPost authentication failed. Check the API token.'],
        403 => ['code' => 'api_forbidden', 'message' => 'GlobalPost denied access to the requested resource.'],
        404 => ['code' => 'api_not_found', 'message' => 'GlobalPost could not find the requested resource.'],
        409 => ['code' => 'api_conflict', 'message' => 'A conflicting shipment already exists in GlobalPost.'],
        422 => ['code' => 'api_validation_failed', 'message' => 'GlobalPost validation failed for the shipment payload.'],
        429 => ['code' => 'api_rate_limited', 'message' => 'Too many requests were sent to GlobalPost. Try again later.'],
    ];

    /**
     * @var array<string, array{code: string, message: string}>
     */
    private const ERROR_CODE_MAP = [
        'INVALID_TOKEN' => ['code' => 'api_auth_failed', 'message' => 'GlobalPost authentication failed. Check the API token.'],
        'AUTH_ERROR' => ['code' => 'api_auth_failed', 'message' => 'GlobalPost authentication failed. Check the API token.'],
        'PERMISSION_DENIED' => ['code' => 'api_forbidden', 'message' => 'GlobalPost denied access to the requested resource.'],
        'NOT_FOUND' => ['code' => 'api_not_found', 'message' => 'GlobalPost could not find the requested resource.'],
        'VALIDATION_ERROR' => ['code' => 'api_validation_failed', 'message' => 'GlobalPost validation failed for the shipment payload.'],
        'RATE_LIMITED' => ['code' => 'api_rate_limited', 'message' => 'Too many requests were sent to GlobalPost. Try again later.'],
        'SERVER_ERROR' => ['code' => 'api_service_unavailable', 'message' => 'GlobalPost service is temporarily unavailable. Try again later.'],
    ];

    /**
     * Maps an API exception to a normalized error payload for the admin UI and logs.
     *
     * @return array{code: string, admin_message: string, log_message: string, status: int|null, api_code: string|null}
     */
    public static function fromClientException(GlobalPostClientException $exception): array
    {
        $status = $exception->getStatusCode();
        $apiCodeRaw = $exception->getErrorCode();
        $apiCode = $apiCodeRaw !== null ? strtoupper($apiCodeRaw) : null;
        $message = trim($exception->getMessage());

        $mapped = null;

        if ($apiCode !== null && isset(self::ERROR_CODE_MAP[$apiCode])) {
            $mapped = self::ERROR_CODE_MAP[$apiCode];
        } elseif ($status !== null && isset(self::STATUS_MAP[$status])) {
            $mapped = self::STATUS_MAP[$status];
        } elseif ($status !== null && $status >= 500) {
            $mapped = ['code' => 'api_service_unavailable', 'message' => 'GlobalPost service is temporarily unavailable. Try again later.'];
        }

        $code = $mapped['code'] ?? 'api_error';
        $adminMessage = $mapped['message'] ?? 'GlobalPost returned an unexpected error. Try again later or contact support.';
        $logMessage = $message !== '' ? $message : $adminMessage;

        if ($status !== null) {
            $logMessage = sprintf('HTTP %d: %s', $status, $logMessage);
        }

        if ($apiCode !== null) {
            $logMessage .= sprintf(' [code: %s]', $apiCode);
        }

        return [
            'code' => $code,
            'admin_message' => $adminMessage,
            'log_message' => $logMessage,
            'status' => $status,
            'api_code' => $apiCode,
        ];
    }

    /**
     * Maps a transport/network exception to a normalized error payload.
     *
     * @return array{code: string, admin_message: string, log_message: string}
     */
    public static function fromTransportException(Throwable $exception): array
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = 'Network request to GlobalPost failed.';
        }

        return [
            'code' => 'api_transport',
            'admin_message' => 'Connection to GlobalPost failed. Check the network connection and retry.',
            'log_message' => $message,
        ];
    }

    /**
     * Provides a combined list of handled HTTP and API codes for documentation purposes.
     *
     * @return array{status: int[], api: string[]}
     */
    public static function getHandledCodes(): array
    {
        return [
            'status' => array_values(array_unique(array_merge(array_keys(self::STATUS_MAP), [500, 503]))),
            'api' => array_values(array_unique(array_keys(self::ERROR_CODE_MAP))),
        ];
    }
}
