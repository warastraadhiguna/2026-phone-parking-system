<?php

namespace App\Http\Admin\Tariff;

use App\Domain\ParkingLocation\Enums\LocationType;
use App\Domain\Tariff\Data\TariffData;
use App\Domain\Tariff\Enums\VehicleType;
use App\Support\Time\BusinessTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TariffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['location_id' => $this->filled('location_id') ? $this->input('location_id') : null]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'location_type' => ['required', Rule::enum(LocationType::class)],
            'location_id' => ['nullable', 'integer', 'exists:parking_locations,id'],
            // Whole rupiah. The upper bound only catches typing mistakes.
            'amount' => ['required', 'integer', 'between:1,10000000'],
            // Local WIB date-time from <input type="datetime-local">.
            'effective_from' => ['required', 'date_format:Y-m-d\TH:i'],
            'regulation_reference' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'vehicle_type' => 'jenis kendaraan', 'location_type' => 'jenis lokasi', 'location_id' => 'lokasi',
            'amount' => 'nominal', 'effective_from' => 'mulai berlaku', 'regulation_reference' => 'dasar hukum',
        ];
    }

    public function toData(): TariffData
    {
        return new TariffData(
            VehicleType::from((string) $this->string('vehicle_type')),
            LocationType::from((string) $this->string('location_type')),
            $this->filled('location_id') ? $this->integer('location_id') : null,
            $this->integer('amount'),
            BusinessTime::fromLocal((string) $this->string('effective_from')),
            (string) $this->string('regulation_reference'),
        );
    }
}
