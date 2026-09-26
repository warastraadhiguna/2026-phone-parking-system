export interface Metrics {
    transaction_count: number;
    expected_cash: number;
    voided_cash: number;
    ledger_cash_in: number | null;
    ledger_reversals: number | null;
    cash_deposited: number | null;
    cash_outstanding: number | null;
    qris_expected: number;
    qris_paid: number;
    qris_refunded: number;
    qris_difference: number;
    qris_open: number;
    total_revenue: number;
}

export interface RunSummary extends Metrics {
    id: number;
    business_date: string;
    created_at: string;
    run_by: string;
    mismatch_count: number;
    error_count: number;
}
