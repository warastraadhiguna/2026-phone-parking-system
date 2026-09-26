<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned tariffs (master doc §9). Never overwritten, never deleted.
 *
 * DRAFT → APPROVED | REJECTED. An APPROVED row is frozen; the only permitted change is closing
 * an open-ended validity (effective_until NULL → a time) when a newer tariff supersedes it.
 * Approved tariffs of the same scope never overlap in time. Creator and approver differ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_type', 20);
            $table->string('location_type', 20);
            $table->foreignId('location_id')->nullable()->constrained('parking_locations')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->string('regulation_reference', 255);
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'vehicle_type', 'location_type']);
            $table->index('location_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tariffs
                ADD CONSTRAINT tariffs_vehicle_type_check CHECK (vehicle_type IN ('MOTORCYCLE', 'CAR', 'OTHER')),
                ADD CONSTRAINT tariffs_location_type_check CHECK (location_type IN ('ON_STREET', 'OFF_STREET', 'EVENT')),
                ADD CONSTRAINT tariffs_status_check CHECK (status IN ('DRAFT', 'APPROVED', 'REJECTED')),
                ADD CONSTRAINT tariffs_amount_check CHECK (amount > 0),
                ADD CONSTRAINT tariffs_period_check CHECK (effective_until IS NULL OR effective_until > effective_from),
                ADD CONSTRAINT tariffs_approval_check CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
                ADD CONSTRAINT tariffs_four_eyes_check CHECK (approved_by IS NULL OR approved_by <> created_by),
                ADD CONSTRAINT tariffs_no_overlap EXCLUDE USING gist (
                    vehicle_type WITH =,
                    location_type WITH =,
                    (COALESCE(location_id, 0)) WITH =,
                    tstzrange(effective_from, effective_until, '[)') WITH &&
                ) WHERE (status = 'APPROVED')
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tariffs_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Tariffs are never deleted (id %)', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'REJECTED' THEN
                    RAISE EXCEPTION 'Rejected tariff % is final', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'APPROVED' THEN
                    IF NEW.status IS DISTINCT FROM OLD.status
                        OR NEW.vehicle_type IS DISTINCT FROM OLD.vehicle_type
                        OR NEW.location_type IS DISTINCT FROM OLD.location_type
                        OR NEW.location_id IS DISTINCT FROM OLD.location_id
                        OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                        OR NEW.regulation_reference IS DISTINCT FROM OLD.regulation_reference
                        OR NEW.created_by IS DISTINCT FROM OLD.created_by
                        OR NEW.approved_by IS DISTINCT FROM OLD.approved_by
                        OR NEW.approved_at IS DISTINCT FROM OLD.approved_at
                        OR (OLD.effective_until IS NOT NULL AND NEW.effective_until IS DISTINCT FROM OLD.effective_until)
                    THEN
                        RAISE EXCEPTION 'Approved tariff % is frozen: only an open validity may be closed', OLD.id
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement('CREATE TRIGGER tariffs_guard_row BEFORE UPDATE OR DELETE ON tariffs FOR EACH ROW EXECUTE FUNCTION tariffs_guard()');
        DB::statement('CREATE TRIGGER tariffs_guard_truncate BEFORE TRUNCATE ON tariffs FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
        DB::statement('DROP FUNCTION IF EXISTS tariffs_guard()');
    }
};
