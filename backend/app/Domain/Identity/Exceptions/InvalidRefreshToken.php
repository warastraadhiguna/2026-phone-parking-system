<?php

namespace App\Domain\Identity\Exceptions;

use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;

/** Unknown, expired, revoked or reused refresh token. The app must ask the user to log in again. */
final class InvalidRefreshToken extends ApiException
{
    public function __construct()
    {
        parent::__construct(ErrorCode::AUTH_INVALID, 'The refresh token is invalid. Please log in again.');
    }
}
