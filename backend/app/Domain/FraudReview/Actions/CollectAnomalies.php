<?php

namespace App\Domain\FraudReview\Actions;

use App\Domain\FraudReview\Enums\ReviewSeverity;
use Illuminate\Support\Facades\DB;

/**
 * Fills the review queue from the signals the other modules already record (ADR-0013):
 * review flags on transactions and shifts, and mismatches of the latest reconciliation run of
 * each date. Idempotent (unique source + entity + code), so it can run as often as needed.
 * It only reads other modules' tables and writes nothing but anomaly_reviews.
 *
 * @return array{transactions: int, shifts: int, reconciliation: int}
 */
final class CollectAnomalies
{
    /** @return array{transactions: int, shifts: int, reconciliation: int} */
    public function handle(): array
    {
        return DB::transaction(fn () => [
            'transactions' => DB::affectingStatement(<<<SQL
                INSERT INTO anomaly_reviews (source, entity_type, entity_id, entity_key, reference, code, severity, attendant_id, location_id,
                                             amount, occurred_at, status, created_at, updated_at)
                SELECT 'TRANSACTION', 'parking_transaction', t.transaction_uuid::text, t.id, t.transaction_number, f.code,
                       {$this->severity('f.code')}, t.attendant_id, t.location_id, t.charged_tariff_amount, t.transaction_time_server,
                       'OPEN', now(), now()
                FROM parking_transactions t
                CROSS JOIN LATERAL jsonb_array_elements_text(t.review_flags) AS f(code)
                WHERE jsonb_array_length(t.review_flags) > 0
                ON CONFLICT (source, entity_type, entity_id, code) DO NOTHING
                SQL),
            'shifts' => DB::affectingStatement(<<<SQL
                INSERT INTO anomaly_reviews (source, entity_type, entity_id, entity_key, reference, code, severity, attendant_id, location_id,
                                             amount, occurred_at, status, created_at, updated_at)
                SELECT 'SHIFT', 'shift', s.shift_uuid::text, s.id, NULL, f.code, {$this->severity('f.code')}, s.attendant_id, s.location_id,
                       NULL, s.started_at_server, 'OPEN', now(), now()
                FROM shifts s
                CROSS JOIN LATERAL jsonb_array_elements_text(s.review_flags) AS f(code)
                WHERE jsonb_array_length(s.review_flags) > 0
                ON CONFLICT (source, entity_type, entity_id, code) DO NOTHING
                SQL),
            'reconciliation' => DB::affectingStatement(<<<'SQL'
                INSERT INTO anomaly_reviews (source, entity_type, entity_id, entity_key, reference, code, severity, attendant_id, location_id,
                                             amount, occurred_at, status, created_at, updated_at)
                SELECT 'RECONCILIATION', m.entity_type, m.entity_id, NULL, m.reference, m.code,
                       CASE WHEN m.severity = 'ERROR' THEN 'HIGH' ELSE 'MEDIUM' END,
                       (SELECT a.id FROM parking_attendants a WHERE a.id = NULLIF(m.details->>'attendant_id', '')::bigint),
                       NULL, m.expected_amount, r.window_start, 'OPEN', now(), now()
                FROM reconciliation_mismatches m
                JOIN reconciliation_runs r ON r.id = m.run_id
                WHERE r.id = (SELECT max(r2.id) FROM reconciliation_runs r2 WHERE r2.business_date = r.business_date)
                ON CONFLICT (source, entity_type, entity_id, code) DO NOTHING
                SQL),
        ]);
    }

    private function severity(string $column): string
    {
        return ReviewSeverity::sqlCaseForFlag($column);
    }
}
