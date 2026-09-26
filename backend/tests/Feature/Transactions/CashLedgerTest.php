<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashLedger\Actions\ReverseLedgerEntry;
use App\Domain\CashLedger\Enums\LedgerEntryType;
use App\Domain\CashLedger\Internal\LedgerWriter;
use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\CashLedger\Services\CashBalances;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    arrangeCashTest($this);
    txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated();
    txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated();
});

it('confirms balances that match the ledger', function () {
    $this->artisan('cash:verify-balances')->expectsOutputToContain('All attendant cash balances match')->assertSuccessful();
});

it('detects a tampered derived balance and rebuilds it from the ledger', function () {
    DB::table('attendant_cash_balances')->update(['balance' => 999999]);

    $this->artisan('cash:verify-balances')->expectsOutputToContain('derived 999999 vs ledger 4000')->assertFailed();
    expect(app(CashBalances::class)->of($this->attendant->id))->toBe(999999);

    $this->artisan('cash:verify-balances --fix')->assertFailed(); // reports what it fixed
    expect(app(CashBalances::class)->of($this->attendant->id))->toBe(4000)
        ->and(CashLedgerEntry::count())->toBe(2)
        ->and(auditOf(AuditAction::CASH_BALANCES_REBUILT)->sole()->metadata['corrected'][0])->toBe(['ledger' => 4000, 'derived' => 999999, 'attendant_id' => $this->attendant->id]);

    $this->artisan('cash:verify-balances')->assertSuccessful();
});

it('rebuilds a missing derived row', function () {
    DB::table('attendant_cash_balances')->delete();

    $this->artisan('cash:verify-balances --fix');

    expect(app(CashBalances::class)->of($this->attendant->id))->toBe(4000);
});

it('refuses to write ledger entries outside a database transaction', function () {
    DB::rollBack(); // leave the RefreshDatabase wrapper for this assertion
    try {
        expect(fn () => app(LedgerWriter::class)->append($this->attendant->id, LedgerEntryType::ADJUSTMENT, 100))->toThrow(LogicException::class);
    } finally {
        DB::beginTransaction();
    }
});

it('reverses an entry once and never a reversal', function () {
    $entry = CashLedgerEntry::query()->orderBy('id')->first();
    $reversal = DB::transaction(fn () => app(ReverseLedgerEntry::class)->handle($entry, 'uji', null));

    expect($reversal->amount)->toBe(-2000)->and($reversal->balance_after)->toBe(2000);
    expect(fn () => DB::transaction(fn () => app(ReverseLedgerEntry::class)->handle($entry, 'lagi', null)))->toThrow(RuleViolation::class);
    expect(fn () => DB::transaction(fn () => app(ReverseLedgerEntry::class)->handle($reversal, 'lagi', null)))->toThrow(RuleViolation::class);
});
