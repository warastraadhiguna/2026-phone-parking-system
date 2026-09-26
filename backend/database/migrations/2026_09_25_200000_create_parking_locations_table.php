<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parking location registry (master doc §8.2). Locations are deactivated, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Needed for exclusion constraints mixing equality and range overlap (assignments, tariffs).
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('parking_locations', function (Blueprint $table) {
            $table->id();
            $table->string('location_code', 30)->unique();
            $table->string('name', 150);
            $table->string('address', 500);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('geofence_radius_m');
            $table->string('location_type', 20);
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedInteger('motorcycle_capacity')->default(0);
            $table->unsignedInteger('car_capacity')->default(0);
            $table->timestampsTz();

            $table->index(['status', 'location_type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE parking_locations
                ADD CONSTRAINT parking_locations_code_format_check CHECK (location_code ~ '^[A-Z0-9][A-Z0-9-]{2,29}$'),
                ADD CONSTRAINT parking_locations_latitude_check CHECK (latitude BETWEEN -90 AND 90),
                ADD CONSTRAINT parking_locations_longitude_check CHECK (longitude BETWEEN -180 AND 180),
                ADD CONSTRAINT parking_locations_radius_check CHECK (geofence_radius_m BETWEEN 5 AND 1000),
                ADD CONSTRAINT parking_locations_type_check CHECK (location_type IN ('ON_STREET', 'OFF_STREET', 'EVENT')),
                ADD CONSTRAINT parking_locations_status_check CHECK (status IN ('ACTIVE', 'INACTIVE', 'SUSPENDED')),
                ADD CONSTRAINT parking_locations_capacity_check CHECK (motorcycle_capacity >= 0 AND car_capacity >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_locations');
    }
};
