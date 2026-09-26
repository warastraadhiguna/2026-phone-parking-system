<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Database constraints protect identity invariants even if application code is bypassed. */
function insertUser(array $overrides = []): void
{
    DB::transaction(fn () => DB::table('users')->insert([
        'username' => 'valid.user',
        'name' => 'Valid',
        'password' => 'x',
        'account_type' => 'STAFF',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]));
}

it('accepts a valid row', function () {
    insertUser();

    expect(DB::table('users')->count())->toBe(1);
});

it('rejects invalid identity data with CHECK constraints', function (array $overrides) {
    expect(fn () => insertUser($overrides))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23514'));
})->with([
    'upper-case username' => [['username' => 'Valid.User']],
    'username with spaces' => [['username' => 'valid user']],
    'unknown account type' => [['account_type' => 'ROBOT']],
    'unknown status' => [['status' => 'DELETED']],
    'upper-case email' => [['email' => 'A@B.C']],
]);

it('keeps usernames unique', function () {
    insertUser();

    expect(fn () => insertUser())->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23505'));
});

it('requires a revoke reason exactly when a refresh token is revoked', function () {
    insertUser();
    $userId = DB::table('users')->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('mobile_refresh_tokens')->insert([
        'user_id' => $userId,
        'family_id' => TEST_DEVICE_UUID,
        'token_hash' => str_repeat('a', 64),
        'device_uuid' => TEST_DEVICE_UUID,
        'expires_at' => now()->addDay(),
        'revoked_at' => now(),
    ])))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23514'));
});
