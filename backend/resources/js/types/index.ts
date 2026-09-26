/** A labelled enum value as sent by the server ({ value: 'ACTIVE', label: 'Aktif' }). */
export interface Option {
    value: string;
    label: string;
}

export interface AuthUser {
    id: number;
    username: string;
    name: string;
    roles: Option[];
    permissions: string[];
}

/** Props shared with every page by App\Http\Middleware\HandleInertiaRequests. */
export interface SharedProps {
    [key: string]: unknown;
    app: {
        name: string;
        environment: string;
    };
    auth: {
        user: AuthUser | null;
    };
    flash: {
        success: string | null;
    };
}

/** Laravel LengthAwarePaginator serialised to JSON. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}
