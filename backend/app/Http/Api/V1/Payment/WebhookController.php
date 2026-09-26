<?php

namespace App\Http\Api\V1\Payment;

use App\Domain\Payment\Actions\HandleWebhook;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Exceptions\InvalidWebhook;
use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Provider notifications: POST /api/v1/payments/webhooks/{provider} (ADR-0006, master doc §18).
 *
 * - Only the active provider's URL exists (others: 404).
 * - Not authentic → 403, logged without the payload.
 * - Processed, duplicate, or unknown order → 200 (so the provider stops retrying).
 * - Unexpected failure → 500 via the global handler; nothing was stored, the provider retries.
 */
final class WebhookController
{
    public function __invoke(Request $request, string $provider, PaymentGatewayInterface $gateway, HandleWebhook $webhook): JsonResponse
    {
        if ($provider !== $gateway->provider()->slug()) {
            throw new ApiException(ErrorCode::NOT_FOUND);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        try {
            $outcome = $webhook->handle($payload);
        } catch (InvalidWebhook $e) {
            Log::warning('Payment webhook rejected', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
                'order_id' => is_string($payload['order_id'] ?? null) ? mb_substr($payload['order_id'], 0, 64) : null,
            ]);

            throw new ApiException(ErrorCode::FORBIDDEN, 'Notification rejected.');
        }

        return ApiResponse::success(['received' => true, 'outcome' => $outcome->value ?? 'DUPLICATE']);
    }
}
