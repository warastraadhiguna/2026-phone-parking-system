import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';

/** Typed access to the props every page receives from the server. */
export function useSharedProps(): SharedProps {
    return usePage().props;
}
