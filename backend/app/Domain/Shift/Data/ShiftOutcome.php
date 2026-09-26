<?php

namespace App\Domain\Shift\Data;

use App\Domain\Shift\Models\Shift;

/** $created is false when the request was an idempotent replay of an earlier one. */
final class ShiftOutcome
{
    public function __construct(
        public readonly Shift $shift,
        public readonly bool $created,
    ) {}
}
