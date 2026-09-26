<?php

namespace App\Http\Middleware;

use App\Domain\Device\Services\DeviceAccess;
use App\Domain\Identity\Models\User;
use App\Support\RequestContext\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operational mobile endpoints (shift, transactions, sync): the device behind the access token
 * must be ACTIVE and its attendant operational. Use after `auth:sanctum` and `mobile.attendant`.
 */
class EnsureActiveDevice
{
    /** Request attribute holding the verified App\Domain\Device\Models\Device. */
    public const DEVICE_ATTRIBUTE = 'device';

    public function __construct(private readonly DeviceAccess $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $device = $this->devices->assertOperational($user, RequestContext::deviceUuid());
        $request->attributes->set(self::DEVICE_ATTRIBUTE, $device);

        return $next($request);
    }
}
