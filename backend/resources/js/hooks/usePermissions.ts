import { useSharedProps } from '@/hooks/useSharedProps';

/**
 * Hides UI the user cannot use. This is convenience only: the server enforces every permission.
 */
export function usePermissions() {
    const { auth } = useSharedProps();
    const granted = new Set(auth.user?.permissions ?? []);

    return {
        can: (permission: string): boolean => granted.has(permission),
    };
}
