<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CaptureRequestContext;
use App\Http\Middleware\EnsureActiveDevice;
use App\Http\Middleware\EnsureActiveStaff;
use App\Http\Middleware\EnsureMobileAttendant;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Errors\ApiException;
use App\Support\Http\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withCommands([
        __DIR__.'/../app/Domain/Identity/Console',
        __DIR__.'/../app/Domain/ParkingAttendant/Console',
        __DIR__.'/../app/Domain/Shift/Console',
        __DIR__.'/../app/Domain/CashLedger/Console',
        __DIR__.'/../app/Domain/Payment/Console',
        __DIR__.'/../app/Domain/Reconciliation/Console',
        __DIR__.'/../app/Domain/FraudReview/Console',
        __DIR__.'/../app/Domain/Reporting/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // First global middleware, so every log line of the request carries the ID.
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(CaptureRequestContext::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'staff' => EnsureActiveStaff::class,
            'mobile.attendant' => EnsureMobileAttendant::class,
            'mobile.device' => EnsureActiveDevice::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Expected domain failures are not errors worth reporting.
        $exceptions->dontReport([ApiException::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            // Every API error uses the standard envelope (docs/api/README.md).
            if ($request->is('api/*')) {
                return ApiExceptionRenderer::render($e);
            }

            // Admin web: a domain rule violation becomes a form error on the page it came from.
            if ($e instanceof ApiException && $request->hasSession()) {
                $field = is_string($e->details['field'] ?? null) ? $e->details['field'] : 'general';

                return back()->withInput($request->except(['password', 'password_confirmation']))
                    ->withErrors([$field => $e->getMessage()]);
            }

            return null;
        });
    })->create();
