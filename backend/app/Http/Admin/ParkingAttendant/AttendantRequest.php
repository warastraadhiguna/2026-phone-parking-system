<?php

namespace App\Http\Admin\ParkingAttendant;

use App\Domain\ParkingAttendant\Data\AttendantData;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class AttendantRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'identity_number' => preg_replace('/\D/', '', (string) $this->input('identity_number')),
            'phone' => preg_replace('/[\s\-().]/', '', (string) $this->input('phone')),
            'expired_at' => $this->filled('expired_at') ? $this->input('expired_at') : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ParkingAttendant|null $attendant */
        $attendant = $this->route('attendant');

        return [
            'name' => ['required', 'string', 'max:150'],
            'identity_number' => ['required', 'digits:16', Rule::unique('parking_attendants', 'identity_number')->ignore($attendant?->id)],
            'phone' => ['required', 'regex:/^\+?[0-9]{8,15}$/'],
            'registered_at' => ['required', 'date_format:Y-m-d'],
            'expired_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:registered_at'],
            'password' => $attendant === null ? ['required', 'confirmed', Password::defaults()] : ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama', 'identity_number' => 'NIK', 'phone' => 'nomor telepon',
            'registered_at' => 'tanggal registrasi', 'expired_at' => 'berlaku sampai', 'password' => 'kata sandi',
        ];
    }

    public function toData(): AttendantData
    {
        return new AttendantData(
            (string) $this->string('name'),
            (string) $this->input('identity_number'),
            (string) $this->input('phone'),
            (string) $this->input('registered_at'),
            $this->input('expired_at'),
        );
    }
}
