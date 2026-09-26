<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\RevokeReason;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\MobileRefreshToken;
use App\Domain\Identity\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    syncRoles();
    $this->attendant = attendantUser();
});

function mobileLogin(string $username, string $password = UserFactory::PASSWORD, string $device = TEST_DEVICE_UUID): TestResponse
{
    return test()->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $password, 'device_uuid' => $device]);
}

function mobileRefresh(string $refreshToken, string $device = TEST_DEVICE_UUID): TestResponse
{
    return test()->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken, 'device_uuid' => $device]);
}

function me(string $accessToken): TestResponse
{
    // Fresh auth state per call: the guard caches the resolved user within one test.
    app('auth')->forgetGuards();

    return test()->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$accessToken]);
}

describe('login', function () {
    it('issues an access and refresh token bound to the device', function () {
        $response = mobileLogin($this->attendant->username)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['data' => [
                'token_type', 'access_token', 'access_token_expires_at', 'refresh_token', 'refresh_token_expires_at',
                'user' => ['id', 'username', 'name', 'account_type', 'roles', 'permissions'],
            ]])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.roles', ['PARKING_ATTENDANT'])
            ->assertJsonPath('data.user.account_type', 'ATTENDANT');

        $refresh = MobileRefreshToken::sole();
        expect($refresh->device_uuid)->toBe(TEST_DEVICE_UUID)
            ->and($refresh->user_id)->toBe($this->attendant->id)
            ->and(PersonalAccessToken::sole()->name)->toBe('mobile:'.TEST_DEVICE_UUID);

        $login = auditOf(AuditAction::LOGIN)->sole();
        expect($login->actor_id)->toBe($this->attendant->id)
            ->and($login->device_uuid)->toBe(TEST_DEVICE_UUID)
            ->and($login->metadata)->toBe(['channel' => 'mobile'])
            ->and($login->request_id)->toBe($response->headers->get('X-Request-Id'));
    });

    it('stores only hashes of the tokens', function () {
        $data = mobileLogin($this->attendant->username)->json('data');
        $plainAccess = explode('|', $data['access_token'], 2)[1];

        expect(PersonalAccessToken::sole()->token)->toBe(hash('sha256', $plainAccess))
            ->and(MobileRefreshToken::sole()->token_hash)->toBe(hash('sha256', $data['refresh_token']));

        $dump = json_encode([DB::table('personal_access_tokens')->get(), DB::table('mobile_refresh_tokens')->get()]);
        expect($dump)->not->toContain($plainAccess)->not->toContain($data['refresh_token']);
    });

    it('authenticates the API with the access token', function () {
        $token = mobileLogin($this->attendant->username)->json('data.access_token');

        me($token)->assertOk()
            ->assertJsonPath('data.user.username', $this->attendant->username)
            ->assertJsonPath('data.user.permissions', [
                'mobile.payment.qris', 'mobile.settlement.submit', 'mobile.shift.operate',
                'mobile.transaction.create', 'mobile.void.request',
            ]);
    });

    it('accepts the username case-insensitively', function () {
        mobileLogin(strtoupper($this->attendant->username))->assertOk();
    });

    it('rejects wrong credentials without revealing which part was wrong', function (string $username, string $password, string $reason) {
        $username = $username === '{attendant}' ? $this->attendant->username : $username;

        mobileLogin($username, $password)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTH_INVALID')
            ->assertJsonPath('error.message', 'Invalid credentials.');

        $failure = auditOf(AuditAction::LOGIN_FAILED)->sole();
        expect($failure->actor_type->value)->toBe('ANONYMOUS')
            ->and($failure->metadata['reason'])->toBe($reason)
            ->and(json_encode($failure->metadata))->not->toContain($password);
        expect(PersonalAccessToken::count())->toBe(0);
    })->with([
        'wrong password' => ['{attendant}', 'Salah-Sekali-999', 'wrong_password'],
        'unknown user' => ['tidak.ada', 'Salah-Sekali-999', 'unknown_user'],
    ]);

    it('does not let staff accounts log in to the mobile app', function () {
        $staff = staffUser(Role::SUPER_ADMIN);

        mobileLogin($staff->username)->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_INVALID');
        expect(auditOf(AuditAction::LOGIN_FAILED)->sole()->metadata['reason'])->toBe('wrong_account_type');
    });

    it('refuses inactive accounts with ACCOUNT_DISABLED', function (UserStatus $status) {
        $this->attendant->forceFill(['status' => $status])->save();

        mobileLogin($this->attendant->username)->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    })->with([UserStatus::INACTIVE, UserStatus::SUSPENDED]);

    it('validates the request', function (array $payload, string $field) {
        $this->postJson('/api/v1/auth/login', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
    })->with([
        'missing device' => [['username' => 'a', 'password' => 'b'], 'device_uuid'],
        'invalid device' => [['username' => 'a', 'password' => 'b', 'device_uuid' => 'not-a-uuid'], 'device_uuid'],
        'missing password' => [['username' => 'a', 'device_uuid' => TEST_DEVICE_UUID], 'password'],
    ]);

    it('throttles repeated attempts for one username', function () {
        foreach (range(1, 5) as $attempt) {
            mobileLogin($this->attendant->username, 'Salah-Sekali-999')->assertUnauthorized();
        }

        mobileLogin($this->attendant->username)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    });

    it('replaces the previous session when logging in again on the same device', function () {
        $first = mobileLogin($this->attendant->username)->json('data');
        $other = mobileLogin($this->attendant->username, device: OTHER_DEVICE_UUID)->json('data');
        $second = mobileLogin($this->attendant->username)->json('data');

        me($first['access_token'])->assertUnauthorized();
        mobileRefresh($first['refresh_token'])->assertUnauthorized();
        me($second['access_token'])->assertOk();
        me($other['access_token'])->assertOk();
        expect(MobileRefreshToken::query()->where('revoke_reason', RevokeReason::RELOGIN->value)->count())->toBe(1);
    });
});

