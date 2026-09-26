<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin web: the session user must still be an ACTIVE staff account on every request.
 * A user deactivated mid-session is logged out on their next request.
 */
class EnsureActiveStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isStaff() || ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['username' => __('auth.disabled')]);
        }

        return $next($request);
    }
}
