<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendant shifts (master doc §8.5, ADR-0008). Created by the device (shift_uuid), possibly
 * offline, and synchronised idempotently. One OPEN shift per attendant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('shift_uuid')->unique();
            $table->foreignId('attendant_id')->constrained('parking_attendants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('parking_locations')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('assignments')->restrictOnDelete();
            $table->string('status', 20)->default('OPEN');
            $table->boolean('offline_created')->default(false);

            $table->timestampTz('started_at_device');
            $table->timestampTz('started_at_server');
            $table->decimal('start_latitude', 10, 7)->nullable();
            $table->decimal('start_longitude', 10, 7)->nullable();
            $table->decimal('start_gps_accuracy_m', 8, 2)->nullable();
            $table->boolean('start_mock_location')->default(false);
            $table->string('start_geofence_result', 10);
            $table->unsignedInteger('start_distance_m')->nullable();

            $table->timestampTz('ended_at_device')->nullable();
            $table->timestampTz('ended_at_server')->nullable();
            $table->decimal('end_latitude', 10, 7)->nullable();
            $table->decimal('end_longitude', 10, 7)->nullable();
            $table->decimal('end_gps_accuracy_m', 8, 2)->nullable();
            $table->boolean('end_mock_location')->default(false);
            $table->string('end_geofence_result', 10)->nullable();
            $table->unsignedInteger('end_distance_m')->nullable();

            $table->foreignId('force_closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('close_reason', 500)->nullable();
            $table->jsonb('review_flags')->default('[]');
            $table->char('start_payload_hash', 64);
            $table->char('end_payload_hash', 64)->nullable();
            $table->timestampsTz();

            $table->index(['attendant_id', 'started_at_server']);
            $table->index(['location_id', 'started_at_server']);
            $table->index(['status', 'started_at_server']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE shifts
                ADD CONSTRAINT shifts_status_check CHECK (status IN ('OPEN', 'CLOSED', 'FORCED_CLOSED')),
                ADD CONSTRAINT shifts_geofence_check CHECK (start_geofence_result IN ('INSIDE', 'OUTSIDE', 'UNKNOWN')
                    AND (end_geofence_result IS NULL OR end_geofence_result IN ('INSIDE', 'OUTSIDE', 'UNKNOWN'))),
                ADD CONSTRAINT shifts_closed_check CHECK (status = 'OPEN' OR ended_at_server IS NOT NULL),
                ADD CONSTRAINT shifts_forced_check CHECK (status <> 'FORCED_CLOSED' OR (force_closed_by IS NOT NULL AND close_reason IS NOT NULL)),
                ADD CONSTRAINT shifts_end_order_check CHECK (ended_at_device IS NULL OR ended_at_device >= started_at_device),
                ADD CONSTRAINT shifts_flags_array_check CHECK (jsonb_typeof(review_flags) = 'array')
            SQL);

        // ADR-0008 / master doc §46 rule 1: one OPEN shift per attendant.
        DB::statement("CREATE UNIQUE INDEX shifts_one_open_per_attendant ON shifts (attendant_id) WHERE status = 'OPEN'");
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
