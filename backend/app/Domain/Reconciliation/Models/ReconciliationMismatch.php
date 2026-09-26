<?php

namespace App\Domain\Reconciliation\Models;

use App\Domain\Reconciliation\Enums\MismatchCode;
use App\Domain\Reconciliation\Enums\Severity;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $run_id
 * @property MismatchCode $code
 * @property Severity $severity
 * @property string $entity_type
 * @property string $entity_id
 * @property string|null $reference
 * @property int|null $expected_amount
 * @property int|null $actual_amount
 * @property array<string, mixed> $details
 */
class ReconciliationMismatch extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'code' => MismatchCode::class,
            'severity' => Severity::class,
            'expected_amount' => 'integer',
            'actual_amount' => 'integer',
            'details' => 'array',
        ];
    }
}
