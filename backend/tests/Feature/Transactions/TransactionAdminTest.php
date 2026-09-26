<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    arrangeCashTest($this);
    $this->withoutVite();
    txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated();
    txApi('POST', '/api/v1/parking-transactions', cashPayload(['charged_amount' => 1000, 'latitude' => -6.7650]))->assertCreated();
});

it('lists transactions with revenue totals and filters', function () {
    $finance = staffUser(Role::FINANCE);

    $this->actingAs($finance)->get('/transactions')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Transactions/Index')
            ->has('transactions.data', 2)
            ->where('totals', ['count' => 2, 'amount' => 3000]));

    $this->actingAs($finance)->get('/transactions?flagged=1')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('transactions.data', 1)->where('totals.amount', 1000));
});

it('shows a transaction with its ledger lines', function () {
    $tx = ParkingTransaction::query()->orderBy('id')->first();

    $this->actingAs(staffUser(Role::AUDITOR))->get("/transactions/{$tx->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Transactions/Show')
            ->where('transaction.charged_amount', 2000)
            ->has('ledger', 1)
            ->where('ledger.0.balance_after', 2000)
            ->where('can.requestVoid', false));
});

it('hides transactions from roles without transactions.view', function () {
    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get('/transactions')->assertForbidden();
});
