<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index review (Phase 11), from EXPLAIN ANALYZE on a synthetic month (tests/Performance):
 * - MovementCheck runs on every transaction creation and needs the attendant's latest
 *   transaction by device time; without this index it sorts the attendant's whole history.
 * - CollectAnomalies runs every 5 minutes over flagged rows only; partial indexes keep that
 *   independent of total volume (about 1% of rows carry flags).
 * - The operational dashboard counts today's payments by created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS parking_transactions_attendant_device_time_index ON parking_transactions (attendant_id, transaction_time_device DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS parking_transactions_flagged_index ON parking_transactions (id) WHERE jsonb_array_length(review_flags) > 0');
        DB::statement('CREATE INDEX IF NOT EXISTS shifts_flagged_index ON shifts (id) WHERE jsonb_array_length(review_flags) > 0');
        DB::statement('CREATE INDEX IF NOT EXISTS payments_created_at_index ON payments (created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS parking_transactions_attendant_device_time_index');
        DB::statement('DROP INDEX IF EXISTS parking_transactions_flagged_index');
        DB::statement('DROP INDEX IF EXISTS shifts_flagged_index');
        DB::statement('DROP INDEX IF EXISTS payments_created_at_index');
    }
};
