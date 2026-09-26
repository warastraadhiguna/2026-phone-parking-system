<?php

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Support\Logging\SensitiveDataRedactor;
use App\Support\RequestContext\RequestContext;
use App\Support\RequestId\RequestId;

/**
 * The only way to write an audit record.
 *
 * Call it inside the same DB transaction as the change being audited, so that either both
 * are stored or neither is. Request ID, client IP and device come from the request context.
 */
final class RecordAuditEvent
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    /**
     * @param  array<string, mixed>  $metadata  Identifiers and changed fields. Never secrets.
     */
    public function handle(
        AuditAction $action,
        ?User $actor = null,
        ?string $entityType = null,
        string|int|null $entityId = null,
        array $metadata = [],
        ?ActorType $actorType = null,
        ?string $deviceUuid = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_type' => $actor !== null ? ActorType::USER : ($actorType ?? ActorType::SYSTEM),
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->username,
            'action' => $action->value,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            // Defence in depth: callers must not pass secrets, but never store one if they do.
            // An empty PHP array would be stored as a JSON list; the column holds objects.
            'metadata' => $metadata === [] ? new \stdClass : $this->redactor->redact($metadata),
            'ip_address' => RequestContext::clientIp(),
            'device_uuid' => $deviceUuid ?? RequestContext::deviceUuid(),
            'request_id' => RequestId::current(),
        ]);
    }
}
