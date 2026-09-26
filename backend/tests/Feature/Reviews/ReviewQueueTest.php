<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\FraudReview\Actions\CollectAnomalies;
use App\Domain\FraudReview\Enums\ReviewSeverity;
use App\Domain\FraudReview\Enums\ReviewStatus;
use App\Domain\FraudReview\Models\AnomalyReview;
use App\Domain\Identity\Enums\Role;
use App\Domain\ParkingTransaction\Enums\TransactionFlag;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\Reconciliation\Actions\RunReconciliation;
use App\Support\Time\BusinessTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Phase 9: impossible-movement rule, review queue, audit log viewer.
*/

beforeEach(function () {
    arrangeCashTest($this);
    syncRoles();
    $this->supervisor = staffUser(Role::SUPERVISOR);
});

/** Location of makeLocation() is around (-6.7551, 111.0380). ~0.09° latitude ≈ 10 km. */
function txAt(float $lat, float $lng, string $time, array $extra = []): ParkingTransaction
{
    $uuid = txApi('POST', '/api/v1/parking-transactions', cashPayload(['latitude' => $lat, 'longitude' => $lng, 'transaction_time_device' => $time, ...$extra]))
        ->assertCreated()->json('data.transaction.transaction_uuid');

    return ParkingTransaction::where('transaction_uuid', $uuid)->sole();
}

describe('impossible movement', function () {
    it('flags a jump that is too fast, but not GPS jitter or a plausible trip', function () {
        $t0 = now()->subMinutes(30);
        txAt(-6.7551, 111.0380, $t0->toIso8601String());

        $jitter = txAt(-6.7560, 111.0385, $t0->addMinutes(1)->toIso8601String());           // ~110 m in 1 min
        $jump = txAt(-6.8451, 111.0380, $t0->addMinutes(2)->toIso8601String());             // ~10 km in 1 min
        $trip = txAt(-6.8460, 111.0380, $t0->addMinutes(3)->toIso8601String());             // from the jump point: ~100 m

        expect($jitter->review_flags)->not->toContain('IMPOSSIBLE_MOVEMENT')
            ->and($jump->review_flags)->toContain('IMPOSSIBLE_MOVEMENT')
            ->and($trip->review_flags)->not->toContain('IMPOSSIBLE_MOVEMENT');
    });

    it('accepts the same distance over a realistic time', function () {
        $t0 = now()->subHours(3);
        txAt(-6.7551, 111.0380, $t0->toIso8601String(), ['offline_created' => true]);
        $later = txAt(-6.8451, 111.0380, $t0->addHours(2)->toIso8601String(), ['offline_created' => true]); // 10 km in 2 h = 5 km/h

        expect($later->review_flags)->not->toContain('IMPOSSIBLE_MOVEMENT');
    });
});

it('collects review flags and reconciliation mismatches into the queue, idempotently', function () {
    $mock = txAt(-6.7551, 111.0380, now()->toIso8601String(), ['mock_location' => true]);
    DB::update("UPDATE shifts SET review_flags = '[\"OVERDUE\"]'");
    DB::update('UPDATE attendant_cash_balances SET balance = balance + 5');
    app(RunReconciliation::class)->handle(now(BusinessTime::timezone())->toDateString());

    $added = app(CollectAnomalies::class)->handle();
    expect($added)->toBe(['transactions' => 1, 'shifts' => 1, 'reconciliation' => 1])
        ->and(app(CollectAnomalies::class)->handle())->toBe(['transactions' => 0, 'shifts' => 0, 'reconciliation' => 0]);

    $item = AnomalyReview::where('code', 'MOCK_LOCATION')->sole();
    expect($item->severity)->toBe(ReviewSeverity::HIGH)
        ->and($item->entity_key)->toBe($mock->id)
        ->and($item->reference)->toBe($mock->transaction_number)
        ->and($item->attendant_id)->toBe($this->attendant->id)
        ->and(AnomalyReview::where('code', 'OVERDUE')->sole()->severity)->toBe(ReviewSeverity::LOW)
        ->and(AnomalyReview::where('code', 'BALANCE_DRIFT')->sole()->severity)->toBe(ReviewSeverity::HIGH);

    // A flag added later to the same transaction becomes its own item.
    $mock->withFlags([TransactionFlag::LATE_PAYMENT])->save();
    expect(app(CollectAnomalies::class)->handle()['transactions'])->toBe(1);
});

it('lets a supervisor record a final finding with a note', function () {
    txAt(-6.7551, 111.0380, now()->toIso8601String(), ['mock_location' => true]);
    app(CollectAnomalies::class)->handle();
    $item = AnomalyReview::where('code', 'MOCK_LOCATION')->sole();

    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->put("/reviews/{$item->id}", ['decision' => 'CONFIRMED', 'decision_note' => 'Aplikasi palsu'])->assertForbidden();
    $this->actingAs($this->supervisor)->put("/reviews/{$item->id}", ['decision' => 'CONFIRMED', 'decision_note' => ''])->assertSessionHasErrors('decision_note');
    $this->actingAs($this->supervisor)->put("/reviews/{$item->id}", ['decision' => 'CONFIRMED', 'decision_note' => 'Aplikasi lokasi palsu terpasang'])->assertSessionHasNoErrors();

    $item->refresh();
    expect($item->status)->toBe(ReviewStatus::CONFIRMED)
        ->and($item->decided_by)->toBe($this->supervisor->id)
        ->and(auditOf(AuditAction::ANOMALY_REVIEWED)->sole()->metadata['decision'])->toBe('CONFIRMED');

    $this->actingAs($this->supervisor)->put("/reviews/{$item->id}", ['decision' => 'DISMISSED', 'decision_note' => 'Berubah pikiran'])->assertSessionHasErrors('decision');

    foreach (["UPDATE anomaly_reviews SET status = 'DISMISSED' WHERE id = {$item->id}", "UPDATE anomaly_reviews SET code = 'X' WHERE id = {$item->id}", "DELETE FROM anomaly_reviews WHERE id = {$item->id}"] as $sql) {
        try {
            DB::transaction(fn () => DB::statement($sql));
            $this->fail("Expected refusal: {$sql}");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23001');
        }
    }
});

it('shows the queue sorted by severity with open counts', function () {
    txAt(-6.7551, 111.0380, now()->toIso8601String(), ['mock_location' => true]);
    DB::update("UPDATE shifts SET review_flags = '[\"CLOCK_SKEW\"]'");
    app(CollectAnomalies::class)->handle();

    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->get('/reviews')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Reviews/Index')
            ->where('openCounts.HIGH', 1)->where('openCounts.LOW', 1)
            ->where('items.data.0.code', 'MOCK_LOCATION')
            ->where('can.review', false)
            ->where('items.data.0.href', '/transactions/'.ParkingTransaction::sole()->id));
    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get('/reviews')->assertForbidden();
});

it('offers a read-only audit log to auditors', function () {
    txAt(-6.7551, 111.0380, now()->toIso8601String());

    $this->actingAs(staffUser(Role::AUDITOR))->get('/audit-logs?action=CREATE_TRANSACTION')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Audit/Index')->has('logs.data', 1)->where('logs.data.0.action', 'CREATE_TRANSACTION'));
    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->get('/audit-logs')->assertForbidden();

    // No write routes exist for audit logs.
    $this->actingAs(staffUser(Role::SUPER_ADMIN))->delete('/audit-logs/1')->assertNotFound();
});
