<?php

namespace App\Support\Errors;

use RuntimeException;
use Throwable;

/**
 * An expected, client-facing failure carrying a stable ErrorCode.
 * Rendered by ApiExceptionRenderer into the standard error envelope.
 */
class ApiException extends RuntimeException
{
    public readonly int $status;

    /**
     * @param  array<string, mixed>  $details  Safe, client-facing details only.
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $details = [],
        ?int $status = null,
        ?Throwable $previous = null,
    ) {
        $this->status = $status ?? $errorCode->httpStatus();

        parent::__construct($message ?? $errorCode->defaultMessage(), 0, $previous);
    }
}
