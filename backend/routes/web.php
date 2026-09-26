<?php

use App\Http\Admin\Assignment\AssignmentController;
use App\Http\Admin\Audit\AuditLogController;
use App\Http\Admin\CashSettlement\SettlementController;
use App\Http\Admin\DashboardController;
use App\Http\Admin\Device\DeviceController;
use App\Http\Admin\FraudReview\ReviewController;
use App\Http\Admin\Identity\LoginController;
use App\Http\Admin\Identity\RoleController;
use App\Http\Admin\Identity\UserController;
use App\Http\Admin\Identity\UserPasswordController;
use App\Http\Admin\Identity\UserStatusController;
use App\Http\Admin\ParkingAttendant\AttendantController;
use App\Http\Admin\ParkingLocation\LocationController;
use App\Http\Admin\ParkingTransaction\TransactionController;
use App\Http\Admin\Payment\PaymentController;
use App\Http\Admin\Reconciliation\ReconciliationController;
use App\Http\Admin\Reporting\ReportController;
use App\Http\Admin\Shift\ShiftController;
use App\Http\Admin\SystemConfiguration\SettingsController;
use App\Http\Admin\Tariff\TariffController;
use Illuminate\Support\Facades\Route;

/*
| Admin / Control Center (Inertia + React). Session auth + CSRF (ADR-0004).
| Every authenticated route also requires an ACTIVE staff account (`staff`).
*/

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'staff'])->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('home');

    Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');

    Route::middleware('can:users.manage')->group(function (): void {
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('users/{user}/status', [UserStatusController::class, 'update'])->name('users.status.update');
        Route::put('users/{user}/password', [UserPasswordController::class, 'update'])->name('users.password.update');
    });

    Route::get('roles', RoleController::class)->middleware('can:roles.view')->name('roles.index');

    // Master data (Phase 2). Literal segments (create) are registered before {model} routes.
    Route::middleware('can:locations.manage')->group(function (): void {
        Route::get('locations/create', [LocationController::class, 'create'])->name('locations.create');
        Route::post('locations', [LocationController::class, 'store'])->name('locations.store');
        Route::put('locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::put('locations/{location}/status', [LocationController::class, 'updateStatus'])->name('locations.status.update');
    });
    Route::middleware('can:locations.view')->group(function (): void {
        Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
        Route::get('locations/{location}', [LocationController::class, 'show'])->name('locations.show');
    });

    Route::middleware('can:attendants.manage')->group(function (): void {
        Route::get('attendants/create', [AttendantController::class, 'create'])->name('attendants.create');
        Route::post('attendants', [AttendantController::class, 'store'])->name('attendants.store');
        Route::put('attendants/{attendant}', [AttendantController::class, 'update'])->name('attendants.update');
        Route::put('attendants/{attendant}/status', [AttendantController::class, 'updateStatus'])->name('attendants.status.update');
        Route::post('attendants/{attendant}/photo', [AttendantController::class, 'storePhoto'])->name('attendants.photo.store');
    });
    Route::middleware('can:attendants.view')->group(function (): void {
        Route::get('attendants', [AttendantController::class, 'index'])->name('attendants.index');
        Route::get('attendants/{attendant}', [AttendantController::class, 'show'])->name('attendants.show');
        Route::get('attendants/{attendant}/photo', [AttendantController::class, 'photo'])->name('attendants.photo');
    });

    Route::middleware('can:assignments.manage')->group(function (): void {
        Route::post('attendants/{attendant}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
        Route::put('assignments/{assignment}/end', [AssignmentController::class, 'end'])->name('assignments.end');
        Route::put('assignments/{assignment}/cancel', [AssignmentController::class, 'cancel'])->name('assignments.cancel');
    });

    Route::get('devices', [DeviceController::class, 'index'])->middleware('can:devices.view')->name('devices.index');
    Route::middleware('can:devices.manage')->group(function (): void {
        Route::put('devices/{device}/approve', [DeviceController::class, 'approve'])->name('devices.approve');
        Route::put('devices/{device}/deactivate', [DeviceController::class, 'deactivate'])->name('devices.deactivate');
    });

    Route::middleware('can:tariffs.manage')->group(function (): void {
        Route::get('tariffs/create', [TariffController::class, 'create'])->name('tariffs.create');
        Route::post('tariffs', [TariffController::class, 'store'])->name('tariffs.store');
        Route::get('tariffs/{tariff}/edit', [TariffController::class, 'edit'])->name('tariffs.edit');
        Route::put('tariffs/{tariff}', [TariffController::class, 'update'])->name('tariffs.update');
    });
    Route::middleware('can:tariffs.approve')->group(function (): void {
        Route::put('tariffs/{tariff}/approve', [TariffController::class, 'approve'])->name('tariffs.approve');
        Route::put('tariffs/{tariff}/reject', [TariffController::class, 'reject'])->name('tariffs.reject');
    });
    Route::middleware('can:tariffs.view')->group(function (): void {
        Route::get('tariffs', [TariffController::class, 'index'])->name('tariffs.index');
        Route::get('tariffs/{tariff}', [TariffController::class, 'show'])->name('tariffs.show');
    });

    // Operations (Phase 3)
    Route::middleware('can:shifts.view')->group(function (): void {
        Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
        Route::get('shifts/{shift}', [ShiftController::class, 'show'])->name('shifts.show');
    });
    Route::put('shifts/{shift}/force-close', [ShiftController::class, 'forceClose'])->middleware('can:shifts.force_close')->name('shifts.force-close');

    // Transactions & voids (Phase 4)
    Route::middleware('can:transactions.view')->group(function (): void {
        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    });
    Route::post('transactions/{transaction}/void-request', [TransactionController::class, 'requestVoid'])->middleware('can:transactions.void_request')->name('transactions.void-request');
    Route::put('void-requests/{voidRequest}', [TransactionController::class, 'decideVoid'])->middleware('can:transactions.void_approve')->name('void-requests.decide');

    // QRIS payments & manual refunds (Phase 6)
    Route::middleware('can:payments.view')->group(function (): void {
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    });
    Route::post('payments/{payment}/refunds', [PaymentController::class, 'recordRefund'])->middleware('can:payments.refund_record')->name('payments.refunds.store');

    // Cash settlements (Phase 7)
    Route::middleware('can:settlements.view')->group(function (): void {
        Route::get('settlements', [SettlementController::class, 'index'])->name('settlements.index');
        Route::get('settlements/{settlement}', [SettlementController::class, 'show'])->name('settlements.show');
        Route::get('settlements/{settlement}/proof', [SettlementController::class, 'proof'])->name('settlements.proof');
    });
    Route::put('settlements/{settlement}/decision', [SettlementController::class, 'decide'])->middleware('can:settlements.verify')->name('settlements.decide');

    // Reconciliation (Phase 8)
    Route::middleware('can:reconciliation.view')->group(function (): void {
        Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::get('reconciliation/{run}', [ReconciliationController::class, 'show'])->name('reconciliation.show');
    });
    Route::post('reconciliation', [ReconciliationController::class, 'store'])->middleware('can:reconciliation.run')->name('reconciliation.store');

    // Reports & exports (Phase 10)
    Route::middleware('can:reports.view')->group(function (): void {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('reports/exports', [ReportController::class, 'export'])->middleware('can:reports.export')->name('reports.exports.store');
        Route::get('reports/exports/{export}', [ReportController::class, 'download'])->middleware('can:reports.export')->name('reports.exports.download');
    });

    // Review queue & audit log (Phase 9)
    Route::get('reviews', [ReviewController::class, 'index'])->middleware('can:anomalies.view')->name('reviews.index');
    Route::put('reviews/{review}', [ReviewController::class, 'decide'])->middleware('can:anomalies.review')->name('reviews.decide');
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit-logs.index');

    Route::middleware('can:system.configure')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('settings/{key}', [SettingsController::class, 'update'])->name('settings.update');
    });
});
