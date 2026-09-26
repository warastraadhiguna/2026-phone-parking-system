<?php

namespace App\Http\Admin\Audit;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogBrowser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only audit log viewer (master doc §27: immutable, no edit UI, no delete). audit.view.
 */
final class AuditLogController
{
    public function index(Request $request, AuditLogBrowser $browser): Response
    {
        /** @var array{action?: string|null, actor?: string|null, entity_type?: string|null, entity_id?: string|null, request_id?: string|null, date?: string|null} $filters */
        $filters = $request->validate([
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'actor' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Inertia::render('Audit/Index', [
            'logs' => $browser->paginate($filters),
            'filters' => array_merge(array_fill_keys(['action', 'actor', 'entity_type', 'entity_id', 'request_id', 'date'], ''), array_map('strval', array_filter($filters))),
            'actions' => array_map(fn (AuditAction $a) => $a->value, AuditAction::cases()),
        ]);
    }
}
