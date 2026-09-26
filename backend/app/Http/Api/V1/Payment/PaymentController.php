<?php

namespace App\Http\Api\V1\Payment;

use App\Domain\Device\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingTransaction\Actions\CreateQrisTransaction;
use App\Domain\Payment\Actions\CancelPayment;
use App\Domain\Payment\Actions\RefreshPaymentStatus;
use App\Domain\Payment\Exceptions\GatewayUnavailable;
use App\Domain\Payment\Models\Payment;
use App\Http\Api\V1\ParkingTransaction\TransactionResource;
use App\Http\Middleware\EnsureActiveDevice;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * QRIS for the Android app (master doc §16, Scenario B). Contract: docs/api/payments.md.
 * The app shows the QR and polls; only the provider's confirmation makes a payment PAID.
 */
final class PaymentController
{
    public function storeQris(StoreQrisTransactionRequest $request, CreateQrisTransaction $create): JsonResponse
    {
        [$user, $attendant, $device] = $this->actor($request);
        $data = $request->toData();

        try {
            $outcome = $create->handle($user, $attendant, $device, $data);
        } catch (GatewayUnavailable $e) {
            Log::warning('QRIS charge outcome unknown', ['transaction_uuid' => $data->transactionUuid, 'message' => $e->getMessage()]);

            throw new ApiException(ErrorCode::SERVICE_UNAVAILABLE, 'Penyedia pembayaran tidak dapat dihubungi. Coba lagi (data yang sama).', [
                'transaction_uuid' => $data->transactionUuid,
                'retryable' => true,
            ]);
        }

        $outcome->transaction->loadMissing('shift');

        return ApiResponse::success([
            'transaction' => TransactionResource::make($outcome->transaction),
            'payment' => PaymentResource::make($outcome->payment),
            'replayed' => ! $outcome->created,
        ], status: $outcome->created ? 201 : 200);
    }

    public function show(Request $request, string $paymentUuid, RefreshPaymentStatus $refresh): JsonResponse
    {
        $payment = $this->own($request, $paymentUuid);

        if ($payment->status->isOpen()) {
            try {
                $payment = $refresh->handle($payment);
            } catch (GatewayUnavailable $e) {
                // The stored status is still true; the app simply polls again.
                Log::info('Payment status check unavailable', ['payment_uuid' => $payment->payment_uuid, 'message' => $e->getMessage()]);
            }
        }

        return $this->respond($payment);
    }

    public function cancel(Request $request, string $paymentUuid, CancelPayment $cancel): JsonResponse
    {
        $payment = $this->own($request, $paymentUuid);
        /** @var User $user */
        $user = $request->user();

        try {
            $payment = $cancel->handle($payment, $user);
        } catch (GatewayUnavailable) {
            throw new ApiException(ErrorCode::SERVICE_UNAVAILABLE, 'Penyedia pembayaran tidak dapat dihubungi. Coba lagi.', ['retryable' => true]);
        }

        return $this->respond($payment);
    }

    private function respond(Payment $payment): JsonResponse
    {
        $transaction = $payment->transaction()->with('shift:id,shift_uuid')->firstOrFail();

        return ApiResponse::success([
            'payment' => PaymentResource::make($payment),
            'transaction' => TransactionResource::make($transaction),
        ]);
    }

    private function own(Request $request, string $uuid): Payment
    {
        [, $attendant] = $this->actor($request);

        return Payment::query()
            ->where('payment_uuid', strtolower($uuid))
            ->whereHas('transaction', fn ($q) => $q->where('attendant_id', $attendant->id))
            ->first() ?? throw new ApiException(ErrorCode::NOT_FOUND, 'Pembayaran tidak ditemukan.');
    }

    /** @return array{0: User, 1: ParkingAttendant, 2: Device} */
    private function actor(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Device $device */
        $device = $request->attributes->get(EnsureActiveDevice::DEVICE_ATTRIBUTE);
        /** @var ParkingAttendant $attendant */
        $attendant = $device->attendant;

        return [$user, $attendant, $device];
    }
}
