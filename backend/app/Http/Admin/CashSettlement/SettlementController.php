<?php

namespace App\Http\Admin\CashSettlement;

use App\Domain\CashLedger\Models\AttendantCashBalance;
use App\Domain\CashLedger\Services\CashSummary;
use App\Domain\CashSettlement\Actions\DecideSettlement;
use App\Domain\CashSettlement\Actions\SubmitSettlement;
use App\Domain\CashSettlement\Enums\SettlementStatus;
use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\Identity\Models\User;
use App\Http\Admin\Support\Options;
use App\Support\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Control Center: deposits and outstanding cash (master doc §11, §13, Scenario D).
 * Viewing needs settlements.view; deciding needs settlements.verify (Finance).
 */
final class SettlementController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SettlementStatus::class)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:40'],
        ]);

        $query = CashSettlement::query()
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('settlement_number', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('attendant', fn ($a) => $a->where('attendant_code', 'ilike', '%'.addcslashes($term, '%_\\').'%'))))
            ->when($filters['date'] ?? null, function ($q, string $date) {
                $start = CarbonImmutable::parse($date, BusinessTime::timezone())->startOfDay()->utc();
                $q->where('submitted_at', '>=', $start)->where('submitted_at', '<', $start->addDay());
            });

        $settlements = $query->with(['attendant:id,attendant_code,name'])
            ->orderByRaw("CASE WHEN status = 'SUBMITTED' THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CashSettlement $s) => $this->row($s));

        $outstanding = AttendantCashBalance::query()
            ->join('parking_attendants', 'parking_attendants.id', '=', 'attendant_cash_balances.attendant_id')
            ->where('attendant_cash_balances.balance', '<>', 0)
            ->orderByDesc('attendant_cash_balances.balance')
            ->limit(50)
            ->get(['parking_attendants.id', 'parking_attendants.attendant_code', 'parking_attendants.name', 'attendant_cash_balances.balance'])
            ->map(fn ($r) => ['id' => (int) $r->getAttribute('id'), 'attendant_code' => $r->getAttribute('attendant_code'), 'name' => $r->getAttribute('name'), 'balance' => (int) $r->getAttribute('balance')]);

        $todayStart = now(BusinessTime::timezone())->startOfDay()->utc();

        return Inertia::render('Settlements/Index', [
            'settlements' => $settlements,
            'outstanding' => $outstanding,
            'totals' => [
                'outstanding' => (int) AttendantCashBalance::query()->sum('balance'),
                'pending_count' => CashSettlement::query()->where('status', SettlementStatus::SUBMITTED->value)->count(),
                'pending_amount' => (int) CashSettlement::query()->where('status', SettlementStatus::SUBMITTED->value)->sum('amount'),
                'verified_today' => (int) CashSettlement::query()->where('status', SettlementStatus::VERIFIED->value)->where('decided_at', '>=', $todayStart)->sum('verified_amount'),
            ],
            'filters' => ['status' => $filters['status'] ?? '', 'date' => $filters['date'] ?? '', 'q' => $filters['q'] ?? ''],
            'options' => ['statuses' => Options::of(SettlementStatus::class)],
        ]);
    }

    public function show(Request $request, CashSettlement $settlement, CashSummary $summary): Response
    {
        $settlement->load(['attendant:id,attendant_code,name', 'shift:id,shift_uuid', 'submitter:id,username', 'decider:id,username', 'ledgerEntry']);
        /** @var User $viewer */
        $viewer = $request->user();
        $entry = $settlement->ledgerEntry;

        return Inertia::render('Settlements/Show', [
            'settlement' => [
                ...$this->row($settlement),
                'balance_at_submission' => $settlement->balance_at_submission,
                'notes' => $settlement->notes,
                'has_proof' => $settlement->proof_path !== null,
                'shift' => $settlement->shift?->only(['id', 'shift_uuid']),
                'submitted_by' => $settlement->submitter?->username,
                'decided_by' => $settlement->decider?->username,
                'decided_at' => $settlement->decided_at?->toIso8601String(),
                'decision_note' => $settlement->decision_note,
                'ledger' => $entry === null ? null : ['id' => $entry->id, 'amount' => $entry->amount, 'balance_after' => $entry->balance_after],
            ],
            'attendantSummary' => $summary->forAttendant($settlement->attendant_id),
            'can' => [
                'decide' => $viewer->can('settlements.verify') && $settlement->status === SettlementStatus::SUBMITTED && $settlement->submitted_by !== $viewer->id,
            ],
        ]);
    }

    public function proof(CashSettlement $settlement): StreamedResponse
    {
        abort_if($settlement->proof_path === null, 404);

        return Storage::disk(SubmitSettlement::PROOF_DISK)->response($settlement->proof_path, headers: [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function decide(Request $request, CashSettlement $settlement, DecideSettlement $decide): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verify', 'reject'])],
            'verified_amount' => ['required_if:decision,verify', 'nullable', 'integer', 'min:1'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ], attributes: ['verified_amount' => 'jumlah diterima', 'decision_note' => 'catatan']);
        /** @var User $actor */
        $actor = $request->user();

        if ($data['decision'] === 'verify') {
            $decide->verify($settlement, $actor, (int) $data['verified_amount'], $data['decision_note'] ?? null);

            return back()->with('success', 'Setoran diverifikasi. Kas juru parkir berkurang sesuai jumlah diterima.');
        }

        $decide->reject($settlement, $actor, (string) ($data['decision_note'] ?? ''));

        return back()->with('success', 'Setoran ditolak. Kas juru parkir tidak berubah.');
    }

    /** @return array<string, mixed> */
    private function row(CashSettlement $s): array
    {
        return [
            'id' => $s->id,
            'settlement_uuid' => $s->settlement_uuid,
            'settlement_number' => $s->settlement_number,
            'status' => Options::one($s->status),
            'amount' => $s->amount,
            'verified_amount' => $s->verified_amount,
            'attendant' => $s->attendant?->only(['id', 'attendant_code', 'name']),
            'submitted_at' => $s->submitted_at->toIso8601String(),
        ];
    }
}
