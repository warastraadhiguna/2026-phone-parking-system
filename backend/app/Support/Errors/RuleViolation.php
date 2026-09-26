<?php

namespace App\Support\Errors;

/**
 * A business rule rejected the request. On the admin web it becomes a form error on $field;
 * on the API it is the standard error envelope with details.field.
 */
class RuleViolation extends ApiException
{
    public function __construct(
        public readonly string $field,
        string $message,
        ErrorCode $code = ErrorCode::CONFLICT,
    ) {
        parent::__construct($code, $message, ['field' => $field]);
    }
}
