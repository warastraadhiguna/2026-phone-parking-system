<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments (master doc §15–§19; ADR-0006). One payment per QRIS parking transaction.
 *
 * Never deleted. Identity and amount are frozen; provider data (reference, QR, expiry, paid_at)
 * can be filled once and then never changed; status moves only forward and PAID is final.
 * All enforced by the trigger below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('payment_uuid')->unique();
            $table->foreignId('transaction_id')->unique()->constrained('parking_transactions')->restrictOnDelete();
            $table->string('provider', 20);
            // The order id sent to the provider (= payment_uuid): globally unique, never reused.
            $table->string('provider_order_id', 64)->unique();
            // The provider's own transaction id, once known.
            $table->string('provider_reference', 100)->nullable();
            $table->string('payment_method', 10);
            $table->bigInteger('amount');
            $table->string('status', 20);
            $table->text('qr_string')->nullable();
            $table->string('qr_image_url', 500)->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->string('status_reason', 255)->nullable();
            $table->unsignedSmallInteger('charge_attempts')->default(0);
            $table->timestampTz('last_status_check_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['status', 'expired_at']);
            $table->index('paid_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_provider_check CHECK (provider IN ('MIDTRANS', 'FAKE')),
                ADD CONSTRAINT payments_method_check CHECK (payment_method IN ('QRIS')),
                ADD CONSTRAINT payments_amount_check CHECK (amount > 0),
                ADD CONSTRAINT payments_status_check CHECK (status IN
                    ('CREATED', 'PENDING', 'PAID', 'EXPIRED', 'FAILED', 'REFUNDED', 'CANCELLED')),
                ADD CONSTRAINT payments_paid_at_check CHECK ((status = 'PAID') = (paid_at IS NOT NULL)),
                ADD CONSTRAINT payments_pending_check CHECK (status <> 'PENDING' OR (provider_reference IS NOT NULL AND expired_at IS NOT NULL))
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION payments_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Payments are never deleted (id %)', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.payment_uuid, NEW.transaction_id, NEW.provider, NEW.provider_order_id, NEW.payment_method,
                    NEW.amount, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.payment_uuid, OLD.transaction_id, OLD.provider, OLD.provider_order_id, OLD.payment_method,
                    OLD.amount, OLD.created_at)
                   OR (OLD.provider_reference IS NOT NULL AND NEW.provider_reference IS DISTINCT FROM OLD.provider_reference)
                   OR (OLD.qr_string IS NOT NULL AND NEW.qr_string IS DISTINCT FROM OLD.qr_string)
                   OR (OLD.qr_image_url IS NOT NULL AND NEW.qr_image_url IS DISTINCT FROM OLD.qr_image_url)
                   OR (OLD.expired_at IS NOT NULL AND NEW.expired_at IS DISTINCT FROM OLD.expired_at)
                   OR (OLD.paid_at IS NOT NULL AND NEW.paid_at IS DISTINCT FROM OLD.paid_at)
                THEN
                    RAISE EXCEPTION 'Payment % is immutable except for status and first-time provider data', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- ADR-0006: forward only; PAID is final; a late confirmation may still turn an
                -- EXPIRED/FAILED/CANCELLED payment into PAID (the money did arrive).
                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                       (OLD.status = 'CREATED' AND NEW.status IN ('PENDING', 'PAID', 'EXPIRED', 'FAILED', 'CANCELLED'))
                    OR (OLD.status = 'PENDING' AND NEW.status IN ('PAID', 'EXPIRED', 'FAILED', 'CANCELLED'))
                    OR (OLD.status IN ('EXPIRED', 'FAILED', 'CANCELLED') AND NEW.status = 'PAID')
                ) THEN
                    RAISE EXCEPTION 'Payment transition % -> % is not allowed (payment %)', OLD.status, NEW.status, OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement('CREATE TRIGGER payments_guard_row BEFORE UPDATE OR DELETE ON payments FOR EACH ROW EXECUTE FUNCTION payments_guard()');
        DB::statement('CREATE TRIGGER payments_guard_truncate BEFORE TRUNCATE ON payments FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation()');

        // A QRIS transaction is always online and always charged exactly the server's tariff.
        DB::statement(<<<'SQL'
            ALTER TABLE parking_transactions
                ADD CONSTRAINT parking_transactions_qris_check CHECK (payment_method <> 'QRIS'
                    OR (offline_created = false AND tariff_difference_amount = 0))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE parking_transactions DROP CONSTRAINT IF EXISTS parking_transactions_qris_check');
        Schema::dropIfExists('payments');
        DB::statement('DROP FUNCTION IF EXISTS payments_guard()');
    }
};
