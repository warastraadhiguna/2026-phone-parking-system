<?php

namespace App\Http\Api\V1\Shift;

use App\Domain\Shift\Data\ShiftStartData;
use App\Http\Api\V1\DeviceRequest;

final class StartShiftRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shift_uuid' => ['required', 'uuid'],
            'location_id' => ['required', 'integer', 'min:1'],
            'started_at_device' => self::DEVICE_TIME,
            'offline_created' => ['sometimes', 'boolean'],
            ...$this->gpsRules(),
        ];
    }

    public function toData(): ShiftStartData
    {
        return new ShiftStartData(
            strtolower((string) $this->input('shift_uuid')),
            $this->integer('location_id'),
            $this->deviceTime('started_at_device'),
            $this->gps(),
            $this->boolean('offline_created'),
        );
    }
}
