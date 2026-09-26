<?php

namespace App\Domain\ParkingTransaction\Services;

use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Services\Settings;
use App\Support\Geo\GpsFix;
use Carbon\CarbonImmutable;

/**
 * "Impossible movement" basic rule (master doc Phase 9). Compares a new transaction with the
 * same attendant's closest earlier transaction that has a usable GPS fix (by device time). A jump
 * of at least movement_min_distance_m faster than movement_max_speed_kmh is flagged. Small jumps
 * are ignored, so GPS jitter never flags. Only a signal for review, never a rejection.
 */
final class MovementCheck
{
    public function __construct(private readonly Settings $settings) {}

    public function isImpossible(int $attendantId, GpsFix $fix, CarbonImmutable $at): bool
    {
        $maxAccuracy = $this->settings->int(SettingKey::GPS_MAX_ACCURACY_M);
        if (! $fix->hasPosition() || ($fix->accuracyM !== null && $fix->accuracyM > $maxAccuracy)) {
            return false;
        }

        $previous = ParkingTransaction::query()
            ->where('attendant_id', $attendantId)
            ->whereNotNull('latitude')
            ->where(fn ($q) => $q->whereNull('gps_accuracy_m')->orWhere('gps_accuracy_m', '<=', $maxAccuracy))
            ->where('transaction_time_device', '<=', $at)
            ->orderByDesc('transaction_time_device')
            ->first(['latitude', 'longitude', 'transaction_time_device']);
        if ($previous === null) {
            return false;
        }

        $distanceM = GpsFix::distanceM((float) $previous->latitude, (float) $previous->longitude, (float) $fix->latitude, (float) $fix->longitude);
        if ($distanceM < $this->settings->int(SettingKey::MOVEMENT_MIN_DISTANCE_M)) {
            return false;
        }

        $seconds = max(1, $previous->transaction_time_device->diffInSeconds($at, true));
        $speedKmh = ($distanceM / 1000) / ($seconds / 3600);

        return $speedKmh > $this->settings->int(SettingKey::MOVEMENT_MAX_SPEED_KMH);
    }
}
