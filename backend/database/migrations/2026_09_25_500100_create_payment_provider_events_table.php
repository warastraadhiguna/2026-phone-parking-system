<?php

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every verified provider answer (charge, status check, cancel, webhook) and what was done with
 * it (ADR-0006). Append-only. Webhooks carry a unique event_key so a resent notification is
 * acknowledged without being processed twice. Payloads are stored without signatures or secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('source', 20);
            $table->string('event_key', 64)->nullable()->unique();
            $table->string('provider_order_id', 64)->nullable();
            $table->string('provider_status', 20);
            $table->bigInteger('reported_amount')->nullable();
            $table->string('outcome', 20);
            $table->string('payment_status_before', 20)->nullable();
            $table->string('payment_status_after', 20)->nullable();
            $table->jsonb('payload')->default('{}');
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['payment_id', 'id']);
            $table->index('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_provider_events
                ADD CONSTRAINT payment_provider_events_source_check CHECK (source IN ('CHARGE', 'STATUS_CHECK', 'CANCEL', 'WEBHOOK')),
                ADD CONSTRAINT payment_provider_events_outcome_check CHECK (outcome IN
                    ('APPLIED', 'NO_CHANGE', 'AMOUNT_MISMATCH', 'UNKNOWN_PAYMENT', 'REJECTED')),
                ADD CONSTRAINT payment_provider_events_payload_check CHECK (jsonb_typeof(payload) = 'object'),
                ADD CONSTRAINT payment_provider_events_key_check CHECK (event_key IS NULL OR source = 'WEBHOOK')
            SQL);

        AppendOnlyTable::protect('payment_provider_events');
    }

    public function down(): void
    {
        AppendOnlyTable::release('payment_provider_events');
        Schema::dropIfExists('payment_provider_events');
    }
};
