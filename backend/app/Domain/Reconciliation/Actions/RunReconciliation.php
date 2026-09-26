<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Reconciliation\Enums\LineDimension;
use App\Domain\Reconciliation\Enums\Severity;
use App\Domain\Reconciliation\Internal\ReconciliationCalculator;
use App\Domain\Reconciliation\Models\ReconciliationLine;
use App\Domain\Reconciliation\Models\ReconciliationMismatch;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Support\Errors\RuleViolation;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reconciles one WIB business date (master doc §14; ADR-0012) and stores the result as a new,
 * immutable run. Reading and storing happen in one REPEATABLE READ transaction, so every figure
 * comes from the same snapshot. Running a date again adds a new run; old runs stay as history.
 */
final class RunReconciliation
{
    public function __construct(
        private readonly ReconciliationCalculator $calculator,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $businessDate, ?User $actor = null): ReconciliationRun
    {
        $start = CarbonImmutable::parse($businessDate, BusinessTime::timezone())->startOfDay();
        if ($start->greaterThan(CarbonImmutable::now(BusinessTime::timezone()))) {
            throw new RuleViolation('business_date', 'Tanggal rekonsiliasi tidak boleh di masa depan.');
        }
        $end = $start->addDay();

        return DB::transaction(function () use ($start, $end, $actor) {
            // Consistent snapshot. (Inside an outer transaction, e.g. in tests, the outer level applies.)
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            $result = $this->calculator->compute($start->utc(), $end->utc());

            $totals = array_fill_keys(ReconciliationRun::METRICS, 0);
            foreach ($result['attendants'] as $row) {
                foreach (ReconciliationRun::METRICS as $metric) {
                    $totals[$metric] += (int) ($row[$metric] ?? 0);
                }
            }
            $errors = count(array_filter($result['mismatches'], fn (array $m) => $m['severity'] === Severity::ERROR->value));

            $run = ReconciliationRun::create([
                'run_uuid' => (string) Str::uuid(),
                'business_date' => $start->toDateString(),
                'window_start' => $start->utc(),
                'window_end' => $end->utc(),
                'run_by' => $actor?->id,
                ...$totals,
                'mismatch_count' => count($result['mismatches']),
                'error_count' => $errors,
            ]);

            $lines = [];
            foreach (['attendants' => LineDimension::ATTENDANT, 'locations' => LineDimension::LOCATION] as $group => $dimension) {
                foreach ($result[$group] as $id => $row) {
                    $lines[] = [
                        'run_id' => $run->id,
                        'dimension' => $dimension->value,
                        'dimension_id' => $id,
                        'label' => mb_substr((string) $row['label'], 0, 150),
                        ...array_intersect_key($row, array_flip(ReconciliationRun::METRICS)),
                    ];
                }
            }
            foreach (array_chunk($lines, 500) as $chunk) {
                ReconciliationLine::query()->insert(array_map(fn (array $l) => $this->withNullCash($l), $chunk));
            }
            foreach (array_chunk($result['mismatches'], 500) as $chunk) {
                ReconciliationMismatch::query()->insert(array_map(fn (array $m) => ['run_id' => $run->id, ...$m], $chunk));
            }

            $this->audit->handle(AuditAction::RECONCILIATION_RUN, $actor, 'reconciliation_run', $run->run_uuid, [
                'business_date' => $start->toDateString(),
                'total_revenue' => $totals['total_revenue'],
                'cash_outstanding' => $totals['cash_outstanding'],
                'qris_difference' => $totals['qris_difference'],
                'mismatches' => count($result['mismatches']),
                'errors' => $errors,
            ]);

            return $run;
        });
    }

    /**
     * Location lines carry no cash-ledger figures (cash is held by attendants).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function withNullCash(array $line): array
    {
        foreach (['ledger_cash_in', 'ledger_reversals', 'cash_deposited', 'cash_outstanding'] as $column) {
            $line[$column] ??= null;
        }

        return $line;
    }
}