describe('refresh', function () {
    beforeEach(function () {
        $this->tokens = mobileLogin($this->attendant->username)->json('data');
    });

    it('rotates both tokens and invalidates the old access token', function () {
        $new = mobileRefresh($this->tokens['refresh_token'])->assertOk()->json('data');

        expect($new['access_token'])->not->toBe($this->tokens['access_token'])
            ->and($new['refresh_token'])->not->toBe($this->tokens['refresh_token'])
            ->and($new)->not->toHaveKey('user');

        me($this->tokens['access_token'])->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        me($new['access_token'])->assertOk();

        $old = MobileRefreshToken::query()->where('token_hash', hash('sha256', $this->tokens['refresh_token']))->sole();
        expect($old->used_at)->not->toBeNull()
            ->and($old->revoked_at)->toBeNull()
            ->and($old->replaced_by_id)->not->toBeNull();
        expect(auditOf(AuditAction::TOKEN_REFRESHED)->sole()->metadata['retry'])->toBeFalse();
    });

    it('can be refreshed repeatedly', function () {
        $tokens = $this->tokens;
        foreach (range(1, 3) as $i) {
            $tokens = mobileRefresh($tokens['refresh_token'])->assertOk()->json('data');
        }

        me($tokens['access_token'])->assertOk();
        expect(MobileRefreshToken::query()->distinct()->count('family_id'))->toBe(1);
    });

    it('treats a quick retry with the old token as a lost response, not theft', function () {
        $lost = mobileRefresh($this->tokens['refresh_token'])->assertOk()->json('data');
        $retry = mobileRefresh($this->tokens['refresh_token'])->assertOk()->json('data');

        // The response the device never received is superseded; the retry result works.
        mobileRefresh($lost['refresh_token'])->assertUnauthorized();
        me($lost['access_token'])->assertUnauthorized();
        me($retry['access_token'])->assertOk();
        mobileRefresh($retry['refresh_token'])->assertOk();

        expect(auditOf(AuditAction::REFRESH_TOKEN_REUSE_DETECTED))->toHaveCount(0);
    });

    it('revokes the whole family when an old token is reused after the grace window', function () {
        $new = mobileRefresh($this->tokens['refresh_token'])->assertOk()->json('data');

        $this->travel(61)->seconds();

        mobileRefresh($this->tokens['refresh_token'])->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_INVALID');

        mobileRefresh($new['refresh_token'])->assertUnauthorized();
        me($new['access_token'])->assertUnauthorized();
        expect(auditOf(AuditAction::REFRESH_TOKEN_REUSE_DETECTED)->sole()->actor_id)->toBe($this->attendant->id);
        expect(MobileRefreshToken::query()->whereNull('revoked_at')->count())->toBe(0);
    });

    it('revokes the family when an old token is reused after its replacement was used', function () {
        $second = mobileRefresh($this->tokens['refresh_token'])->json('data');
        $third = mobileRefresh($second['refresh_token'])->assertOk()->json('data');

        mobileRefresh($this->tokens['refresh_token'])->assertUnauthorized();

        me($third['access_token'])->assertUnauthorized();
        expect(auditOf(AuditAction::REFRESH_TOKEN_REUSE_DETECTED))->toHaveCount(1);
    });

    it('rejects a token presented by another device', function () {
        mobileRefresh($this->tokens['refresh_token'], OTHER_DEVICE_UUID)->assertUnauthorized();
        mobileRefresh($this->tokens['refresh_token'])->assertOk();
    });

    it('rejects unknown and expired refresh tokens', function () {
        mobileRefresh('pprt_'.str_repeat('x', 64))->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_INVALID');

        $this->travel(31)->days();
        mobileRefresh($this->tokens['refresh_token'])->assertUnauthorized();
    });

    it('ends sessions of accounts that were deactivated', function () {
        $this->attendant->forceFill(['status' => UserStatus::SUSPENDED])->save();

        mobileRefresh($this->tokens['refresh_token'])->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
        expect(MobileRefreshToken::query()->whereNull('revoked_at')->count())->toBe(0);
    });
});

