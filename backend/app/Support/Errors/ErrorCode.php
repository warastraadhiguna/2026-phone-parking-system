<?php

namespace App\Support\Errors;

/**
 * Stable, machine-readable API error codes (master doc §43).
 *
 * Clients must branch on these codes, never on message text.
 * Codes are part of the public API contract: never rename or reuse one.
 */
enum ErrorCode: string
{
    // Generic / transport
    case BAD_REQUEST = 'BAD_REQUEST';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    case CONFLICT = 'CONFLICT';
    case RATE_LIMITED = 'RATE_LIMITED';
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    // Domain (master doc §43). Used by later phases.
    case AUTH_INVALID = 'AUTH_INVALID';
    case ACCOUNT_DISABLED = 'ACCOUNT_DISABLED';
    case DEVICE_NOT_ALLOWED = 'DEVICE_NOT_ALLOWED';
    case SHIFT_NOT_ACTIVE = 'SHIFT_NOT_ACTIVE';
    case SHIFT_ALREADY_OPEN = 'SHIFT_ALREADY_OPEN';
    case LOCATION_NOT_ALLOWED = 'LOCATION_NOT_ALLOWED';
    case TARIFF_NOT_FOUND = 'TARIFF_NOT_FOUND';
    case TRANSACTION_DUPLICATE = 'TRANSACTION_DUPLICATE';
    case PAYMENT_FAILED = 'PAYMENT_FAILED';
    case PAYMENT_EXPIRED = 'PAYMENT_EXPIRED';
    case SETTLEMENT_INVALID = 'SETTLEMENT_INVALID';
    case SYNC_CONFLICT = 'SYNC_CONFLICT';
    case TARIFF_CHANGED = 'TARIFF_CHANGED';

    public function httpStatus(): int
    {
        return match ($this) {
            self::BAD_REQUEST => 400,
            self::VALIDATION_FAILED, self::SETTLEMENT_INVALID => 422,
            self::UNAUTHENTICATED, self::AUTH_INVALID => 401,
            self::FORBIDDEN, self::ACCOUNT_DISABLED, self::DEVICE_NOT_ALLOWED, self::LOCATION_NOT_ALLOWED => 403,
            self::NOT_FOUND, self::TARIFF_NOT_FOUND => 404,
            self::METHOD_NOT_ALLOWED => 405,
            self::CONFLICT, self::SHIFT_NOT_ACTIVE, self::SHIFT_ALREADY_OPEN, self::TRANSACTION_DUPLICATE, self::SYNC_CONFLICT, self::TARIFF_CHANGED => 409,
            self::PAYMENT_FAILED, self::PAYMENT_EXPIRED => 422,
            self::RATE_LIMITED => 429,
            self::SERVICE_UNAVAILABLE => 503,
            self::INTERNAL_ERROR => 500,
        };
    }

    /** Safe, generic message. Never include internal details here. */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::BAD_REQUEST => 'The request could not be processed.',
            self::VALIDATION_FAILED => 'The given data was invalid.',
            self::UNAUTHENTICATED => 'Authentication is required.',
            self::FORBIDDEN => 'This action is not allowed.',
            self::NOT_FOUND => 'The requested resource was not found.',
            self::METHOD_NOT_ALLOWED => 'The HTTP method is not allowed for this resource.',
            self::CONFLICT => 'The request conflicts with the current state of the resource.',
            self::RATE_LIMITED => 'Too many requests. Please retry later.',
            self::SERVICE_UNAVAILABLE => 'The service is temporarily unavailable.',
            self::INTERNAL_ERROR => 'An unexpected error occurred.',
            self::AUTH_INVALID => 'Invalid credentials.',
            self::ACCOUNT_DISABLED => 'This account is not active.',
            self::DEVICE_NOT_ALLOWED => 'This device is not allowed.',
            self::SHIFT_NOT_ACTIVE => 'Active shift is required.',
            self::SHIFT_ALREADY_OPEN => 'Another shift is still open.',
            self::LOCATION_NOT_ALLOWED => 'This location is not allowed.',
            self::TARIFF_NOT_FOUND => 'No applicable tariff was found.',
            self::TRANSACTION_DUPLICATE => 'The transaction already exists.',
            self::PAYMENT_FAILED => 'The payment failed.',
            self::PAYMENT_EXPIRED => 'The payment has expired.',
            self::SETTLEMENT_INVALID => 'The settlement is invalid.',
            self::SYNC_CONFLICT => 'The synchronised data conflicts with the server record.',
            self::TARIFF_CHANGED => 'The tariff has changed; refresh and try again.',
        };
    }
}
