<?php

namespace App\Http\Admin\ParkingLocation;

use App\Domain\ParkingLocation\Data\LocationData;
use App\Domain\ParkingLocation\Enums\LocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LocationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('location_code')) {
            $this->merge(['location_code' => strtoupper(trim((string) $this->input('location_code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->route('location') === null;

        return [
            'location_code' => $creating
                ? ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9-]{2,29}$/', 'unique:parking_locations,location_code']
                : ['prohibited'],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90', 'decimal:0,7'],
            'longitude' => ['required', 'numeric', 'between:-180,180', 'decimal:0,7'],
            'geofence_radius_m' => ['required', 'integer', 'between:5,1000'],
            'location_type' => ['required', Rule::enum(LocationType::class)],
            'motorcycle_capacity' => ['required', 'integer', 'between:0,100000'],
            'car_capacity' => ['required', 'integer', 'between:0,100000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'location_code' => 'kode lokasi', 'name' => 'nama', 'address' => 'alamat',
            'latitude' => 'lintang', 'longitude' => 'bujur', 'geofence_radius_m' => 'radius geofence',
            'location_type' => 'jenis lokasi', 'motorcycle_capacity' => 'kapasitas motor', 'car_capacity' => 'kapasitas mobil',
        ];
    }

    public function toData(): LocationData
    {
        return new LocationData(
            (string) $this->string('name'),
            (string) $this->string('address'),
            (string) $this->input('latitude'),
            (string) $this->input('longitude'),
            $this->integer('geofence_radius_m'),
            LocationType::from((string) $this->string('location_type')),
            $this->integer('motorcycle_capacity'),
            $this->integer('car_capacity'),
        );
    }
}
