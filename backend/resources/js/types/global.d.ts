import type { SharedProps } from '@/types';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}

interface ImportMetaEnv {
    readonly VITE_APP_NAME?: string;
}
