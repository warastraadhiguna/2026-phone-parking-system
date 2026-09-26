<?php

namespace App\Http\Admin\ParkingTransaction;

use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\ParkingTransaction\Actions\DecideVoid;
use App\Domain\ParkingTransaction\Actions\RequestVoid;
use App\Domain\ParkingTransaction\Enums\PaymentMethod;
use App\Domain\ParkingTransaction\Enums\TransactionFlag;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\ParkingTransaction\Enums\VoidChannel;
use App\Domain\ParkingTransaction\Enums\VoidRequestStatus;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\ParkingTransaction\Models\VoidRequest;
use App\Domain\Payment\Models\Payment;
use App\Http\Admin\Support\Options;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class TransactionController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'location_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'flagged' => ['nullable', 'boolean'],
        ]);

        $query = ParkingTransaction::query()
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('transaction_number', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('vehicle_plate', 'ilike', '%'.addcslashes(strtoupper($term), '%_\\').'%')))
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['payment_method'] ?? null, fn ($q, string $v) => $q->where('payment_method', $v))
            ->when($filters['location_id'] ?? null, fn ($q, int|string $v) => $q->where('location_id', (int) $v))
            ->when($filters['date'] ?? null, function ($q, string $date) {
                $start = CarbonImmutable::parse($date, BusinessTime::timezone())->startOfDay()->utc();
                $q->where('transaction_time_server', '>=', $start)->where('transaction_time_server', '<', $start->addDay());
            })
            ->when($filters['flagged'] ?? false, fn ($q) => $q->whereRaw('jsonb_array_length(review_flags) > 0'));

        $totals = (clone $query)->whereIn('status', [TransactionStatus::COMPLETED->value, TransactionStatus::VOID_REQUESTED->value])
            ->selectRaw('count(*) as count, coalesce(sum(charged_tariff_amount), 0) as amount')->first();

        $transactions = $query->with(['attendant:id,attendant_code,name', 'location:id,location_code'])
            ->orderByDesc('transaction_time_server')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ParkingTransaction $t) => $this->row($t));

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'totals' => ['count' => (int) $totals?->getAttribute('count'), 'amount' => (int) $totals?->getAttribute('amount')],
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
                'payment_method' => $filters['payment_method'] ?? '',
                'location_id' => (string) ($filters['location_id'] ?? ''),
                'date' => $filters['date'] ?? '',
                'flagged' => (bool) ($filters['flagged'] ?? false),
            ],
            'options' => [
                'statuses' => Options::of(TransactionStatus::class),
                'methods' => Options::of(PaymentMethod::class),
                'locations' => ParkingLocation::query()->orderBy('location_code')->get(['id', 'location_code', 'name'])
                    ->map(fn (ParkingLocation $l) => ['value' => (string) $l->id, 'label' => "{$l->location_code} — {$l->name}"]),
            ],
        ]);
    }

    public function show(Request $request, ParkingTransaction $transaction): Response
    {
        $transaction->load(['attendant:id,attendant_code,name', 'location:id,location_code,name', 'shift:id,shift_uuid', 'device:id,device_uuid,device_model', 'tariff:id,amount,regulation_reference']);
        /** @var User $viewer */
        $viewer = $request->user();
        $pending = $transaction->voidRequests()->where('status', VoidRequestStatus::PENDING->value)->first();
        $payment = Payment::query()->where('transaction_id', $transaction->id)->first();

        return Inertia::render('Transactions/Show', [
            'transaction' => [
                ...$this->row($transaction),
                'shift' => $transaction->shift?->only(['id', 'shift_uuid']),
                'device' => $transaction->device?->only(['device_uuid', 'device_model']),
                'tariff' => $transaction->tariff?->only(['id', 'amount', 'regulation_reference']),
                'device_tariff_id' => $transaction->device_tariff_id,
                'expected_amount' => $transaction->server_expected_tariff_amount,
                'difference_amount' => $transaction->tariff_difference_amount,
                'transaction_time_device' => $transaction->transaction_time_device->toIso8601String(),
                'sync_sequence' => $transaction->sync_sequence,
                'gps' => [
                    'latitude' => $transaction->latitude,
                    'longitude' => $transaction->longitude,
                    'accuracy_m' => $transaction->gps_accuracy_m,
                    'mock' => $transaction->mock_location,
                    'distance_m' => $transaction->distance_m,
                ],
            ],
            'ledger' => $transaction->ledgerEntries()->orderBy('id')->get()->map(fn (CashLedgerEntry $e) => [
                'id' => $e->id,
                'type' => Options::one($e->type),
                'amount' => $e->amount,
                'balance_after' => $e->balance_after,
                'description' => $e->description,
                'created_at' => $e->created_at->toIso8601String(),
            ]),
            'voidRequests' => $transaction->voidRequests()->with(['requester:id,username', 'decider:id,username'])->orderBy('id')->get()->map(fn (VoidRequest $v) => [
                'id' => $v->id,
                'status' => Options::one($v->status),
                'channel' => $v->channel->value,
                'reason' => $v->reason,
                'requested_by' => $v->requester?->username,
                'decided_by' => $v->decider?->username,
                'decided_at' => $v->decided_at?->toIso8601String(),
                'decision_note' => $v->decision_note,
                'created_at' => $v->created_at->toIso8601String(),
            ]),
            'can' => [
                'requestVoid' => $viewer->can('transactions.void_request') && $transaction->status === TransactionStatus::COMPLETED,
                'decideVoid' => $viewer->can('transactions.void_approve') && $pending !== null && $pending->requested_by !== $viewer->id,
            ],
            'pendingVoidId' => $pending?->id,
            'payment' => $payment === null ? null : [
                'id' => $payment->id,
                'payment_uuid' => $payment->payment_uuid,
                'status' => Options::one($payment->status),
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'refunded' => $payment->refundedAmount(),
            ],
        ]);
    }

    public function requestVoid(Request $request, ParkingTransaction $transaction, RequestVoid $requestVoid): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], attributes: ['reason' => 'alasan']);
        /** @var User $actor */
        $actor = $request->user();
        $requestVoid->handle($transaction, $actor, VoidChannel::ADMIN, $data['reason']);

        return back()->with('success', 'Pengajuan pembatalan dikirim ke supervisor.');
    }

    public function decideVoid(Request $request, VoidRequest $voidRequest, DecideVoid $decide): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'decision_note' => ['nullable', 'string', 'max:500'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $decide->handle($voidRequest, $data['decision'] === 'approve', $actor, $data['decision_note'] ?? null);
        $cash = $voidRequest->transaction()->value('payment_method') === PaymentMethod::CASH->value;

        return back()->with('success', match (true) {
            $data['decision'] !== 'approve' => 'Pengajuan pembatalan ditolak.',
            $cash => 'Transaksi dibatalkan. Kas juru parkir dikoreksi dengan entri reversal.',
            default => 'Transaksi dibatalkan. Pembayaran QRIS tetap Lunas; pengembalian dana dicatat terpisah bila dilakukan.',
        });
    }

    /** @return array<string, mixed> */
    private function row(ParkingTransaction $t): array
    {
        return [
            'id' => $t->id,
            'transaction_uuid' => $t->transaction_uuid,
            'transaction_number' => $t->transaction_number,
            'status' => Options::one($t->status),
            'payment_method' => Options::one($t->payment_method),
            'vehicle_type' => Options::one($t->vehicle_type),
            'vehicle_plate' => $t->vehicle_plate,
            'charged_amount' => $t->charged_tariff_amount,
            'attendant' => $t->attendant?->only(['id', 'attendant_code', 'name']),
            'location' => $t->location?->only(['id', 'location_code']),
            'transaction_time_server' => $t->transaction_time_server->toIso8601String(),
            'offline_created' => $t->offline_created,
            'geofence' => Options::one($t->geofence_result),
            'flags' => array_map(fn (string $f) => Options::one(TransactionFlag::from($f)), $t->review_flags),
        ];
    }
}
