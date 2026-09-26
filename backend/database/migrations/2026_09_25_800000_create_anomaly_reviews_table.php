<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review queue (master doc Phase 9; ADR-0013). One item per signal: a review flag on a
 * transaction or shift, or a reconciliation mismatch. Items are collected idempotently (unique
 * source + entity + code). A decision is final: decided items are frozen, and items are never
 * deleted (trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomaly_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('entity_type', 50);
            $table->string('entity_id', 64);
            $table->unsignedBigInteger('entity_key')->nullable();
            $table->string('reference', 64)->nullable();
            $table->string('code', 50);
            $table->string('severity', 10);
            $table->foreignId('attendant_id')->nullable()->constrained('parking_attendants')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('parking_locations')->restrictOnDelete();
            $table->bigInteger('amount')->nullable();
            $table->timestampTz('occurred_at');
            $table->string('status', 20)->default('OPEN');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'entity_type', 'entity_id', 'code']);
            $table->index(['status', 'severity', 'occurred_at']);
            $table->index('attendant_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE anomaly_reviews
                ADD CONSTRAINT anomaly_reviews_source_check CHECK (source IN ('TRANSACTION', 'SHIFT', 'RECONCILIATION')),
                ADD CONSTRAINT anomaly_reviews_severity_check CHECK (severity IN ('HIGH', 'MEDIUM', 'LOW')),
                ADD CONSTRAINT anomaly_reviews_status_check CHECK (status IN ('OPEN', 'CONFIRMED', 'DISMISSED')),
                ADD CONSTRAINT anomaly_reviews_decision_check CHECK ((status = 'OPEN') = (decided_by IS NULL AND decided_at IS NULL)
                    AND (status = 'OPEN' OR length(btrim(coalesce(decision_note, ''))) >= 5))
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION anomaly_reviews_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Review items are never deleted (id %)', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status <> 'OPEN' THEN
                    RAISE EXCEPTION 'Review item % is decided and can no longer change', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;
                IF (NEW.source, NEW.entity_type, NEW.entity_id, NEW.entity_key, NEW.reference, NEW.code, NEW.severity,
                    NEW.attendant_id, NEW.location_id, NEW.amount, NEW.occurred_at, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.source, OLD.entity_type, OLD.entity_id, OLD.entity_key, OLD.reference, OLD.code, OLD.severity,
                    OLD.attendant_id, OLD.location_id, OLD.amount, OLD.occurred_at, OLD.created_at)
                THEN
                    RAISE EXCEPTION 'Review item % facts are immutable', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('CREATE TRIGGER anomaly_reviews_guard_row BEFORE UPDATE OR DELETE ON anomaly_reviews FOR EACH ROW EXECUTE FUNCTION anomaly_reviews_guard()');
        DB::statement('CREATE TRIGGER anomaly_reviews_guard_truncate BEFORE TRUNCATE ON anomaly_reviews FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('anomaly_reviews');
        DB::statement('DROP FUNCTION IF EXISTS anomaly_reviews_guard()');
    }
};
