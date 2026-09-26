import { useSharedProps } from '@/hooks/useSharedProps';

export function FlashMessage() {
    const { flash } = useSharedProps();

    if (!flash.success) {
        return null;
    }

    return (
        <div role="status" className="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {flash.success}
        </div>
    );
}
