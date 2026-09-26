<?php

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money returned for a PAID payment outside the provider (ADR-0006, Q3). The payment itself stays
 * PAID; reconciliation reports received − refunded. Append-only; only PAID payments; the refunds
 * of one payment can never exceed its amount (trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('adjustment_uuid')->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->text('reason');
            $table->timestampTz('refunded_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('payment_id');
            $table->index('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_adjustments
                ADD CONSTRAINT payment_adjustments_type_check CHECK (type IN ('MANUAL_REFUND')),
                ADD CONSTRAINT payment_adjustments_amount_check CHECK (amount > 0),
                ADD CONSTRAINT payment_adjustments_reason_check CHECK (length(btrim(reason)) >= 5)
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_adjustments_limit() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                p payments%ROWTYPE;
                refunded bigint;
            BEGIN
                SELECT * INTO p FROM payments WHERE id = NEW.payment_id FOR UPDATE;
                IF p.status <> 'PAID' THEN
                    RAISE EXCEPTION 'Refunds are only possible for PAID payments (payment %)', p.id USING ERRCODE = 'check_violation';
                END IF;
                SELECT COALESCE(SUM(amount), 0) INTO refunded FROM payment_adjustments WHERE payment_id = NEW.payment_id;
                IF refunded + NEW.amount > p.amount THEN
                    RAISE EXCEPTION 'Refunds (%) would exceed the payment amount (%)', refunded + NEW.amount, p.amount USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement('CREATE TRIGGER payment_adjustments_limit_row BEFORE INSERT ON payment_adjustments FOR EACH ROW EXECUTE FUNCTION payment_adjustments_limit()');
        AppendOnlyTable::protect('payment_adjustments');
    }

    public function down(): void
    {
        AppendOnlyTable::release('payment_adjustments');
        Schema::dropIfExists('payment_adjustments');
        DB::statement('DROP FUNCTION IF EXISTS payment_adjustments_limit()');
    }
};
