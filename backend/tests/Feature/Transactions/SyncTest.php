<?php

use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use Illuminate\Support\Str;

beforeEach(fn () => arrangeCashTest($this));

function sync(array $items)
{
    return txApi('POST', '/api/v1/sync/transactions', ['transactions' => $items]);
}

it('stores a batch of offline transactions in order with per-item results (Scenario C)', function () {
    $items = [
        cashPayload(['offline_created' => true, 'transaction_time_device' => now()->subMinutes(20)->toIso8601String()]),
        cashPayload(['offline_created' => true, 'transaction_time_device' => now()->subMinutes(10)->toIso8601String()]),
        cashPayload(['offline_created' => true, 'charged_amount' => 1000]),
    ];

    $response = sync($items)->assertOk()
        ->assertJsonPath('data.summary', ['CREATED' => 3, 'EXISTING' => 0, 'REJECTED' => 0])
        ->assertJsonPath('data.cash_balance', 5000);

    foreach ($items as $i => $item) {
        $response->assertJsonPath("data.results.{$i}.transaction_uuid", $item['transaction_uuid'])
            ->assertJsonPath("data.results.{$i}.result", 'CREATED')
            ->assertJsonPath("data.results.{$i}.transaction.offline_created", true);
    }
    expect(ParkingTransaction::count())->toBe(3)->and(CashLedgerEntry::count())->toBe(3);
});

it('is safe to resend a whole batch after a lost response (Scenario E)', function () {
    $items = [cashPayload(['offline_created' => true]), cashPayload(['offline_created' => true])];

    sync($items)->assertJsonPath('data.summary.CREATED', 2);
    sync($items)->assertOk()
        ->assertJsonPath('data.summary', ['CREATED' => 0, 'EXISTING' => 2, 'REJECTED' => 0])
        ->assertJsonPath('data.cash_balance', 4000);

    expect(ParkingTransaction::count())->toBe(2)->and(CashLedgerEntry::count())->toBe(2);
});

it('rejects bad items without blocking the good ones', function () {
    $good = cashPayload(['offline_created' => true]);
    $conflicting = [...$good, 'charged_amount' => 9999];
    $invalid = cashPayload(['vehicle_type' => 'TRUCK']);
    $unknownShift = cashPayload(['shift_uuid' => (string) Str::uuid(), 'offline_created' => true]);
    $afterGood = cashPayload(['offline_created' => true]);

    $response = sync([$good, $conflicting, $invalid, $unknownShift, $afterGood])->assertOk()
        ->assertJsonPath('data.summary', ['CREATED' => 2, 'EXISTING' => 0, 'REJECTED' => 3]);

    $response->assertJsonPath('data.results.1.error.code', 'SYNC_CONFLICT')
        ->assertJsonPath('data.results.1.error.retryable', false)
        ->assertJsonPath('data.results.2.error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['data' => ['results' => [2 => ['error' => ['details' => ['fields' => ['vehicle_type']]]]]]])
        ->assertJsonPath('data.results.3.error.code', 'SHIFT_NOT_ACTIVE')
        ->assertJsonPath('data.results.3.error.retryable', true)
        ->assertJsonPath('data.results.4.result', 'CREATED');
});

it('limits the batch size', function () {
    $items = array_map(fn () => cashPayload(), range(1, 51));

    sync($items)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    sync([])->assertUnprocessable();
});
