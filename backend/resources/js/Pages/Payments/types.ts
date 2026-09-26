import type { Option } from '@/types';

export interface PaymentRow {
    id: number;
    payment_uuid: string;
    status: Option;
    provider: string;
    amount: number;
    created_at: string;
    paid_at: string | null;
    transaction: { id: number; transaction_number: string; status: Option; attendant_code: string | null };
}

export function paymentTone(status: string): 'success' | 'danger' | 'warning' | 'info' | 'neutral' {
    if (status === 'PAID') return 'success';
    if (status === 'FAILED') return 'danger';
    if (status === 'PENDING' || status === 'CREATED') return 'info';
    return 'neutral';
}
