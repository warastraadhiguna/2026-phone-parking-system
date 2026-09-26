<?php

namespace App\Http\Api\V1;

use App\Support\Geo\GpsFix;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Shared validation for device-originated payloads: device timestamps with offset + GPS fix. */
abstract class DeviceRequest extends FormRequest
{
    /** Device timestamps must carry an explicit offset so they are unambiguous. */
    public const DEVICE_TIME = ['required', 'date', 'regex:/(Z|[+-]\d{2}:\d{2})$/'];

    /** @return array<string, list<string>> */
    public static function gpsRules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'between:0,100000'],
            'mock_location' => ['sometimes', 'boolean'],
        ];
    }

    /** @param  array<string, mixed>  $input */
    public static function gpsFrom(array $input): GpsFix
    {
        $num = fn (string $key, int $precision) => isset($input[$key]) && $input[$key] !== '' ? round((float) $input[$key], $precision) : null;

        return new GpsFix(
            $num('latitude', 7),
            $num('longitude', 7),
            $num('gps_accuracy_m', 2),
            filter_var($input['mock_location'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public static function timeFrom(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
    }

    public function gps(): GpsFix
    {
        return self::gpsFrom($this->all());
    }

    protected function deviceTime(string $key): CarbonImmutable
    {
        return self::timeFrom((string) $this->input($key));
    }
}
