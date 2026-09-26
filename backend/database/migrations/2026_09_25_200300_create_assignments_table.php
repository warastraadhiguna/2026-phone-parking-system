<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendant ↔ location assignments (master doc §8.4). Dates are calendar days in WIB, inclusive.
 * An attendant is assigned to at most one location on any given day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendant_id')->constrained('parking_attendants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('parking_locations')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index(['location_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE assignments
                ADD CONSTRAINT assignments_status_check CHECK (status IN ('ACTIVE', 'CANCELLED')),
                ADD CONSTRAINT assignments_period_check CHECK (effective_until IS NULL OR effective_until >= effective_from),
                ADD CONSTRAINT assignments_no_overlap EXCLUDE USING gist (
                    attendant_id WITH =,
                    daterange(effective_from, effective_until, '[]') WITH &&
                ) WHERE (status = 'ACTIVE')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
