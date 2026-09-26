<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Database-level protection for append-only tables (audit_logs, cash_ledger_entries, ...).
 *
 * Installs triggers that reject UPDATE, DELETE and TRUNCATE by raising SQLSTATE 23001
 * (restrict_violation). Relies on the forbid_append_only_mutation() function created by
 * migration 2026_09_25_000100_create_append_only_guard_function.
 *
 * Use from a migration's up(); call release() from its down(). Maintenance procedures
 * are documented in docs/decisions/0007-financial-ledger-immutability.md.
 */
final class AppendOnlyTable
{
    public const SQLSTATE = '23001';

    public static function protect(string $table): void
    {
        $t = self::identifier($table);

        DB::statement(<<<SQL
            CREATE TRIGGER {$t}_append_only_row
            BEFORE UPDATE OR DELETE ON {$t}
            FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation()
            SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER {$t}_append_only_truncate
            BEFORE TRUNCATE ON {$t}
            FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation()
            SQL);
    }

    public static function release(string $table): void
    {
        $t = self::identifier($table);

        DB::statement("DROP TRIGGER IF EXISTS {$t}_append_only_row ON {$t}");
        DB::statement("DROP TRIGGER IF EXISTS {$t}_append_only_truncate ON {$t}");
    }

    /** Table names are code constants, but still refuse anything that is not a plain identifier. */
    private static function identifier(string $table): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,40}$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name [{$table}].");
        }

        return $table;
    }
}
