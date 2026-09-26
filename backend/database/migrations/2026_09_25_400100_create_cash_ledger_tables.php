<?php

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash ledger (master doc §11–§12, ADR-0007).
 *
 * cash_ledger_entries is the financial source of truth for cash and is append-only.
 * attendant_cash_balances is derived (fast reads) and can always be rebuilt from the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendant_id')->constrained('parking_attendants')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('parking_transactions')->restrictOnDelete();
            // FK to cash_settlements is added by the settlement migration (Phase 7).
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('cash_ledger_entries')->restrictOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['attendant_id', 'id']);
            $table->index('shift_id');
            $table->index('settlement_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cash_ledger_entries
                ADD CONSTRAINT cash_ledger_type_check CHECK (type IN ('PARKING_CASH_IN', 'SETTLEMENT_OUT', 'ADJUSTMENT', 'REVERSAL')),
                ADD CONSTRAINT cash_ledger_amount_check CHECK (amount <> 0),
                ADD CONSTRAINT cash_ledger_cash_in_check CHECK (type <> 'PARKING_CASH_IN' OR (amount > 0 AND transaction_id IS NOT NULL)),
                ADD CONSTRAINT cash_ledger_settlement_check CHECK (type <> 'SETTLEMENT_OUT' OR (amount < 0 AND settlement_id IS NOT NULL)),
                ADD CONSTRAINT cash_ledger_reversal_check CHECK ((type = 'REVERSAL') = (reverses_entry_id IS NOT NULL))
            SQL);

        // One cash-in per transaction; each entry can be reversed at most once.
        DB::statement("CREATE UNIQUE INDEX cash_ledger_one_cash_in_per_transaction ON cash_ledger_entries (transaction_id) WHERE type = 'PARKING_CASH_IN'");
        DB::statement('CREATE UNIQUE INDEX cash_ledger_reversed_once ON cash_ledger_entries (reverses_entry_id) WHERE reverses_entry_id IS NOT NULL');

        AppendOnlyTable::protect('cash_ledger_entries');

        Schema::create('attendant_cash_balances', function (Blueprint $table) {
            $table->foreignId('attendant_id')->primary()->constrained('parking_attendants')->restrictOnDelete();
            $table->bigInteger('balance')->default(0);
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendant_cash_balances');
        AppendOnlyTable::release('cash_ledger_entries');
        Schema::dropIfExists('cash_ledger_entries');
    }
};
