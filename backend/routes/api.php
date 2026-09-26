<?php

use App\Http\Api\V1\CashSettlement\SettlementController;
use App\Http\Api\V1\Identity\AuthController;
use App\Http\Api\V1\ParkingTransaction\TransactionController;
use App\Http\Api\V1\Payment\PaymentController;
use App\Http\Api\V1\Payment\WebhookController;
use App\Http\Api\V1\Shift\ShiftController;
use App\Http\Api\V1\Sync\SyncController;
use App\Http\Api\V1\System\BootstrapController;
use App\Http\Api\V1\System\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Mobile / machine API. Everything is versioned under /api/v1 (master doc §41).
| Contracts: docs/api/.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health/live', [HealthController::class, 'live'])->name('api.v1.health.live');
    Route::get('health/ready', [HealthController::class, 'ready'])->name('api.v1.health.ready');

    // Payment provider notifications: authenticated by signature, not by token (docs/api/payments.md).
    Route::post('payments/webhooks/{provider}', WebhookController::class)->whereAlpha('provider')->middleware('throttle:payment-webhook')->name('api.v1.payments.webhook');

    // Attendant authentication (docs/api/auth.md)
    Route::prefix('auth')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:mobile-login')->name('api.v1.auth.login');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:mobile-refresh')->name('api.v1.auth.refresh');

        Route::middleware(['auth:sanctum', 'mobile.attendant'])->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
            Route::get('me', [AuthController::class, 'me'])->name('api.v1.auth.me');
        });
    });

    // Operational endpoints: ACTIVE device and operational attendant required.
    Route::middleware(['auth:sanctum', 'mobile.attendant', 'mobile.device'])->group(function (): void {
        Route::get('bootstrap', BootstrapController::class)->name('api.v1.bootstrap');

        // docs/api/shifts.md
        Route::post('shifts/start', [ShiftController::class, 'start'])->name('api.v1.shifts.start');
        Route::post('shifts/end', [ShiftController::class, 'end'])->name('api.v1.shifts.end');
        Route::get('shifts/active', [ShiftController::class, 'active'])->name('api.v1.shifts.active');
        Route::get('shifts/{shiftUuid}', [ShiftController::class, 'show'])->whereUuid('shiftUuid')->name('api.v1.shifts.show');

        // docs/api/transactions.md
        Route::post('parking-transactions', [TransactionController::class, 'store'])->middleware('can:mobile.transaction.create')->name('api.v1.transactions.store');
        Route::get('parking-transactions', [TransactionController::class, 'index'])->name('api.v1.transactions.index');
        Route::get('parking-transactions/{transactionUuid}', [TransactionController::class, 'show'])->whereUuid('transactionUuid')->name('api.v1.transactions.show');
        Route::post('parking-transactions/{transactionUuid}/void-request', [TransactionController::class, 'requestVoid'])->whereUuid('transactionUuid')->middleware('can:mobile.void.request')->name('api.v1.transactions.void-request');
        Route::get('cash/balance', [TransactionController::class, 'balance'])->name('api.v1.cash.balance');

        // docs/api/payments.md (QRIS)
        Route::post('parking-transactions/qris', [PaymentController::class, 'storeQris'])->middleware('can:mobile.payment.qris')->name('api.v1.transactions.qris');
        Route::get('payments/{paymentUuid}', [PaymentController::class, 'show'])->whereUuid('paymentUuid')->name('api.v1.payments.show');
        Route::post('payments/{paymentUuid}/cancel', [PaymentController::class, 'cancel'])->whereUuid('paymentUuid')->middleware('can:mobile.payment.qris')->name('api.v1.payments.cancel');

        // docs/api/settlements.md
        Route::get('cash/summary', [SettlementController::class, 'summary'])->name('api.v1.cash.summary');
        Route::post('settlements', [SettlementController::class, 'store'])->middleware('can:mobile.settlement.submit')->name('api.v1.settlements.store');
        Route::get('settlements', [SettlementController::class, 'index'])->name('api.v1.settlements.index');
        Route::get('settlements/{settlementUuid}', [SettlementController::class, 'show'])->whereUuid('settlementUuid')->name('api.v1.settlements.show');
        Route::post('settlements/{settlementUuid}/cancel', [SettlementController::class, 'cancel'])->whereUuid('settlementUuid')->middleware('can:mobile.settlement.submit')->name('api.v1.settlements.cancel');

        // docs/api/sync.md
        Route::post('sync/transactions', [SyncController::class, 'transactions'])->middleware('can:mobile.transaction.create')->name('api.v1.sync.transactions');
    });
});
