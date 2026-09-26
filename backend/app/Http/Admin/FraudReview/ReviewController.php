<?php

namespace App\Http\Admin\FraudReview;

use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\FraudReview\Actions\DecideReview;
use App\Domain\FraudReview\Enums\ReviewSeverity;
use App\Domain\FraudReview\Enums\ReviewSource;
use App\Domain\FraudReview\Enums\ReviewStatus;
use App\Domain\FraudReview\Models\AnomalyReview;
use App\Domain\Identity\Models\User;
use App\Domain\ParkingTransaction\Enums\TransactionFlag;
use App\Domain\ParkingTransaction\Models\ParkingTransaction;
use App\Domain\Reconciliation\Enums\MismatchCode;
use App\Domain\Shift\Enums\ShiftFlag;
use App\Http\Admin\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Control Center review queue (master doc Phase 9). anomalies.view reads; anomalies.review
 * (Supervisor) decides. A decision is a finding and never changes money.
 */
final class ReviewController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ReviewStatus::class)],
            'severity' => ['nullable', Rule::enum(ReviewSeverity::class)],
            'source' => ['nullable', Rule::enum(ReviewSource::class)],
            'code' => ['nullable', 'string', 'max:50'],
            'attendant_id' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? ReviewStatus::OPEN->value;
        /** @var User $viewer */
        $viewer = $request->user();

        $items = AnomalyReview::query()
            ->with(['attendant:id,attendant_code,name', 'location:id,location_code', 'decider:id,username'])
            ->where('status', $status)
            ->when($filters['severity'] ?? null, fn ($q, string $v) => $q->where('severity', $v))
            ->when($filters['source'] ?? null, fn ($q, string $v) => $q->where('source', $v))
            ->when($filters['code'] ?? null, fn ($q, string $v) => $q->where('code', $v))
            ->when($filters['attendant_id'] ?? null, fn ($q, int|string $v) => $q->where('attendant_id', (int) $v))
            ->orderByRaw("CASE severity WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 ELSE 2 END")
            ->orderByDesc('occurred_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AnomalyReview $r) => [
                'id' => $r->id,
                'source' => Options::one($r->source),
                'code' => $r->code,
                'label' => self::label($r),
                'severity' => Options::one($r->severity),
                'status' => Options::one($r->status),
                'reference' => $r->reference ?? $r->entity_id,
                'href' => self::href($r),
                'amount' => $r->amount,
                'occurred_at' => $r->occurred_at->toIso8601String(),
                'attendant' => $r->attendant?->only(['id', 'attendant_code', 'name']),
                'location' => $r->location?->location_code,
                'decided_by' => $r->decider?->username,
                'decided_at' => $r->decided_at?->toIso8601String(),
                'decision_note' => $r->decision_note,
            ]);

        $open = AnomalyReview::query()->where('status', ReviewStatus::OPEN->value)
            ->selectRaw('severity, count(*) AS n')->groupBy('severity')->pluck('n', 'severity');

        return Inertia::render('Reviews/Index', [
            'items' => $items,
            'openCounts' => ['HIGH' => (int) ($open['HIGH'] ?? 0), 'MEDIUM' => (int) ($open['MEDIUM'] ?? 0), 'LOW' => (int) ($open['LOW'] ?? 0)],
            'filters' => ['status' => $status, 'severity' => $filters['severity'] ?? '', 'source' => $filters['source'] ?? '', 'code' => $filters['code'] ?? '', 'attendant_id' => (string) ($filters['attendant_id'] ?? '')],
            'options' => [
                'statuses' => Options::of(ReviewStatus::class),
                'severities' => Options::of(ReviewSeverity::class),
                'sources' => Options::of(ReviewSource::class),
            ],
            'can' => ['review' => $viewer->can('anomalies.review')],
        ]);
    }

    public function decide(Request $request, AnomalyReview $review, DecideReview $decide): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([ReviewStatus::CONFIRMED->value, ReviewStatus::DISMISSED->value])],
            'decision_note' => ['required', 'string', 'min:5', 'max:1000'],
        ], attributes: ['decision_note' => 'catatan']);
        /** @var User $actor */
        $actor = $request->user();

        $decide->handle($review, $actor, ReviewStatus::from($data['decision']), $data['decision_note']);

        return back()->with('success', 'Hasil tinjauan dicatat.');
    }

    private static function label(AnomalyReview $r): string
    {
        return match ($r->source) {
            ReviewSource::TRANSACTION => TransactionFlag::tryFrom($r->code)?->label() ?? $r->code,
            ReviewSource::SHIFT => ShiftFlag::tryFrom($r->code)?->label() ?? $r->code,
            ReviewSource::RECONCILIATION => MismatchCode::tryFrom($r->code)?->label() ?? $r->code,
        };
    }

    private static function href(AnomalyReview $r): ?string
    {
        return match ($r->entity_type) {
            'parking_transaction' => ($id = $r->entity_key ?? ParkingTransaction::query()->where('transaction_uuid', $r->entity_id)->value('id')) ? "/transactions/{$id}" : null,
            'shift' => $r->entity_key !== null ? "/shifts/{$r->entity_key}" : null,
            'cash_settlement' => ($id = CashSettlement::query()->where('settlement_uuid', $r->entity_id)->value('id')) ? "/settlements/{$id}" : null,
            'parking_attendant' => "/attendants/{$r->entity_id}",
            default => null,
        };
    }
}
