<?php

namespace App\Http\Api\V1\Shift;

use App\Domain\Shift\Data\ShiftEndData;
use App\Http\Api\V1\DeviceRequest;

final class EndShiftRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shift_uuid' => ['required', 'uuid'],
            'ended_at_device' => self::DEVICE_TIME,
            ...$this->gpsRules(),
        ];
    }

    public function toData(): ShiftEndData
    {
        return new ShiftEndData(
            strtolower((string) $this->input('shift_uuid')),
            $this->deviceTime('ended_at_device'),
            $this->gps(),
        );
    }
}
