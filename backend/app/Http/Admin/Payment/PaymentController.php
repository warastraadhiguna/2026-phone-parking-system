<?php

namespace App\Http\Admin\Payment;

use App\Domain\Identity\Models\User;
use App\Domain\Payment\Actions\RecordManualRefund;
use App\Domain\Payment\Enums\PaymentProvider;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAdjustment;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Http\Admin\Support\Options;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Control Center: QRIS payments, the provider's answers, and manual refunds (ADR-0006).
 * Read-only except for recording a manual refund (payments.refund_record).
 */
final class PaymentController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = Payment::query()
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('provider_order_id', strtolower($term))
                ->orWhere('provider_reference', $term)
                ->orWhereHas('transaction', fn ($t) => $t->where('transaction_number', 'ilike', '%'.addcslashes($term, '%_\\').'%'))))
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['date'] ?? null, function ($q, string $date) {
                $start = CarbonImmutable::parse($date, BusinessTime::timezone())->startOfDay()->utc();
                $q->where('created_at', '>=', $start)->where('created_at', '<', $start->addDay());
            });

        $paid = (clone $query)->where('status', PaymentStatus::PAID->value);
        $received = (int) (clone $paid)->sum('amount');
        $refunded = (int) PaymentAdjustment::query()->whereIn('payment_id', (clone $paid)->select('id'))->sum('amount');

        $payments = $query->with(['transaction:id,transaction_number,status,attendant_id', 'transaction.attendant:id,attendant_code'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Payment $p) => $this->row($p));

        return Inertia::render('Payments/Index', [
            'payments' => $payments,
            'totals' => ['received' => $received, 'refunded' => $refunded, 'net' => $received - $refunded],
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'date' => $filters['date'] ?? ''],
            'options' => ['statuses' => Options::of(PaymentStatus::class)],
        ]);
    }

    public function show(Request $request, Payment $payment): Response
    {
        $payment->load(['transaction:id,transaction_number,status,attendant_id,location_id', 'transaction.attendant:id,attendant_code,name', 'transaction.location:id,location_code']);
        /** @var User $viewer */
        $viewer = $request->user();
        $refunded = $payment->refundedAmount();

        return Inertia::render('Payments/Show', [
            'payment' => [
                ...$this->row($payment),
                'provider_order_id' => $payment->provider_order_id,
                'provider_reference' => $payment->provider_reference,
                'expired_at' => $payment->expired_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'status_reason' => $payment->status_reason,
                'charge_attempts' => $payment->charge_attempts,
                'last_status_check_at' => $payment->last_status_check_at?->toIso8601String(),
                'refunded' => $refunded,
                'location' => $payment->transaction->location?->location_code,
                'attendant_name' => $payment->transaction->attendant?->name,
            ],
            'events' => $payment->events()->orderBy('id')->get()->map(fn (PaymentProviderEvent $e) => [
                'id' => $e->id,
                'source' => $e->source->value,
                'provider_status' => $e->provider_status,
                'reported_amount' => $e->reported_amount,
                'outcome' => $e->outcome->value,
                'status_before' => $e->payment_status_before,
                'status_after' => $e->payment_status_after,
                'created_at' => $e->created_at->toIso8601String(),
            ]),
            'adjustments' => $payment->adjustments()->with('recorder:id,username')->orderBy('id')->get()->map(fn (PaymentAdjustment $a) => [
                'id' => $a->id,
                'type' => Options::one($a->type),
                'amount' => $a->amount,
                'reason' => $a->reason,
                'refunded_at' => $a->refunded_at->toIso8601String(),
                'recorded_by' => $a->recorder?->username,
            ]),
            'can' => [
                'recordRefund' => $viewer->can('payments.refund_record') && $payment->status === PaymentStatus::PAID && $refunded < $payment->amount,
            ],
        ]);
    }

    public function recordRefund(Request $request, Payment $payment, RecordManualRefund $record): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'refunded_at' => ['required', 'date'],
        ], attributes: ['amount' => 'jumlah', 'reason' => 'alasan', 'refunded_at' => 'waktu pengembalian']);
        /** @var User $actor */
        $actor = $request->user();

        $record->handle($payment, $actor, (int) $data['amount'], $data['reason'], CarbonImmutable::parse($data['refunded_at'], BusinessTime::timezone()));

        return back()->with('success', 'Pengembalian dana dicatat. Status pembayaran tetap Lunas.');
    }

    /** @return array<string, mixed> */
    private function row(Payment $p): array
    {
        return [
            'id' => $p->id,
            'payment_uuid' => $p->payment_uuid,
            'status' => Options::one($p->status),
            'provider' => $p->provider === PaymentProvider::FAKE ? 'FAKE (uji)' : $p->provider->value,
            'amount' => $p->amount,
            'created_at' => $p->created_at->toIso8601String(),
            'paid_at' => $p->paid_at?->toIso8601String(),
            'transaction' => [
                'id' => $p->transaction->id,
                'transaction_number' => $p->transaction->transaction_number,
                'status' => Options::one($p->transaction->status),
                'attendant_code' => $p->transaction->attendant?->attendant_code,
            ],
        ];
    }
}
