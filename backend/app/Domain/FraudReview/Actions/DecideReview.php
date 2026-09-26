<?php

namespace App\Domain\FraudReview\Actions;

use App\Domain\Audit\Actions\RecordAuditEvent;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\FraudReview\Enums\ReviewStatus;
use App\Domain\FraudReview\Models\AnomalyReview;
use App\Domain\Identity\Models\User;
use App\Support\Errors\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * A reviewer records the outcome of one signal. It is a finding only: it never changes money
 * or transactions. Follow-up happens through the proper workflows (void, refund, device revoke).
 */
final class DecideReview
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(AnomalyReview $review, User $reviewer, ReviewStatus $decision, string $note): AnomalyReview
    {
        if ($decision === ReviewStatus::OPEN) {
            throw new RuleViolation('decision', 'Pilih hasil tinjauan.');
        }
        if (mb_strlen(trim($note)) < 5) {
            throw new RuleViolation('decision_note', 'Catatan tinjauan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($review, $reviewer, $decision, $note) {
            $review = AnomalyReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($review->status !== ReviewStatus::OPEN) {
                throw new RuleViolation('decision', 'Item ini sudah ditinjau.');
            }

            $review->forceFill([
                'status' => $decision,
                'decided_by' => $reviewer->id,
                'decided_at' => now(),
                'decision_note' => trim($note),
            ])->save();

            $this->audit->handle(AuditAction::ANOMALY_REVIEWED, $reviewer, $review->entity_type, $review->entity_id, [
                'review_id' => $review->id,
                'code' => $review->code,
                'decision' => $decision->value,
                'note' => trim($note),
            ]);

            return $review;
        });
    }
}
