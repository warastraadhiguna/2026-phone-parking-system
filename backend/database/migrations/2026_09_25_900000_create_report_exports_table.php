<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Queued report exports (master doc §32, §39). Operational records, not financial ones: the
 * file is a copy of data that stays in its source tables. Files expire after a retention period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('export_uuid')->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('report_type', 40);
            $table->string('format', 10);
            $table->jsonb('params');
            $table->string('status', 20);
            $table->string('file_path', 255)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['requested_by', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE report_exports
                ADD CONSTRAINT report_exports_format_check CHECK (format IN ('csv', 'xlsx', 'pdf')),
                ADD CONSTRAINT report_exports_status_check CHECK (status IN ('QUEUED', 'RUNNING', 'DONE', 'FAILED', 'EXPIRED'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
