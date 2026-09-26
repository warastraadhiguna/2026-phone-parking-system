<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Data\MobileTokenName;
use App\Domain\Identity\Exceptions\AccountDisabled;
use App\Domain\Identity\Models\User;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\RequestContext\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile API (after auth:sanctum): the token must be a mobile token of an ACTIVE attendant.
 * Also records the token's device in the request context for the audit trail.
 */
class EnsureMobileAttendant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken || ! $token->can(MobileTokenName::ABILITY) || ! $user->isAttendant()) {
            throw new ApiException(ErrorCode::FORBIDDEN);
        }

        if (! $user->isActive()) {
            throw new AccountDisabled;
        }

        RequestContext::setDeviceUuid(MobileTokenName::deviceUuid($token->name));

        return $next($request);
    }
}
