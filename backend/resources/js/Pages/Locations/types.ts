import type { Option } from '@/types';

export interface LocationRow {
    id: number;
    location_code: string;
    name: string;
    address: string;
    latitude: string;
    longitude: string;
    geofence_radius_m: number;
    location_type: Option;
    status: Option;
    motorcycle_capacity: number;
    car_capacity: number;
}
