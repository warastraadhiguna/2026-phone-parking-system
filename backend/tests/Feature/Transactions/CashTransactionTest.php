<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\Shift\Models\Shift;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => arrangeCashTest($this));

it('records a cash transaction with a tariff snapshot and a ledger cash-in (Scenario A)', function () {
    $response = txApi('POST', '/api/v1/parking-transactions', cashPayload())
        ->assertCreated()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.transaction.status', 'COMPLETED')
        ->assertJsonPath('data.transaction.charged_amount', 2000)
        ->assertJsonPath('data.transaction.expected_amount', 2000)
        ->assertJsonPath('data.transaction.difference_amount', 0)
        ->assertJsonPath('data.transaction.review_flags', [])
        ->assertJsonPath('data.cash_balance', 2000);

    expect($response->json('data.transaction.transaction_number'))->toMatch('/^TRX-\d{6}-\d{8}$/');

    $tx = ParkingTransaction::sole();
    expect($tx->tariff_id)->toBe($this->tariff->id)
        ->and($tx->shift->shift_uuid)->toBe(TX_SHIFT_UUID)
        ->and($tx->device_id)->toBe($this->device->id);

    $entry = CashLedgerEntry::sole();
    expect($entry->type)->toBe(LedgerEntryType::PARKING_CASH_IN)
        ->and($entry->amount)->toBe(2000)
        ->and($entry->balance_after)->toBe(2000)
        ->and($entry->transaction_id)->toBe($tx->id);

    $audit = auditOf(AuditAction::CREATE_TRANSACTION)->sole();
    expect($audit->metadata['ledger_entry_id'])->toBe($entry->id)->and($audit->device_uuid)->toBe(TEST_DEVICE_UUID);
});

it('never duplicates a retried transaction (Scenario E)', function () {
    $payload = cashPayload();

    txApi('POST', '/api/v1/parking-transactions', $payload)->assertCreated();
    txApi('POST', '/api/v1/parking-transactions', $payload)
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.cash_balance', 2000);

    expect(ParkingTransaction::count())->toBe(1)
        ->and(CashLedgerEntry::count())->toBe(1)
        ->and(auditOf(AuditAction::CREATE_TRANSACTION))->toHaveCount(1);
});

it('reports conflicts for a reused UUID or sync sequence', function () {
    $first = cashPayload();
    txApi('POST', '/api/v1/parking-transactions', $first)->assertCreated();

    txApi('POST', '/api/v1/parking-transactions', [...$first, 'charged_amount' => 5000])
        ->assertStatus(409)->assertJsonPath('error.code', 'SYNC_CONFLICT');
    txApi('POST', '/api/v1/parking-transactions', cashPayload(['sync_sequence' => $first['sync_sequence']]))
        ->assertStatus(409)->assertJsonPath('error.code', 'SYNC_CONFLICT');

    expect(ParkingTransaction::count())->toBe(1);
});

it('keeps what was really charged when the tariff changed, and flags it', function () {
    $this->tariff->forceFill(['effective_until' => now()->subMinutes(10)])->save();
    $new = tariffRow(3000, ['effective_from' => now()->subMinutes(10)]);

    txApi('POST', '/api/v1/parking-transactions', cashPayload(['charged_amount' => 2000, 'tariff_id' => $this->tariff->id]))
        ->assertCreated()
        ->assertJsonPath('data.transaction.charged_amount', 2000)
        ->assertJsonPath('data.transaction.expected_amount', 3000)
        ->assertJsonPath('data.transaction.difference_amount', 1000)
        ->assertJsonPath('data.transaction.tariff_id', $new->id)
        ->assertJsonPath('data.transaction.review_flags', ['TARIFF_MISMATCH']);

    // The ledger records the money that really changed hands.
    expect(CashLedgerEntry::sole()->amount)->toBe(2000);
});

it('prices an offline transaction at the time it happened', function () {
    $changeAt = now()->subMinutes(10);
    $this->tariff->forceFill(['effective_until' => $changeAt])->save();
    tariffRow(3000, ['effective_from' => $changeAt]);

    txApi('POST', '/api/v1/parking-transactions', cashPayload([
        'offline_created' => true,
        'transaction_time_device' => now()->subMinutes(30)->toIso8601String(),
    ]))->assertCreated()->assertJsonPath('data.transaction.expected_amount', 2000)->assertJsonPath('data.transaction.review_flags', []);
});

it('refuses an online transaction without an applicable tariff, but keeps an offline one', function () {
    txApi('POST', '/api/v1/parking-transactions', cashPayload(['vehicle_type' => 'CAR', 'charged_amount' => 5000]))
        ->assertNotFound()->assertJsonPath('error.code', 'TARIFF_NOT_FOUND');
    expect(ParkingTransaction::count())->toBe(0)->and(CashLedgerEntry::count())->toBe(0);

    txApi('POST', '/api/v1/parking-transactions', cashPayload(['vehicle_type' => 'CAR', 'charged_amount' => 5000, 'offline_created' => true]))
        ->assertCreated()
        ->assertJsonPath('data.transaction.expected_amount', null)
        ->assertJsonPath('data.transaction.review_flags', ['TARIFF_NOT_FOUND']);
});

