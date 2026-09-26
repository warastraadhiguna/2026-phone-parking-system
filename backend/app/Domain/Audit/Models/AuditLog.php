<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable audit record. Create only through App\Domain\Audit\Actions\RecordAuditEvent.
 * UPDATE/DELETE are refused here and by database triggers (ADR-0007).
 *
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property array<string, mixed> $metadata
 * @property string|null $ip_address
 * @property string|null $device_uuid
 * @property string|null $request_id
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'actor_type' => ActorType::class,
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
