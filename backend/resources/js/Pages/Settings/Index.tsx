import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Card, InputError, PageHeader, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime } from '@/lib/format';

interface Setting {
    key: string;
    label: string;
    description: string;
    value: number;
    default: number;
    min: number;
    max: number;
    updated_at: string | null;
}

function SettingRow({ setting }: { setting: Setting }) {
    const form = useForm({ value: String(setting.value) });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/settings/${setting.key}`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-3 py-4 sm:grid-cols-3" noValidate>
            <div className="sm:col-span-2">
                <div className="text-sm font-medium text-slate-900">{setting.label}</div>
                <p className="text-xs text-slate-500">{setting.description}</p>
                <p className="mt-1 text-xs text-slate-400">
                    Bawaan {setting.default} · rentang {setting.min}–{setting.max}
                    {setting.updated_at && ` · diubah ${formatDateTime(setting.updated_at)}`}
                </p>
            </div>
            <div className="flex items-start gap-2">
                <div className="w-28">
                    <TextInput inputMode="numeric" value={form.data.value} onChange={(e) => form.setData('value', e.target.value.replace(/\D/g, ''))} aria-label={setting.label} />
                    <InputError message={form.errors.value} />
                </div>
                <Button type="submit" variant="secondary" disabled={form.processing || form.data.value === String(setting.value)}>
                    Simpan
                </Button>
            </div>
        </form>
    );
}

export default function SettingsIndex({ settings }: { settings: Setting[] }) {
    return (
        <AdminLayout>
            <Head title="Pengaturan" />
            <PageHeader title="Pengaturan Kebijakan" />
            <Card description="Nilai awal adalah kebijakan bawaan pengembangan, bukan ketentuan resmi. Setiap perubahan dicatat di audit (SETTING_CHANGED).">
                <div className="divide-y divide-slate-100">
                    {settings.map((s) => (
                        <SettingRow key={s.key} setting={s} />
                    ))}
                </div>
            </Card>
        </AdminLayout>
    );
}
