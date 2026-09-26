import type { Option } from '@/types';

export interface TariffRow {
    id: number;
    vehicle_type: Option;
    location_type: Option;
    location: { id: number; location_code: string; name: string } | null;
    amount: number;
    effective_from: string;
    effective_from_local: string | null;
    effective_until: string | null;
    regulation_reference: string;
    status: Option;
    created_by: string | null;
    approved_by: string | null;
    approved_at: string | null;
    rejection_reason: string | null;
}

export interface TariffOptions {
    vehicle_types: Option[];
    location_types: Option[];
    statuses: Option[];
    locations: (Option & { location_type: string })[];
}
