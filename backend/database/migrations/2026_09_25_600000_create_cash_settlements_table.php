<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash settlements (master doc §11, §13, §46 rule 5; ADR-0011).
 *
 * An attendant submits a deposit; finance verifies the counted amount. Only a VERIFIED
 * settlement moves money in the ledger (one SETTLEMENT_OUT). Never deleted; a decided settlement
 * is frozen; identity and submitted amount are frozen from the start (trigger below).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('settlement_uuid')->unique();
            $table->string('settlement_number', 32)->unique();
            $table->foreignId('attendant_id')->constrained('parking_attendants')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->bigInteger('amount');
            // Attendant's cash balance (ledger) when submitting: context for finance.
            $table->bigInteger('balance_at_submission');
            $table->text('notes')->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->char('proof_sha256', 64)->nullable();
            $table->string('status', 20);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at');
            $table->bigInteger('verified_amount')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->char('payload_hash', 64);
            $table->timestampsTz();

            $table->index(['attendant_id', 'submitted_at']);
            $table->index(['status', 'submitted_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cash_settlements
                ADD CONSTRAINT cash_settlements_status_check CHECK (status IN ('SUBMITTED', 'VERIFIED', 'REJECTED', 'CANCELLED')),
                ADD CONSTRAINT cash_settlements_amount_check CHECK (amount > 0),
                ADD CONSTRAINT cash_settlements_verified_check CHECK ((status = 'VERIFIED') = (verified_amount IS NOT NULL)
                    AND (verified_amount IS NULL OR verified_amount > 0)),
                ADD CONSTRAINT cash_settlements_decision_check CHECK (
                    (status IN ('VERIFIED', 'REJECTED')) = (decided_by IS NOT NULL AND decided_at IS NOT NULL)),
                ADD CONSTRAINT cash_settlements_reject_note_check CHECK (status <> 'REJECTED' OR length(btrim(coalesce(decision_note, ''))) > 0),
                ADD CONSTRAINT cash_settlements_difference_note_check CHECK (
                    verified_amount IS NULL OR verified_amount = amount OR length(btrim(coalesce(decision_note, ''))) > 0),
                ADD CONSTRAINT cash_settlements_four_eyes_check CHECK (decided_by IS NULL OR decided_by <> submitted_by)
            SQL);

        // One open submission per attendant: finance handles them one at a time.
        DB::statement("CREATE UNIQUE INDEX cash_settlements_one_open_per_attendant ON cash_settlements (attendant_id) WHERE status = 'SUBMITTED'");

        DB::statement('CREATE SEQUENCE cash_settlement_number_seq START 1 OWNED BY cash_settlements.settlement_number');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_settlements_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Cash settlements are never deleted (id %)', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status <> 'SUBMITTED' THEN
                    RAISE EXCEPTION 'Settlement % is % and can no longer change', OLD.id, OLD.status USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.settlement_uuid, NEW.settlement_number, NEW.attendant_id, NEW.shift_id, NEW.device_id, NEW.amount,
                    NEW.balance_at_submission, NEW.notes, NEW.proof_path, NEW.proof_sha256, NEW.submitted_by,
                    NEW.submitted_at, NEW.payload_hash, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.settlement_uuid, OLD.settlement_number, OLD.attendant_id, OLD.shift_id, OLD.device_id, OLD.amount,
                    OLD.balance_at_submission, OLD.notes, OLD.proof_path, OLD.proof_sha256, OLD.submitted_by,
                    OLD.submitted_at, OLD.payload_hash, OLD.created_at)
                THEN
                    RAISE EXCEPTION 'Settlement % submission data is immutable', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status NOT IN ('VERIFIED', 'REJECTED', 'CANCELLED') THEN
                    RAISE EXCEPTION 'Transition SUBMITTED -> % is not allowed (settlement %)', NEW.status, OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement('CREATE TRIGGER cash_settlements_guard_row BEFORE UPDATE OR DELETE ON cash_settlements FOR EACH ROW EXECUTE FUNCTION cash_settlements_guard()');
        DB::statement('CREATE TRIGGER cash_settlements_guard_truncate BEFORE TRUNCATE ON cash_settlements FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation()');

        // Ledger link (prepared in Phase 4): one SETTLEMENT_OUT per settlement; a deposit can never
        // take more cash than the attendant holds.
        DB::statement('ALTER TABLE cash_ledger_entries ADD CONSTRAINT cash_ledger_entries_settlement_id_foreign FOREIGN KEY (settlement_id) REFERENCES cash_settlements (id) ON DELETE RESTRICT');
        DB::statement("CREATE UNIQUE INDEX cash_ledger_one_settlement_out ON cash_ledger_entries (settlement_id) WHERE type = 'SETTLEMENT_OUT'");
        DB::statement("ALTER TABLE cash_ledger_entries ADD CONSTRAINT cash_ledger_settlement_balance_check CHECK (type <> 'SETTLEMENT_OUT' OR balance_after >= 0)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cash_ledger_entries DROP CONSTRAINT IF EXISTS cash_ledger_settlement_balance_check');
        DB::statement('DROP INDEX IF EXISTS cash_ledger_one_settlement_out');
        DB::statement('ALTER TABLE cash_ledger_entries DROP CONSTRAINT IF EXISTS cash_ledger_entries_settlement_id_foreign');
        Schema::dropIfExists('cash_settlements');
        DB::statement('DROP FUNCTION IF EXISTS cash_settlements_guard()');
    }
};
