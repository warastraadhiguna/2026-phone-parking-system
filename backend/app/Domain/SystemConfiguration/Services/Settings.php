<?php

namespace App\Domain\SystemConfiguration\Services;

use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Domain\SystemConfiguration\Models\SystemSetting;

/**
 * Reads policy settings: the stored override, else the code default. Values are read from
 * PostgreSQL (the source of truth) and memoised for the current request/job only.
 */
final class Settings
{
    /** @var array<string, int>|null */
    private ?array $overrides = null;

    public function int(SettingKey $key): int
    {
        $this->overrides ??= SystemSetting::query()->pluck('value', 'key')
            ->map(fn ($v) => (int) $v)
            ->all();

        return $this->overrides[$key->value] ?? $key->default();
    }

    /** @return array<string, int> every setting with its effective value */
    public function all(): array
    {
        $values = [];
        foreach (SettingKey::cases() as $key) {
            $values[$key->value] = $this->int($key);
        }

        return $values;
    }

    public function forget(): void
    {
        $this->overrides = null;
    }
}
