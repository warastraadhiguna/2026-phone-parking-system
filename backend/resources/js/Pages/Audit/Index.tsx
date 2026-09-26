import { Head, router } from '@inertiajs/react';
import { Fragment, useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Button, Card, PageHeader, Select, Table, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

interface Log {
    id: number;
    occurred_at: string;
    actor_type: string;
    actor: string | null;
    action: string;
    entity_type: string | null;
    entity_id: string | null;
    metadata: Record<string, unknown>;
    ip_address: string | null;
    device_uuid: string | null;
    request_id: string | null;
}

interface Props {
    logs: Paginated<Log>;
    filters: { action: string; actor: string; entity_type: string; entity_id: string; request_id: string; date: string };
    actions: string[];
}

export default function AuditIndex({ logs, filters, actions }: Props) {
    const [query, setQuery] = useState(filters);
    const [open, setOpen] = useState<number | null>(null);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/audit-logs', query, { preserveState: true, replace: true });
    };
    const text = (key: keyof typeof query, placeholder: string) => (
        <TextInput placeholder={placeholder} value={query[key]} onChange={(e) => setQuery({ ...query, [key]: e.target.value })} aria-label={placeholder} />
    );

    return (
        <AdminLayout>
            <Head title="Log Audit" />
            <PageHeader title="Log Audit" />
            <p className="mb-4 text-sm text-slate-600">Catatan audit tidak dapat diubah atau dihapus. Halaman ini hanya untuk membaca.</p>
            <Card>
                <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-4 lg:grid-cols-7">
                    <Select value={query.action} onChange={(e) => setQuery({ ...query, action: e.target.value })} aria-label="Aksi">
                        <option value="">Semua aksi</option>
                        {actions.map((a) => (
                            <option key={a} value={a}>
                                {a}
                            </option>
                        ))}
                    </Select>
                    {text('actor', 'Pelaku (username)')}
                    {text('entity_type', 'Jenis data')}
                    {text('entity_id', 'ID data')}
                    {text('request_id', 'Request ID')}
                    <TextInput type="date" value={query.date} onChange={(e) => setQuery({ ...query, date: e.target.value })} aria-label="Tanggal" />
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </form>
                <Table head={['Waktu', 'Pelaku', 'Aksi', 'Data', 'Asal', '']} empty={logs.data.length === 0}>
                    {logs.data.map((l) => (
                        <Fragment key={l.id}>
                            <tr>
                                <td className="py-2 pr-4 text-xs">{formatDateTime(l.occurred_at)}</td>
                                <td className="py-2 pr-4 text-xs">{l.actor ?? (l.actor_type === 'SYSTEM' ? 'sistem' : l.actor_type.toLowerCase())}</td>
                                <td className="py-2 pr-4 font-mono text-xs">{l.action}</td>
                                <td className="py-2 pr-4 text-xs">
                                    {l.entity_type ?? '—'}
                                    {l.entity_id && <div className="font-mono text-slate-500">{l.entity_id}</div>}
                                </td>
                                <td className="py-2 pr-4 text-xs text-slate-500">
                                    {l.ip_address ?? '—'}
                                    {l.device_uuid && <div className="font-mono">{l.device_uuid}</div>}
                                </td>
                                <td className="py-2 pr-4 text-xs">
                                    <button type="button" className="text-sky-700 hover:underline" onClick={() => setOpen(open === l.id ? null : l.id)}>
                                        {open === l.id ? 'Tutup' : 'Detail'}
                                    </button>
                                </td>
                            </tr>
                            {open === l.id && (
                                <tr>
                                    <td colSpan={6} className="bg-slate-50 p-3">
                                        <div className="mb-1 text-xs text-slate-500">Request ID: {l.request_id ?? '—'}</div>
                                        <pre className="overflow-x-auto text-xs">{JSON.stringify(l.metadata, null, 2)}</pre>
                                    </td>
                                </tr>
                            )}
                        </Fragment>
                    ))}
                </Table>
                <Pagination page={logs} />
            </Card>
        </AdminLayout>
    );
}
