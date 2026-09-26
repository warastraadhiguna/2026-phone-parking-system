<?php

namespace App\Domain\Identity\Exceptions;

use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;

/** Correct credentials, but the account is INACTIVE or SUSPENDED. */
final class AccountDisabled extends ApiException
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ACCOUNT_DISABLED);
    }
}
