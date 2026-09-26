<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shared trigger function for append-only tables. See App\Support\Database\AppendOnlyTable.
 * Tables themselves are protected by the migrations that create them (later phases).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_append_only_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Table "%" is append-only: % is not permitted', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation',
                          HINT = 'Record a correcting entry (reversal/adjustment) instead.';
            END;
            $$
            SQL);
    }

    public function down(): void
    {
        // Fails (correctly) while any table still has a trigger depending on this function.
        DB::statement('DROP FUNCTION IF EXISTS forbid_append_only_mutation()');
    }
};
