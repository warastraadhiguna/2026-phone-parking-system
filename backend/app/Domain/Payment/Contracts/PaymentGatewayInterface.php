<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\Data\ChargeResult;
use App\Domain\Payment\Data\ProviderStatus;
use App\Domain\Payment\Data\QrisChargeRequest;
use App\Domain\Payment\Enums\PaymentProvider;
use App\Domain\Payment\Exceptions\GatewayUnavailable;
use App\Domain\Payment\Exceptions\InvalidWebhook;

/**
 * A payment provider (master doc §15; ADR-0006). Implementations live in
 * App\Domain\Payment\Internal\Gateways and are used only by the Payment module.
 *
 * Adapters never change payment records: they talk to the provider and return normalised,
 * verified answers. Payment Actions decide what those answers mean.
 */
interface PaymentGatewayInterface
{
    public function provider(): PaymentProvider;

    /** @throws GatewayUnavailable when the outcome is unknown (timeout, 5xx, network) */
    public function createQrisCharge(QrisChargeRequest $request): ChargeResult;

    /** @throws GatewayUnavailable */
    public function getStatus(string $orderId): ProviderStatus;

    /**
     * Asks the provider to cancel an unpaid charge and returns the resulting status. If the
     * provider refuses because the charge is already final (e.g. paid), returns that status.
     *
     * @throws GatewayUnavailable
     */
    public function cancel(string $orderId): ProviderStatus;

    /**
     * Verifies a notification's authenticity and normalises it.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidWebhook when it is not authentic or not understandable
     */
    public function verifyAndParseWebhook(array $payload): ProviderStatus;
}
