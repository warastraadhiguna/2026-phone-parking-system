<?php

namespace App\Domain\FraudReview\Enums;

/**
 * Priority of a review item, derived from its signal code. HIGH signals point at possible
 * fraud or money problems; LOW ones are mostly operational noise worth a glance.
 */
enum ReviewSeverity: string
{
    case HIGH = 'HIGH';
    case MEDIUM = 'MEDIUM';
    case LOW = 'LOW';

    private const HIGH_CODES = [
        'MOCK_LOCATION', 'IMPOSSIBLE_MOVEMENT', 'LATE_PAYMENT', 'PAYMENT_AMOUNT_MISMATCH', 'DEVICE_MISMATCH',
    ];

    private const LOW_CODES = ['CLOCK_SKEW', 'STALE_OFFLINE', 'OVERDUE', 'LOCATION_INACTIVE'];

    public static function forFlag(string $code): self
    {
        return match (true) {
            in_array($code, self::HIGH_CODES, true) => self::HIGH,
            in_array($code, self::LOW_CODES, true) => self::LOW,
            default => self::MEDIUM,
        };
    }

    /** SQL CASE for a flag-code column, from the same lists (the collector runs in SQL). */
    public static function sqlCaseForFlag(string $column): string
    {
        $list = fn (array $codes) => implode(', ', array_map(fn (string $c) => "'{$c}'", $codes));

        return "CASE WHEN {$column} IN (".$list(self::HIGH_CODES).") THEN 'HIGH' WHEN {$column} IN (".$list(self::LOW_CODES).") THEN 'LOW' ELSE 'MEDIUM' END";
    }

    public function label(): string
    {
        return match ($this) {
            self::HIGH => 'Tinggi',
            self::MEDIUM => 'Sedang',
            self::LOW => 'Rendah',
        };
    }
}
