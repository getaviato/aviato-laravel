<?php

namespace Aviato\Laravel\Exceptions;

use Throwable;

/**
 * A business error of a datasource handler, shown to the user with its meaning (HTTP 404, 422,
 * 409, 403):
 *
 *     throw new DatasourceException('A subject is required', 'invalid');
 */
class DatasourceException extends RpcException
{
    /** Kinds and their Connect error codes. */
    public const KINDS = [
        'not_found' => 'not_found',
        'invalid' => 'invalid_argument',
        'conflict' => 'already_exists',
        'forbidden' => 'permission_denied',
    ];

    /** @param 'not_found'|'invalid'|'conflict'|'forbidden' $kind */
    public function __construct(string $message, public readonly string $kind = 'invalid', ?Throwable $previous = null)
    {
        parent::__construct(self::KINDS[$kind], $message, $previous);
    }
}
