<?php

namespace App\Http\Admin\Identity;

use App\Domain\Identity\Actions\ResetUserPassword;
use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class UserPasswordController
{
    public function update(Request $request, User $user, ResetUserPassword $resetPassword): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ], attributes: ['password' => 'kata sandi']);

        /** @var User $actor */
        $actor = $request->user();

        $resetPassword->handle($user, $data['password'], $actor);

        return back()->with('success', 'Kata sandi diperbarui. Semua sesi aplikasi mobile pengguna ini diakhiri.');
    }
}
