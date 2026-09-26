import type { Option } from '@/types';

export interface UserRow {
    id: number;
    username: string;
    name: string;
    email: string | null;
    account_type: Option;
    status: Option;
    roles: Option[];
    last_login_at: string | null;
}
