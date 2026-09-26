<?php

namespace App\Domain\ParkingAttendant\Data;

/** Editable attendant attributes. Dates are Y-m-d (WIB calendar days). */
final class AttendantData
{
    public function __construct(
        public readonly string $name,
        #[\SensitiveParameter] public readonly string $identityNumber,
        public readonly string $phone,
        public readonly string $registeredAt,
        public readonly ?string $expiredAt,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'name' => trim($this->name),
            'identity_number' => $this->identityNumber,
            'phone' => $this->phone,
            'registered_at' => $this->registeredAt,
            'expired_at' => $this->expiredAt,
        ];
    }
}
