<?php

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation snapshots (master doc §14; ADR-0012). A run is computed in one consistent
 * database snapshot and stored as a whole; runs, lines and mismatches are append-only. Running
 * again for the same date adds a new run; history is never overwritten.
 */
return new class extends Migration
{
    /** Money/count columns shared by the run totals and every line. */
    private function metrics(Blueprint $table, bool $nullableCash): void
    {
        $table->unsignedInteger('transaction_count')->default(0);
        $table->bigInteger('expected_cash')->default(0);
        $table->bigInteger('voided_cash')->default(0);
        foreach (['ledger_cash_in', 'ledger_reversals', 'cash_deposited', 'cash_outstanding'] as $column) {
            $nullableCash ? $table->bigInteger($column)->nullable() : $table->bigInteger($column)->default(0);
        }
        $table->bigInteger('qris_expected')->default(0);
        $table->bigInteger('qris_paid')->default(0);
        $table->bigInteger('qris_refunded')->default(0);
        $table->bigInteger('qris_difference')->default(0);
        $table->bigInteger('qris_open')->default(0);
        $table->bigInteger('total_revenue')->default(0);
    }

    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->date('business_date');
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->foreignId('run_by')->nullable()->constrained('users')->restrictOnDelete();
            $this->metrics($table, false);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['business_date', 'id']);
        });

        Schema::create('reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('reconciliation_runs')->restrictOnDelete();
            $table->string('dimension', 20);
            $table->unsignedBigInteger('dimension_id');
            $table->string('label', 150);
            $this->metrics($table, true);

            $table->unique(['run_id', 'dimension', 'dimension_id']);
        });

        Schema::create('reconciliation_mismatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('reconciliation_runs')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('severity', 10);
            $table->string('entity_type', 50);
            $table->string('entity_id', 64);
            $table->string('reference', 64)->nullable();
            $table->bigInteger('expected_amount')->nullable();
            $table->bigInteger('actual_amount')->nullable();
            $table->jsonb('details')->default('{}');

            $table->index(['run_id', 'severity']);
            $table->index(['entity_type', 'entity_id']);
        });

        DB::statement("ALTER TABLE reconciliation_lines ADD CONSTRAINT reconciliation_lines_dimension_check CHECK (dimension IN ('ATTENDANT', 'LOCATION'))");
        DB::statement("ALTER TABLE reconciliation_mismatches ADD CONSTRAINT reconciliation_mismatches_severity_check CHECK (severity IN ('ERROR', 'WARNING'))");

        foreach (['reconciliation_runs', 'reconciliation_lines', 'reconciliation_mismatches'] as $table) {
            AppendOnlyTable::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['reconciliation_mismatches', 'reconciliation_lines', 'reconciliation_runs'] as $table) {
            AppendOnlyTable::release($table);
            Schema::dropIfExists($table);
        }
    }
};
