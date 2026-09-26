<?php

namespace App\Http\Admin\Identity;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Actions\AuthenticateUser;
use App\Domain\Identity\Models\User;
use App\Http\Admin\Identity\Requests\StaffLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin control center login: Laravel session + CSRF (ADR-0004). No remember-me.
 */
final class LoginController
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(StaffLoginRequest $request, AuthenticateUser $authenticate): RedirectResponse
    {
        $user = $request->authenticate($authenticate);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request, RecordAuditEvent $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(fn () => $audit->handle(
            AuditAction::LOGOUT,
            $user,
            'user',
            $user->id,
            ['channel' => AuthenticateUser::CHANNEL_ADMIN_WEB],
        ));

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
