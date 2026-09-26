<?php

namespace App\Http\Admin\Identity;

use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Support\Errors\RuleViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UserStatusController
{
    public function update(Request $request, User $user, ChangeUserStatus $changeStatus): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ], attributes: ['status' => 'status', 'reason' => 'alasan']);

        if ($user->isAttendant()) {
            throw new RuleViolation('status', 'Status akun juru parkir diubah melalui halaman Juru Parkir.');
        }

        /** @var User $actor */
        $actor = $request->user();

        $changeStatus->handle($user, UserStatus::from($data['status']), $data['reason'] ?? null, $actor);

        return back()->with('success', 'Status pengguna diperbarui.');
    }
}
