import type { Option } from '@/types';

export interface ShiftRow {
    id: number;
    shift_uuid: string;
    status: Option;
    attendant: { id: number; attendant_code: string; name: string } | null;
    location: { id: number; location_code: string; name: string } | null;
    offline_created: boolean;
    started_at_device: string;
    started_at_server: string;
    ended_at_device: string | null;
    ended_at_server: string | null;
    start_geofence: Option;
    start_distance_m: number | null;
    flags: Option[];
}
