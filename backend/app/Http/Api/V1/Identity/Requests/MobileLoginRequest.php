<?php

namespace App\Http\Api\V1\Identity\Requests;

use App\Domain\Identity\Data\MobileDeviceInfo;
use Illuminate\Foundation\Http\FormRequest;

final class MobileLoginRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:128'],
            'device_uuid' => ['required', 'uuid'],
            'device_model' => ['nullable', 'string', 'max:100'],
            'android_version' => ['nullable', 'string', 'max:30'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function device(): MobileDeviceInfo
    {
        return new MobileDeviceInfo(
            strtolower((string) $this->string('device_uuid')),
            $this->input('device_model'),
            $this->input('android_version'),
            $this->input('app_version'),
        );
    }
}