describe('access token', function () {
    it('expires after the configured lifetime', function () {
        $token = mobileLogin($this->attendant->username)->json('data.access_token');

        $this->travel(59)->minutes();
        me($token)->assertOk();

        $this->travel(2)->minutes();
        me($token)->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    });

    it('is refused for a suspended account even before it expires', function () {
        $token = mobileLogin($this->attendant->username)->json('data.access_token');
        User::query()->whereKey($this->attendant->id)->update(['status' => UserStatus::SUSPENDED->value]);

        me($token)->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    });

    it('must be a mobile token of an attendant', function () {
        $staff = staffUser(Role::SUPER_ADMIN);
        $staffToken = $staff->createToken('mobile:'.TEST_DEVICE_UUID, ['mobile'])->plainTextToken;
        $wrongAbility = $this->attendant->createToken('other', ['something'])->plainTextToken;

        me($staffToken)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        me($wrongAbility)->assertForbidden();
    });

    it('is required: an admin web session does not authenticate the mobile API', function () {
        $this->actingAs(staffUser(Role::SUPER_ADMIN), 'web');

        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    });
});

describe('logout', function () {
    it('revokes the access token and the refresh token family', function () {
        $tokens = mobileLogin($this->attendant->username)->json('data');

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$tokens['access_token']])
            ->assertOk()
            ->assertJsonPath('data.logged_out', true);

        me($tokens['access_token'])->assertUnauthorized();
        mobileRefresh($tokens['refresh_token'])->assertUnauthorized();

        $logout = auditOf(AuditAction::LOGOUT)->sole();
        expect($logout->device_uuid)->toBe(TEST_DEVICE_UUID)
            ->and(MobileRefreshToken::sole()->revoke_reason)->toBe(RevokeReason::LOGOUT->value);
    });
});
