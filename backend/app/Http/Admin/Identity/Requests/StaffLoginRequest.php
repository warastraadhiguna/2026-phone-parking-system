<?php

namespace App\Http\Admin\Identity\Requests;

use App\Domain\Identity\Actions\AuthenticateUser;
use App\Domain\Identity\Enums\AccountType;
use App\Domain\Identity\Exceptions\AccountDisabled;
use App\Domain\Identity\Exceptions\InvalidCredentials;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class StaffLoginRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:128'],
        ];
    }

    /**
     * Verifies credentials with throttling per username+IP and per IP.
     *
     * @throws ValidationException
     */
    public function authenticate(AuthenticateUser $authenticate): User
    {
        $this->ensureIsNotRateLimited();

        try {
            $user = $authenticate->handle(
                (string) $this->string('username'),
                (string) $this->string('password'),
                AccountType::STAFF,
                AuthenticateUser::CHANNEL_ADMIN_WEB,
            );
        } catch (InvalidCredentials) {
            $this->hitRateLimiters();
            throw ValidationException::withMessages(['username' => __('auth.failed')]);
        } catch (AccountDisabled) {
            $this->hitRateLimiters();
            throw ValidationException::withMessages(['username' => __('auth.disabled')]);
        }

        RateLimiter::clear($this->usernameKey());

        return $user;
    }

    private function ensureIsNotRateLimited(): void
    {
        $limits = [
            $this->usernameKey() => (int) config('identity.throttle.login_per_username'),
            $this->ipKey() => (int) config('identity.throttle.login_per_ip'),
        ];

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'username' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
                ]);
            }
        }
    }

    private function hitRateLimiters(): void
    {
        RateLimiter::hit($this->usernameKey(), 60);
        RateLimiter::hit($this->ipKey(), 60);
    }

    private function usernameKey(): string
    {
        return 'admin-login:'.mb_strtolower((string) $this->string('username')).'|'.$this->ip();
    }

    private function ipKey(): string
    {
        return 'admin-login-ip:'.$this->ip();
    }
}
