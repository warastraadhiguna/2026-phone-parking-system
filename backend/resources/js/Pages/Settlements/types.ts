import type { Option } from '@/types';

export interface SettlementRow {
    id: number;
    settlement_uuid: string;
    settlement_number: string;
    status: Option;
    amount: number;
    verified_amount: number | null;
    attendant: { id: number; attendant_code: string; name: string } | null;
    submitted_at: string;
}

export function settlementTone(status: string): 'success' | 'danger' | 'info' | 'neutral' {
    if (status === 'VERIFIED') return 'success';
    if (status === 'REJECTED') return 'danger';
    if (status === 'SUBMITTED') return 'info';
    return 'neutral';
}