it('requires an open shift online, and flags offline transactions outside their shift', function () {
    txApi('POST', '/api/v1/shifts/end', ['shift_uuid' => TX_SHIFT_UUID, 'ended_at_device' => now()->toIso8601String()])->assertOk();

    txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertStatus(409)->assertJsonPath('error.code', 'SHIFT_NOT_ACTIVE');
    txApi('POST', '/api/v1/parking-transactions', cashPayload(['shift_uuid' => (string) Str::uuid()]))->assertStatus(409)->assertJsonPath('error.code', 'SHIFT_NOT_ACTIVE');

    txApi('POST', '/api/v1/parking-transactions', cashPayload(['offline_created' => true, 'transaction_time_device' => now()->addMinutes(5)->toIso8601String()]))
        ->assertCreated()->assertJsonPath('data.transaction.review_flags', ['OUTSIDE_SHIFT_WINDOW']);
});

it('records position signals and normalises the plate', function () {
    txApi('POST', '/api/v1/parking-transactions', cashPayload(['latitude' => -6.7650, 'mock_location' => true, 'vehicle_plate' => 'k 1234 ab']))
        ->assertCreated()
        ->assertJsonPath('data.transaction.vehicle_plate', 'K1234AB')
        ->assertJsonPath('data.transaction.geofence_result', 'OUTSIDE')
        ->assertJsonPath('data.transaction.review_flags', ['OUTSIDE_GEOFENCE', 'MOCK_LOCATION']);
});

it('accepts only cash here (QRIS goes through the payment flow)', function () {
    txApi('POST', '/api/v1/parking-transactions', cashPayload(['payment_method' => 'QRIS']))->assertUnprocessable();
});

it('accumulates the cash the attendant holds', function () {
    txApi('POST', '/api/v1/parking-transactions', cashPayload());
    txApi('POST', '/api/v1/parking-transactions', cashPayload());
    txApi('POST', '/api/v1/parking-transactions', cashPayload(['charged_amount' => 1000]));

    txApi('GET', '/api/v1/cash/balance')->assertOk()->assertJsonPath('data.cash_balance', 5000);
    expect(CashLedgerEntry::query()->orderBy('id')->pluck('balance_after')->all())->toBe([2000, 4000, 5000]);
});

it('lists and shows only the attendant\'s own transactions', function () {
    $payload = cashPayload();
    txApi('POST', '/api/v1/parking-transactions', $payload);

    txApi('GET', '/api/v1/parking-transactions?shift_uuid='.TX_SHIFT_UUID)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    txApi('GET', '/api/v1/parking-transactions/'.$payload['transaction_uuid'])->assertOk()
        ->assertJsonPath('data.transaction.transaction_uuid', $payload['transaction_uuid']);

    DB::statement('ALTER TABLE parking_transactions DISABLE TRIGGER parking_transactions_guard_row');
    ParkingTransaction::query()->update(['attendant_id' => attendantOf(attendantUser())->id]);
    DB::statement('ALTER TABLE parking_transactions ENABLE TRIGGER parking_transactions_guard_row');
    txApi('GET', '/api/v1/parking-transactions/'.$payload['transaction_uuid'])->assertNotFound();
});

describe('database protection', function () {
    beforeEach(fn () => txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated());

    it('freezes money, identity and time columns and forbids deletion', function (string $sql) {
        expect(fn () => DB::transaction(fn () => DB::statement($sql)))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23001'));
        expect(ParkingTransaction::sole()->charged_tariff_amount)->toBe(2000);
    })->with([
        'amount' => 'UPDATE parking_transactions SET charged_tariff_amount = 1',
        'time' => "UPDATE parking_transactions SET transaction_time_device = '2000-01-01 00:00:00+00'",
        'sequence' => 'UPDATE parking_transactions SET sync_sequence = sync_sequence + 1000',
        'delete' => 'DELETE FROM parking_transactions',
        'truncate' => 'TRUNCATE parking_transactions CASCADE',
        'illegal transition' => "UPDATE parking_transactions SET status = 'PAID'",
    ]);

    it('allows only the approved state machine', function () {
        $tx = ParkingTransaction::sole();
        $tx->forceFill(['status' => TransactionStatus::VOID_REQUESTED])->save();
        $tx->forceFill(['status' => TransactionStatus::COMPLETED])->save();

        expect($tx->refresh()->status)->toBe(TransactionStatus::COMPLETED);
    });

    it('keeps the ledger append-only with one cash-in per transaction', function (string $sql, string $code) {
        expect(fn () => DB::transaction(fn () => DB::statement($sql)))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe($code));
    })->with([
        'update' => ['UPDATE cash_ledger_entries SET amount = 1', '23001'],
        'delete' => ['DELETE FROM cash_ledger_entries', '23001'],
        'second cash-in' => ["INSERT INTO cash_ledger_entries (attendant_id, transaction_id, type, amount, balance_after) SELECT attendant_id, transaction_id, 'PARKING_CASH_IN', 2000, 4000 FROM cash_ledger_entries", '23505'],
        'zero amount' => ["INSERT INTO cash_ledger_entries (attendant_id, type, amount, balance_after, reverses_entry_id) SELECT attendant_id, 'ADJUSTMENT', 0, 0, NULL FROM cash_ledger_entries", '23514'],
    ]);

    it('keeps each shift linked', function () {
        expect(ParkingTransaction::sole()->shift_id)->toBe(Shift::sole()->id);
    });
});
