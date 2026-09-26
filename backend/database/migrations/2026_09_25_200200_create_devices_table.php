<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Android devices bound to attendants (master doc §8.3, ADR-0005).
 * PENDING_APPROVAL → ACTIVE → REVOKED | LOST; a pending device may also be REVOKED (rejected).
 * REVOKED and LOST are final: the app must re-register with a new device UUID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('device_uuid')->unique();
            $table->foreignId('attendant_id')->constrained('parking_attendants')->restrictOnDelete();
            $table->string('device_model', 100)->nullable();
            $table->string('android_version', 30)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->string('status', 20)->default('PENDING_APPROVAL');
            $table->timestampTz('registered_at');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('deactivation_reason', 500)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->index(['attendant_id', 'status']);
            $table->index('status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE devices
                ADD CONSTRAINT devices_status_check CHECK (status IN ('PENDING_APPROVAL', 'ACTIVE', 'REVOKED', 'LOST')),
                ADD CONSTRAINT devices_approval_check CHECK (status <> 'ACTIVE' OR approved_at IS NOT NULL),
                ADD CONSTRAINT devices_deactivation_check CHECK (status NOT IN ('REVOKED', 'LOST') OR deactivated_at IS NOT NULL)
            SQL);

        // MVP rule: one ACTIVE device per attendant (ADR-0005).
        DB::statement("CREATE UNIQUE INDEX devices_one_active_per_attendant ON devices (attendant_id) WHERE status = 'ACTIVE'");
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
