<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Void workflow (master doc §28): requested by the attendant or an operator, decided by a
 * supervisor. The original transaction is kept; a cash void produces a ledger REVERSAL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('void_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('parking_transactions')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('channel', 10);
            $table->string('reason', 500);
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE void_requests
                ADD CONSTRAINT void_requests_status_check CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED')),
                ADD CONSTRAINT void_requests_channel_check CHECK (channel IN ('MOBILE', 'ADMIN')),
                ADD CONSTRAINT void_requests_decision_check CHECK ((status = 'PENDING') = (decided_by IS NULL)),
                ADD CONSTRAINT void_requests_four_eyes_check CHECK (decided_by IS NULL OR decided_by <> requested_by)
            SQL);

        DB::statement("CREATE UNIQUE INDEX void_requests_one_pending ON void_requests (transaction_id) WHERE status = 'PENDING'");
    }

    public function down(): void
    {
        Schema::dropIfExists('void_requests');
    }
};
