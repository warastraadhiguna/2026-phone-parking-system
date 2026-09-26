<?php

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit trail (master doc §27, ADR-0007). Append-only at the database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('actor_type', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_label', 150)->nullable();
            $table->string('action', 64);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->ipAddress('ip_address')->nullable();
            $table->uuid('device_uuid')->nullable();
            $table->string('request_id', 128)->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index('occurred_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_logs
                ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('USER', 'SYSTEM', 'ANONYMOUS')),
                ADD CONSTRAINT audit_logs_actor_consistency_check CHECK ((actor_type = 'USER') = (actor_id IS NOT NULL)),
                ADD CONSTRAINT audit_logs_metadata_object_check CHECK (jsonb_typeof(metadata) = 'object')
            SQL);

        AppendOnlyTable::protect('audit_logs');
    }

    public function down(): void
    {
        AppendOnlyTable::release('audit_logs');
        Schema::dropIfExists('audit_logs');
    }
};
