<?php

namespace Aviato\Laravel\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A ConnectRPC error. Throw it from a handler to answer the connector with a specific error code;
 * any other exception becomes an `internal` error.
 */
class RpcException extends RuntimeException
{
    /** Connect error codes and their HTTP status. */
    public const STATUSES = [
        'canceled' => 499,
        'unknown' => 500,
        'invalid_argument' => 400,
        'deadline_exceeded' => 504,
        'not_found' => 404,
        'already_exists' => 409,
        'permission_denied' => 403,
        'resource_exhausted' => 429,
        'failed_precondition' => 400,
        'aborted' => 409,
        'out_of_range' => 400,
        'unimplemented' => 501,
        'internal' => 500,
        'unavailable' => 503,
        'data_loss' => 500,
        'unauthenticated' => 401,
    ];

    public function __construct(public readonly string $errorCode, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', $message);
    }

    public static function invalidArgument(string $message): self
    {
        return new self('invalid_argument', $message);
    }

    public static function permissionDenied(string $message): self
    {
        return new self('permission_denied', $message);
    }

    public static function failedPrecondition(string $message): self
    {
        return new self('failed_precondition', $message);
    }

    public static function unauthenticated(string $message): self
    {
        return new self('unauthenticated', $message);
    }

    public static function internal(string $message, ?Throwable $previous = null): self
    {
        return new self('internal', $message, $previous);
    }

    public function httpStatus(): int
    {
        return self::STATUSES[$this->errorCode] ?? 500;
    }

    /** @return array{code: string, message: string} */
    public function toConnectError(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage()];
    }
}
