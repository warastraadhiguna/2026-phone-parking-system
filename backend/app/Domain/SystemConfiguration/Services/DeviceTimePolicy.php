<?php

namespace App\Domain\SystemConfiguration\Services;

use App\Domain\SystemConfiguration\Enums\SettingKey;
use Carbon\CarbonImmutable;

/**
 * Judges a device-reported time against server time (ADR-0008), for shifts and transactions.
 *
 * - Online: device time off by more than max_clock_skew_minutes → clock skew.
 * - Offline: device time in the future beyond the skew → clock skew; older than
 *   offline_transaction_warning_hours → stale (accepted, flagged).
 */
final class DeviceTimePolicy
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array{clock_skew: bool, stale: bool} */
    public function assess(CarbonImmutable $deviceTime, bool $offline, CarbonImmutable $serverNow): array
    {
        $skew = $this->settings->int(SettingKey::MAX_CLOCK_SKEW_MINUTES);
        $minutesAhead = $serverNow->diffInMinutes($deviceTime, false);

        if (! $offline) {
            return ['clock_skew' => abs($minutesAhead) > $skew, 'stale' => false];
        }

        return [
            'clock_skew' => $minutesAhead > $skew,
            'stale' => -$minutesAhead > $this->settings->int(SettingKey::OFFLINE_TRANSACTION_WARNING_HOURS) * 60,
        ];
    }
}
