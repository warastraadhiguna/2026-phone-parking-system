import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, GeneralError, PageHeader, Select, Table, TextInput, statusTone } from '@/Components/ui';
import { usePermissions } from '@/hooks/usePermissions';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime } from '@/lib/format';
import type { Option, Paginated } from '@/types';

interface Row {
    id: number;
    device_uuid: string;
    device_model: string | null;
    android_version: string | null;
    app_version: string | null;
    status: Option;
    attendant: { id: number; attendant_code: string; name: string } | null;
    registered_at: string;
    last_seen_at: string | null;
    deactivation_reason: string | null;
}

export default function DevicesIndex({ devices, filters, statuses }: { devices: Paginated<Row>; filters: { status: string; q: string }; statuses: Option[] }) {
    const { can } = usePermissions();
    const [query, setQuery] = useState(filters);
    const errors = usePage().props.errors as Record<string, string | undefined>;

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/devices', query, { preserveState: true, replace: true });
    };

    const approve = (d: Row) => {
        if (window.confirm(`Setujui perangkat ${d.device_model ?? d.device_uuid} untuk ${d.attendant?.attendant_code}?`)) {
            router.put(`/devices/${d.id}/approve`, {}, { preserveScroll: true });
        }
    };
    const deactivate = (d: Row, status: 'REVOKED' | 'LOST') => {
        const reason = window.prompt(status === 'LOST' ? 'Keterangan perangkat hilang:' : d.status.value === 'PENDING_APPROVAL' ? 'Alasan penolakan:' : 'Alasan pencabutan:');
        if (reason) router.put(`/devices/${d.id}/deactivate`, { status, reason }, { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title="Perangkat" />
            <PageHeader title="Perangkat Juru Parkir" />
            <GeneralError errors={errors} keys={['device', 'reason', 'status']} />
            <Card description="Satu juru parkir hanya boleh memiliki satu perangkat aktif. Perangkat yang dicabut atau hilang tidak dapat diaktifkan kembali.">
                <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-3">
                    <TextInput placeholder="Cari UUID, model, kode/nama jukir" value={query.q} onChange={(e) => setQuery({ ...query, q: e.target.value })} aria-label="Cari" />
                    <Select value={query.status} onChange={(e) => setQuery({ ...query, status: e.target.value })} aria-label="Status">
                        <option value="">Semua status</option>
                        {statuses.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </form>
                <Table head={['Juru parkir', 'Perangkat', 'Terdaftar', 'Terakhir aktif', 'Status', '']} empty={devices.data.length === 0}>
                    {devices.data.map((d) => (
                        <tr key={d.id}>
                            <td className="py-2 pr-4">
                                {d.attendant && (
                                    <Link href={`/attendants/${d.attendant.id}`} className="text-sky-700 hover:underline">
                                        <span className="font-mono">{d.attendant.attendant_code}</span> {d.attendant.name}
                                    </Link>
                                )}
                            </td>
                            <td className="py-2 pr-4">
                                <div>{d.device_model ?? 'Model tidak diketahui'}</div>
                                <div className="font-mono text-xs text-slate-500">{d.device_uuid}</div>
                                <div className="text-xs text-slate-500">
                                    Android {d.android_version ?? '?'} · Aplikasi {d.app_version ?? '?'}
                                </div>
                            </td>
                            <td className="py-2 pr-4">{formatDateTime(d.registered_at)}</td>
                            <td className="py-2 pr-4">{formatDateTime(d.last_seen_at)}</td>
                            <td className="py-2 pr-4">
                                <Badge tone={statusTone(d.status.value)}>{d.status.label}</Badge>
                                {d.deactivation_reason && <div className="text-xs text-slate-500">{d.deactivation_reason}</div>}
                            </td>
                            <td className="whitespace-nowrap py-2 text-right">
                                {can('devices.manage') && d.status.value === 'PENDING_APPROVAL' && (
                                    <>
                                        <button type="button" className="mr-3 font-medium text-emerald-700 hover:underline" onClick={() => approve(d)}>
                                            Setujui
                                        </button>
                                        <button type="button" className="text-red-700 hover:underline" onClick={() => deactivate(d, 'REVOKED')}>
                                            Tolak
                                        </button>
                                    </>
                                )}
                                {can('devices.manage') && d.status.value === 'ACTIVE' && (
                                    <>
                                        <button type="button" className="mr-3 text-red-700 hover:underline" onClick={() => deactivate(d, 'REVOKED')}>
                                            Cabut
                                        </button>
                                        <button type="button" className="text-red-700 hover:underline" onClick={() => deactivate(d, 'LOST')}>
                                            Hilang
                                        </button>
                                    </>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination page={devices} />
            </Card>
        </AdminLayout>
    );
}
