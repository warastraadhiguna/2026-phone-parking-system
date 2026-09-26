import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Badge, Button, Card, PageHeader, Select, Table, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime } from '@/lib/format';
import type { Option } from '@/types';

interface Props {
    types: { value: string; label: string; dated: boolean }[];
    filters: { type: string; from: string; to: string; location_id: string; attendant_id: string; payment_method: string };
    preview: { type: string; title: string; columns: Record<string, string>; rows: Record<string, string | number | null>[]; limit: number } | null;
    options: { locations: Option[]; attendants: Option[] };
    exports: {
        id: number;
        report: string;
        format: string;
        params: Record<string, string | number>;
        status: Option;
        row_count: number | null;
        error: string | null;
        created_at: string;
        expires_at: string | null;
    }[];
    can: { export: boolean };
}

const statusTone = (s: string) => (s === 'DONE' ? 'success' : s === 'FAILED' ? 'danger' : s === 'EXPIRED' ? 'neutral' : 'info');

export default function ReportsIndex({ types, filters, preview, options, exports, can }: Props) {
    const [query, setQuery] = useState({ ...filters, type: filters.type || types[0]?.value || '' });
    const current = types.find((t) => t.value === query.type);

    const show = (e: FormEvent) => {
        e.preventDefault();
        router.get('/reports', query, { preserveState: true, replace: true });
    };
    const exportAs = (format: 'csv' | 'xlsx' | 'pdf') => router.post('/reports/exports', { ...query, format }, { preserveScroll: true });

    return (
        <AdminLayout>
            <Head title="Laporan" />
            <PageHeader title="Laporan" />
            <Card>
                <form onSubmit={show} className="grid gap-3 sm:grid-cols-3 lg:grid-cols-7">
                    <Select value={query.type} onChange={(e) => setQuery({ ...query, type: e.target.value })} aria-label="Jenis laporan" className="lg:col-span-2">
                        {types.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </Select>
                    <TextInput type="date" value={query.from} onChange={(e) => setQuery({ ...query, from: e.target.value })} aria-label="Dari" disabled={!current?.dated} />
                    <TextInput type="date" value={query.to} onChange={(e) => setQuery({ ...query, to: e.target.value })} aria-label="Sampai" disabled={!current?.dated} />
                    <Select value={query.location_id} onChange={(e) => setQuery({ ...query, location_id: e.target.value })} aria-label="Lokasi">
                        <option value="">Semua lokasi</option>
                        {options.locations.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                    <Select value={query.attendant_id} onChange={(e) => setQuery({ ...query, attendant_id: e.target.value })} aria-label="Juru parkir">
                        <option value="">Semua jukir</option>
                        {options.attendants.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                    <Select value={query.payment_method} onChange={(e) => setQuery({ ...query, payment_method: e.target.value })} aria-label="Metode">
                        <option value="">Semua metode</option>
                        <option value="CASH">Tunai</option>
                        <option value="QRIS">QRIS</option>
                    </Select>
                    <div className="flex flex-wrap gap-2 lg:col-span-7">
                        <Button type="submit" variant="secondary">
                            Tampilkan
                        </Button>
                        {can.export && (
                            <>
                                <Button type="button" onClick={() => exportAs('xlsx')}>
                                    Ekspor XLSX
                                </Button>
                                <Button type="button" onClick={() => exportAs('csv')}>
                                    Ekspor CSV
                                </Button>
                                <Button type="button" onClick={() => exportAs('pdf')}>
                                    Ekspor PDF
                                </Button>
                            </>
                        )}
                    </div>
                </form>
            </Card>
            {preview && (
                <div className="mt-6">
                    <Card title={preview.title} description={`Pratinjau maksimal ${preview.limit} baris. Ekspor berisi seluruh data dan diproses di antrean.`}>
                        <Table head={Object.values(preview.columns)} empty={preview.rows.length === 0}>
                            {preview.rows.map((r, i) => (
                                <tr key={i}>
                                    {Object.keys(preview.columns).map((k) => (
                                        <td key={k} className="py-1 pr-4 text-xs">
                                            {r[k] === null || r[k] === undefined ? '—' : typeof r[k] === 'number' ? r[k].toLocaleString('id-ID') : String(r[k])}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
            )}
            {can.export && (
                <div className="mt-6">
                    <Card title="Ekspor saya" description="File disimpan 7 hari. Muat ulang halaman untuk memperbarui status.">
                        <Table head={['Diminta', 'Laporan', 'Format', 'Baris', 'Status', '']} empty={exports.length === 0}>
                            {exports.map((e) => (
                                <tr key={e.id}>
                                    <td className="py-2 pr-4 text-xs">{formatDateTime(e.created_at)}</td>
                                    <td className="py-2 pr-4 text-xs">
                                        {e.report}
                                        <div className="text-slate-500">{[e.params.from, e.params.to].filter(Boolean).join(' s.d. ')}</div>
                                    </td>
                                    <td className="py-2 pr-4 text-xs">{e.format}</td>
                                    <td className="py-2 pr-4 text-xs">{e.row_count ?? '—'}</td>
                                    <td className="py-2 pr-4 text-xs">
                                        <Badge tone={statusTone(e.status.value)}>{e.status.label}</Badge>
                                        {e.error && <div className="text-red-700">{e.error}</div>}
                                    </td>
                                    <td className="py-2 pr-4 text-xs">
                                        {e.status.value === 'DONE' && (
                                            <a href={`/reports/exports/${e.id}`} className="text-sky-700 hover:underline">
                                                Unduh
                                            </a>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
            )}
        </AdminLayout>
    );
}
