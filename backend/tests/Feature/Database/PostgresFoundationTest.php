<?php

use App\Support\Database\AppendOnlyTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Runs $statement inside a savepoint so the failure does not abort the test transaction. */
function attempt(string $statement): void
{
    DB::transaction(fn () => DB::statement($statement));
}

it('runs the suite against PostgreSQL, never SQLite', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('pati_parking_test');
});

describe('append-only tables', function () {
    beforeEach(function () {
        // DDL is transactional in PostgreSQL: RefreshDatabase rolls this table back.
        DB::statement('CREATE TABLE append_only_probe (id bigserial PRIMARY KEY, amount bigint NOT NULL)');
        AppendOnlyTable::protect('append_only_probe');
        DB::table('append_only_probe')->insert(['amount' => 2000]);
    });

    it('allows inserts', function () {
        DB::table('append_only_probe')->insert(['amount' => 3000]);

        expect(DB::table('append_only_probe')->sum('amount'))->toEqual(5000);
    });

    it('rejects update, delete and truncate with SQLSTATE 23001', function (string $statement) {
        expect(fn () => attempt($statement))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe(AppendOnlyTable::SQLSTATE));

        expect(DB::table('append_only_probe')->pluck('amount')->all())->toBe([2000]);
    })->with([
        'update' => 'UPDATE append_only_probe SET amount = 0',
        'delete' => 'DELETE FROM append_only_probe',
        'truncate' => 'TRUNCATE append_only_probe',
    ]);

    it('rejects mutation through the query builder too', function () {
        expect(fn () => DB::transaction(fn () => DB::table('append_only_probe')->update(['amount' => 1])))
            ->toThrow(QueryException::class);
    });

    it('can only be lifted explicitly, by a migration-level release', function () {
        AppendOnlyTable::release('append_only_probe');

        DB::table('append_only_probe')->update(['amount' => 1]);

        expect(DB::table('append_only_probe')->value('amount'))->toEqual(1);
    });
});

it('always talks to PostgreSQL in UTC, whatever the server default time zone is', function () {
    // The test database default is Asia/Jakarta on purpose (docker/postgres/init), like a typical
    // Indonesian server. Without the connection setting, timestamps are stored 7 hours off.
    expect(DB::scalar('SHOW timezone'))->toBe('UTC')
        ->and(DB::scalar("SELECT current_setting('timezone')"))->toBe('UTC');

    $written = now()->startOfSecond();
    $read = DB::scalar('SELECT ?::timestamptz', [$written->format('Y-m-d H:i:s')]);
    expect(CarbonImmutable::parse($read)->equalTo($written))->toBeTrue();
});
