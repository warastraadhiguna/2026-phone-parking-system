<?php

namespace App\Domain\Identity\Exceptions;

use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;

/**
 * A user-administration rule was broken (e.g. deactivating the last Super Admin).
 * $field names the form field the message belongs to.
 */
final class UserRuleViolation extends ApiException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct(ErrorCode::CONFLICT, $message, ['field' => $field]);
    }
}
