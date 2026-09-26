<?php

namespace App\Domain\Identity\Exceptions;

use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;

/** Deliberately generic: never reveals whether the username exists or which check failed. */
final class InvalidCredentials extends ApiException
{
    public function __construct()
    {
        parent::__construct(ErrorCode::AUTH_INVALID);
    }
}
