<?php

namespace App\Http\Api\V1\ParkingTransaction;

use App\Domain\ParkingTransaction\Data\CashTransactionData;
use App\Http\Api\V1\DeviceRequest;

final class StoreTransactionRequest extends DeviceRequest
{
    protected function prepareForValidation(): void
    {
        $this->replace(CashTransactionPayload::normalise($this->all()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CashTransactionPayload::rules();
    }

    public function toData(): CashTransactionData
    {
        return CashTransactionPayload::toData($this->all());
    }
}
