import type { Option } from '@/types';

export interface TransactionRow {
    id: number;
    transaction_uuid: string;
    transaction_number: string;
    status: Option;
    payment_method: Option;
    vehicle_type: Option;
    vehicle_plate: string | null;
    charged_amount: number;
    attendant: { id: number; attendant_code: string; name: string } | null;
    location: { id: number; location_code: string } | null;
    transaction_time_server: string;
    offline_created: boolean;
    geofence: Option;
    flags: Option[];
}
