<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime policy settings (ADR-0008). Keys, defaults and bounds live in code
 * (App\Domain\SystemConfiguration\Enums\SettingKey); a row exists only once a value was changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('updated_at');
        });

        DB::statement("ALTER TABLE system_settings ADD CONSTRAINT system_settings_value_scalar_check CHECK (jsonb_typeof(value) IN ('number', 'string', 'boolean'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
