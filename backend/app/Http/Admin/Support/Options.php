<?php

namespace App\Http\Admin\Support;

use BackedEnum;

/** Serialises labelled enums for Inertia pages: [{ value, label }, …]. */
final class Options
{
    /**
     * @param  class-string<BackedEnum>  $enum
     * @return list<array{value: string, label: string}>
     */
    public static function of(string $enum): array
    {
        return array_map(fn (BackedEnum $case) => self::one($case), $enum::cases());
    }

    /** @return array{value: string, label: string} */
    public static function one(BackedEnum $case): array
    {
        return [
            'value' => (string) $case->value,
            'label' => method_exists($case, 'label') ? (string) $case->label() : (string) $case->value,
        ];
    }
}
