<?php

namespace App\Domain\Payment\Console;

use App\Domain\Payment\Actions\RefreshPaymentStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\GatewayUnavailable;
use App\Domain\Payment\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for lost notifications (scheduled every minute): asks the provider about open
 * payments whose QR has expired, whose charge outcome is unknown, or that were not checked for
 * a while. The status applied is always the provider's answer; nothing is guessed locally.
 */
final class CheckPendingPaymentsCommand extends Command
{
    protected $signature = 'payments:check-pending {--limit=100}';

    protected $description = 'Ask the payment provider about open (CREATED/PENDING) payments';

    public function handle(RefreshPaymentStatus $refresh): int
    {
        $payments = Payment::query()
            ->where(function ($q) {
                $q->where(fn ($p) => $p->where('status', PaymentStatus::PENDING->value)->where(fn ($d) => $d
                    ->where('expired_at', '<', now()->subMinute())
                    ->orWhereNull('last_status_check_at')
                    ->orWhere('last_status_check_at', '<', now()->subMinutes(5))))
                    ->orWhere(fn ($c) => $c->where('status', PaymentStatus::CREATED->value)->where('created_at', '<', now()->subMinute()));
            })
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $changed = 0;
        $unavailable = 0;
        foreach ($payments as $payment) {
            $before = $payment->status;
            try {
                $after = $refresh->handle($payment, force: true)->status;
                $changed += $after !== $before ? 1 : 0;
            } catch (GatewayUnavailable $e) {
                $unavailable++;
                Log::warning('Payment status check failed', ['payment_uuid' => $payment->payment_uuid, 'message' => $e->getMessage()]);
            }
        }

        $this->info("Checked {$payments->count()} payment(s): {$changed} changed, {$unavailable} provider errors.");

        return self::SUCCESS;
    }
}
