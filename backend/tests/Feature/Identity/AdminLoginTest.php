<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    syncRoles();
    $this->withoutVite();
    $this->staff = staffUser(Role::FINANCE);
});

function adminLogin(string $username, string $password = UserFactory::PASSWORD): TestResponse
{
    return test()->post('/login', ['username' => $username, 'password' => $password]);
}

it('sends guests to the login page', function () {
    $this->get('/')->assertRedirect('/login');
    $this->get('/users')->assertRedirect('/login');
});

it('shows the login page', function () {
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('logs staff in with a fresh session', function () {
    $this->get('/login');
    $sessionBefore = session()->getId();

    adminLogin($this->staff->username)->assertRedirect('/');

    $this->assertAuthenticatedAs($this->staff, 'web');
    expect(session()->getId())->not->toBe($sessionBefore)
        ->and($this->staff->refresh()->last_login_at)->not->toBeNull();

    $login = auditOf(AuditAction::LOGIN)->sole();
    expect($login->actor_id)->toBe($this->staff->id)->and($login->metadata)->toBe(['channel' => 'admin_web']);
});

it('shares the signed-in user and permissions with the frontend', function () {
    $this->actingAs($this->staff)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('auth.user.username', $this->staff->username)
            ->where('auth.user.roles.0.value', 'FINANCE')
            ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->contains('settlements.verify')
                && ! collect($permissions)->contains('users.manage')));
});

it('rejects wrong credentials with a generic message', function (string $username, string $password) {
    adminLogin($username === '{staff}' ? $this->staff->username : $username, $password)
        ->assertRedirect()
        ->assertSessionHasErrors(['username' => 'Username atau kata sandi salah.']);

    $this->assertGuest('web');
    expect(auditOf(AuditAction::LOGIN_FAILED))->toHaveCount(1);
})->with([
    'wrong password' => ['{staff}', 'Salah-Sekali-999'],
    'unknown user' => ['tidak.ada', 'Salah-Sekali-999'],
]);

it('does not let attendant accounts into the control center', function () {
    $attendant = attendantUser();

    adminLogin($attendant->username)->assertSessionHasErrors(['username' => 'Username atau kata sandi salah.']);
    $this->assertGuest('web');
});

it('refuses inactive staff accounts', function () {
    $this->staff->forceFill(['status' => UserStatus::SUSPENDED])->save();

    adminLogin($this->staff->username)->assertSessionHasErrors(['username' => 'Akun ini tidak aktif. Hubungi administrator.']);
    $this->assertGuest('web');
});

it('throttles repeated failures, even when the password is then correct', function () {
    foreach (range(1, 5) as $attempt) {
        adminLogin($this->staff->username, 'Salah-Sekali-999');
    }

    adminLogin($this->staff->username)->assertSessionHasErrors('username');
    expect(session('errors')->first('username'))->toContain('Terlalu banyak percobaan');
    $this->assertGuest('web');
});

it('logs out and records the logout', function () {
    $this->actingAs($this->staff)->post('/logout')->assertRedirect('/login');

    $this->assertGuest('web');
    expect(auditOf(AuditAction::LOGOUT)->sole()->actor_id)->toBe($this->staff->id);
});

it('ends the session of a user deactivated mid-session', function () {
    adminLogin($this->staff->username)->assertRedirect('/');
    $this->get('/')->assertOk();

    User::query()->whereKey($this->staff->id)->update(['status' => UserStatus::INACTIVE->value]);
    app('auth')->forgetGuards(); // next request re-reads the user, as a real request would

    $this->get('/')->assertRedirect('/login')->assertSessionHasErrors('username');
    $this->assertGuest('web');
});

it('redirects signed-in staff away from the login page', function () {
    $this->actingAs($this->staff)->get('/login')->assertRedirect('/');
});
