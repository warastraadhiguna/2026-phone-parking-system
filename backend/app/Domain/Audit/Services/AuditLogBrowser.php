<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only access to the audit trail for viewers outside this module. It offers no way to
 * change a record (master doc §27); writes go only through RecordAuditEvent.
 */
final class AuditLogBrowser
{
    /**
     * @param  array{action?: string|null, actor?: string|null, entity_type?: string|null, entity_id?: string|null, request_id?: string|null, date?: string|null}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return AuditLog::query()
            ->when($filters['action'] ?? null, fn ($q, string $v) => $q->where('action', $v))
            ->when($filters['actor'] ?? null, fn ($q, string $v) => $q->where('actor_label', 'ilike', '%'.addcslashes($v, '%_\\').'%'))
            ->when($filters['entity_type'] ?? null, fn ($q, string $v) => $q->where('entity_type', $v))
            ->when($filters['entity_id'] ?? null, fn ($q, string $v) => $q->where('entity_id', $v))
            ->when($filters['request_id'] ?? null, fn ($q, string $v) => $q->where('request_id', $v))
            ->when($filters['date'] ?? null, function ($q, string $date) {
                $start = CarbonImmutable::parse($date, BusinessTime::timezone())->startOfDay()->utc();
                $q->where('occurred_at', '>=', $start)->where('occurred_at', '<', $start->addDay());
            })
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (AuditLog $l) => [
                'id' => $l->id,
                'occurred_at' => $l->occurred_at->toIso8601String(),
                'actor_type' => $l->actor_type->value,
                'actor' => $l->actor_label,
                'action' => $l->action,
                'entity_type' => $l->entity_type,
                'entity_id' => $l->entity_id,
                'metadata' => $l->metadata,
                'ip_address' => $l->ip_address,
                'device_uuid' => $l->device_uuid,
                'request_id' => $l->request_id,
            ]);
    }

    /**
     * Rows for the audit-log report (read-only, streamed; metadata excluded from exports).
     *
     * @return iterable<array<string, scalar|null>>
     */
    public function export(CarbonImmutable $start, CarbonImmutable $end): iterable
    {
        $query = AuditLog::query()->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->orderBy('id');
        foreach ($query->lazyById(1000) as $l) {
            yield [
                'occurred_at' => $l->occurred_at->toIso8601String(),
                'actor' => $l->actor_label ?? strtolower($l->actor_type->value),
                'action' => $l->action,
                'entity_type' => $l->entity_type,
                'entity_id' => $l->entity_id,
                'ip_address' => $l->ip_address,
                'request_id' => $l->request_id,
            ];
        }
    }
}
