<?php

namespace App\Http\Api\V1\Identity\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MobileRefreshRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'max:200'],
            'device_uuid' => ['required', 'uuid'],
        ];
    }
}
