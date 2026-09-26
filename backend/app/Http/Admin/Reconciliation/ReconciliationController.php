<?php

namespace App\Http\Admin\Reconciliation;

use App\Domain\Identity\Models\User;
use App\Domain\Reconciliation\Actions\RunReconciliation;
use App\Domain\Reconciliation\Enums\LineDimension;
use App\Domain\Reconciliation\Models\ReconciliationLine;
use App\Domain\Reconciliation\Models\ReconciliationMismatch;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Http\Admin\Support\Options;
use App\Support\Time\BusinessTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Control Center: reconciliation runs (master doc §14). Viewing: reconciliation.view.
 * Running: reconciliation.run (Finance). Runs are immutable snapshots.
 */
final class ReconciliationController
{
    public function index(Request $request): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $runs = ReconciliationRun::query()->with('runner:id,username')
            ->orderByDesc('business_date')->orderByDesc('id')
            ->paginate(30)
            ->through(fn (ReconciliationRun $r) => $this->summary($r));

        return Inertia::render('Reconciliation/Index', [
            'runs' => $runs,
            'can' => ['run' => $viewer->can('reconciliation.run')],
            'yesterday' => now(BusinessTime::timezone())->subDay()->toDateString(),
        ]);
    }

    public function show(ReconciliationRun $run): Response
    {
        $run->load('runner:id,username');
        $line = fn (ReconciliationLine $l) => ['dimension_id' => $l->dimension_id, 'label' => $l->label, ...$l->only(ReconciliationRun::METRICS)];

        return Inertia::render('Reconciliation/Show', [
            'run' => $this->summary($run),
            'attendants' => $run->lines()->where('dimension', LineDimension::ATTENDANT->value)->orderBy('label')->get()->map($line),
            'locations' => $run->lines()->where('dimension', LineDimension::LOCATION->value)->orderBy('label')->get()->map($line),
            'mismatches' => $run->mismatches()->orderBy('severity')->orderBy('code')->get()->map(fn (ReconciliationMismatch $m) => [
                'id' => $m->id,
                'code' => $m->code->value,
                'label' => $m->code->label(),
                'severity' => Options::one($m->severity),
                'entity_type' => $m->entity_type,
                'entity_id' => $m->entity_id,
                'reference' => $m->reference,
                'expected_amount' => $m->expected_amount,
                'actual_amount' => $m->actual_amount,
            ]),
            'newerRun' => ReconciliationRun::query()->where('business_date', $run->business_date->toDateString())->where('id', '>', $run->id)->max('id'),
        ]);
    }

    public function store(Request $request, RunReconciliation $reconcile): RedirectResponse
    {
        $data = $request->validate(['business_date' => ['required', 'date_format:Y-m-d']], attributes: ['business_date' => 'tanggal']);
        /** @var User $actor */
        $actor = $request->user();

        $run = $reconcile->handle($data['business_date'], $actor);

        return redirect("/reconciliation/{$run->id}")->with('success', "Rekonsiliasi {$data['business_date']} selesai: {$run->mismatch_count} ketidaksesuaian.");
    }

    /** @return array<string, mixed> */
    private function summary(ReconciliationRun $r): array
    {
        $runner = $r->runner;

        return [
            'id' => $r->id,
            'business_date' => $r->business_date->toDateString(),
            'created_at' => $r->created_at->toIso8601String(),
            'run_by' => $runner instanceof User ? $runner->username : 'sistem (terjadwal)',
            'mismatch_count' => $r->mismatch_count,
            'error_count' => $r->error_count,
            ...$r->only(ReconciliationRun::METRICS),
        ];
    }
}
