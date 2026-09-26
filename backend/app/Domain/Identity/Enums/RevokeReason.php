<?php

namespace App\Domain\Identity\Enums;

/** Why a mobile refresh token was revoked (mobile_refresh_tokens.revoke_reason). */
enum RevokeReason: string
{
    case LOGOUT = 'LOGOUT';
    case RELOGIN = 'RELOGIN';
    case SUPERSEDED = 'SUPERSEDED';
    case REUSE_DETECTED = 'REUSE_DETECTED';
    case ACCOUNT_DISABLED = 'ACCOUNT_DISABLED';
    case PASSWORD_RESET = 'PASSWORD_RESET';
    case ADMIN_REVOKED = 'ADMIN_REVOKED';
    case DEVICE_DEACTIVATED = 'DEVICE_DEACTIVATED';
    case DEVICE_NOT_ALLOWED = 'DEVICE_NOT_ALLOWED';
}
