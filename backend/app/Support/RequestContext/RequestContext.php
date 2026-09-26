<?php

namespace App\Support\RequestContext;

use Illuminate\Support\Facades\Context;

/**
 * Per-request facts that domain code may need (e.g. for audit records) without depending on HTTP.
 *
 * Stored as *hidden* Laravel Context: carried into queued jobs, but not added to log lines.
 * Populated by App\Http\Middleware\CaptureRequestContext.
 */
final class RequestContext
{
    private const CLIENT_IP = 'client_ip';

    private const DEVICE_UUID = 'device_uuid';

    public static function setClientIp(?string $ip): void
    {
        Context::addHidden(self::CLIENT_IP, $ip);
    }

    public static function clientIp(): ?string
    {
        $value = Context::getHidden(self::CLIENT_IP);

        return is_string($value) ? $value : null;
    }

    public static function setDeviceUuid(?string $deviceUuid): void
    {
        Context::addHidden(self::DEVICE_UUID, $deviceUuid);
    }

    public static function deviceUuid(): ?string
    {
        $value = Context::getHidden(self::DEVICE_UUID);

        return is_string($value) ? $value : null;
    }
}
