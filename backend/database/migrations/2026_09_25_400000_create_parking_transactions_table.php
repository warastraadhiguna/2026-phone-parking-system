<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parking transactions (master doc §10, §46, §47; ADR-0008 tariff mismatch).
 *
 * Never deleted. Identity, money, time and position columns are frozen after insert, and status
 * may only move along the approved state machine; both are enforced by the trigger below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_uuid')->unique();
            $table->string('transaction_number', 32)->unique();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('attendant_id')->constrained('parking_attendants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('parking_locations')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->unsignedBigInteger('sync_sequence');

            $table->string('vehicle_type', 20);
            $table->string('vehicle_plate', 15)->nullable();

            // Tariff snapshot: what was really charged, and what the server says applied.
            $table->foreignId('tariff_id')->nullable()->constrained('tariffs')->restrictOnDelete();
            $table->unsignedBigInteger('device_tariff_id')->nullable();
            $table->bigInteger('charged_tariff_amount');
            $table->bigInteger('server_expected_tariff_amount')->nullable();
            $table->bigInteger('tariff_difference_amount')->nullable();

            $table->string('payment_method', 10);
            $table->string('status', 20);

            $table->timestampTz('transaction_time_device');
            $table->timestampTz('transaction_time_server');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->boolean('mock_location')->default(false);
            $table->string('geofence_result', 10);
            $table->unsignedInteger('distance_m')->nullable();
            $table->boolean('offline_created')->default(false);

            $table->jsonb('review_flags')->default('[]');
            $table->char('payload_hash', 64);
            $table->timestampsTz();

            $table->unique(['device_id', 'sync_sequence']);
            $table->index(['attendant_id', 'transaction_time_server']);
            $table->index(['location_id', 'transaction_time_server']);
            $table->index(['shift_id']);
            $table->index('transaction_time_server');
            $table->index('status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE parking_transactions
                ADD CONSTRAINT parking_transactions_vehicle_check CHECK (vehicle_type IN ('MOTORCYCLE', 'CAR', 'OTHER')),
                ADD CONSTRAINT parking_transactions_method_check CHECK (payment_method IN ('CASH', 'QRIS')),
                ADD CONSTRAINT parking_transactions_status_check CHECK (status IN
                    ('PENDING', 'WAITING_PAYMENT', 'PAID', 'COMPLETED', 'VOID_REQUESTED', 'VOIDED', 'CANCELLED')),
                ADD CONSTRAINT parking_transactions_amount_check CHECK (charged_tariff_amount > 0
                    AND (server_expected_tariff_amount IS NULL OR server_expected_tariff_amount > 0)),
                ADD CONSTRAINT parking_transactions_difference_check CHECK (
                    (server_expected_tariff_amount IS NULL AND tariff_difference_amount IS NULL)
                    OR tariff_difference_amount = server_expected_tariff_amount - charged_tariff_amount),
                ADD CONSTRAINT parking_transactions_geofence_check CHECK (geofence_result IN ('INSIDE', 'OUTSIDE', 'UNKNOWN')),
                ADD CONSTRAINT parking_transactions_cash_status_check CHECK (payment_method <> 'CASH'
                    OR status IN ('COMPLETED', 'VOID_REQUESTED', 'VOIDED')),
                ADD CONSTRAINT parking_transactions_flags_check CHECK (jsonb_typeof(review_flags) = 'array'),
                ADD CONSTRAINT parking_transactions_plate_check CHECK (vehicle_plate IS NULL OR vehicle_plate ~ '^[A-Z0-9]{1,15}$')
            SQL);

        DB::statement('CREATE SEQUENCE parking_transaction_number_seq START 1 OWNED BY parking_transactions.transaction_number');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION parking_transactions_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Parking transactions are never deleted (id %)', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.transaction_uuid, NEW.transaction_number, NEW.shift_id, NEW.attendant_id, NEW.location_id,
                    NEW.device_id, NEW.sync_sequence, NEW.vehicle_type, NEW.vehicle_plate, NEW.tariff_id,
                    NEW.device_tariff_id, NEW.charged_tariff_amount, NEW.server_expected_tariff_amount,
                    NEW.tariff_difference_amount, NEW.payment_method, NEW.transaction_time_device,
                    NEW.transaction_time_server, NEW.latitude, NEW.longitude, NEW.gps_accuracy_m, NEW.mock_location,
                    NEW.geofence_result, NEW.distance_m, NEW.offline_created, NEW.payload_hash, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.transaction_uuid, OLD.transaction_number, OLD.shift_id, OLD.attendant_id, OLD.location_id,
                    OLD.device_id, OLD.sync_sequence, OLD.vehicle_type, OLD.vehicle_plate, OLD.tariff_id,
                    OLD.device_tariff_id, OLD.charged_tariff_amount, OLD.server_expected_tariff_amount,
                    OLD.tariff_difference_amount, OLD.payment_method, OLD.transaction_time_device,
                    OLD.transaction_time_server, OLD.latitude, OLD.longitude, OLD.gps_accuracy_m, OLD.mock_location,
                    OLD.geofence_result, OLD.distance_m, OLD.offline_created, OLD.payload_hash, OLD.created_at)
                THEN
                    RAISE EXCEPTION 'Parking transaction % is immutable: only status and review flags may change', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Master doc §47. Anything else is refused.
                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                       (OLD.status = 'PENDING' AND NEW.status IN ('WAITING_PAYMENT', 'COMPLETED'))
                    OR (OLD.status = 'WAITING_PAYMENT' AND NEW.status IN ('PAID', 'CANCELLED'))
                    OR (OLD.status = 'PAID' AND NEW.status = 'COMPLETED')
                    OR (OLD.status = 'COMPLETED' AND NEW.status = 'VOID_REQUESTED')
                    OR (OLD.status = 'VOID_REQUESTED' AND NEW.status IN ('VOIDED', 'COMPLETED'))
                ) THEN
                    RAISE EXCEPTION 'Transition % -> % is not allowed for transaction %', OLD.status, NEW.status, OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement('CREATE TRIGGER parking_transactions_guard_row BEFORE UPDATE OR DELETE ON parking_transactions FOR EACH ROW EXECUTE FUNCTION parking_transactions_guard()');
        DB::statement('CREATE TRIGGER parking_transactions_guard_truncate BEFORE TRUNCATE ON parking_transactions FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_transactions');
        DB::statement('DROP FUNCTION IF EXISTS parking_transactions_guard()');
    }
};
