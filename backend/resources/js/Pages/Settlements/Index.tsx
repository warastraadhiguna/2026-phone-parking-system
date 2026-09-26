import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, PageHeader, Select, Table, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { Option, Paginated } from '@/types';
import { settlementTone, type SettlementRow } from './types';

interface Props {
    settlements: Paginated<SettlementRow>;
    outstanding: { id: number; attendant_code: string; name: string; balance: number }[];
    totals: { outstanding: number; pending_count: number; pending_amount: number; verified_today: number };
    filters: { status: string; date: string; q: string };
    options: { statuses: Option[] };
}

export default function SettlementsIndex({ settlements, outstanding, totals, filters, options }: Props) {
    const [query, setQuery] = useState(filters);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/settlements', query, { preserveState: true, replace: true });
    };

    const stat = (label: string, value: string) => (
        <div className="rounded-lg border border-slate-200 bg-white p-4">
            <div className="text-xs text-slate-500">{label}</div>
            <div className="mt-1 text-lg font-semibold text-slate-900">{value}</div>
        </div>
    );

    return (
        <AdminLayout>
            <Head title="Setoran Kas" />
            <PageHeader title="Setoran Kas" />
            <div className="mb-6 grid gap-4 sm:grid-cols-4">
                {stat('Kas belum disetor (semua jukir)', formatRupiah(totals.outstanding))}
                {stat('Menunggu verifikasi', `${totals.pending_count} · ${formatRupiah(totals.pending_amount)}`)}
                {stat('Diverifikasi hari ini', formatRupiah(totals.verified_today))}
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <Card title="Setoran">
                        <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-4">
                            <TextInput placeholder="No. setoran / kode jukir" value={query.q} onChange={(e) => setQuery({ ...query, q: e.target.value })} aria-label="Cari" />
                            <Select value={query.status} onChange={(e) => setQuery({ ...query, status: e.target.value })} aria-label="Status">
                                <option value="">Semua status</option>
                                {options.statuses.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </Select>
                            <TextInput type="date" value={query.date} onChange={(e) => setQuery({ ...query, date: e.target.value })} aria-label="Tanggal" />
                            <Button type="submit" variant="secondary">
                                Terapkan
                            </Button>
                        </form>
                        <Table head={['No. setoran', 'Diajukan', 'Jukir', 'Diajukan (Rp)', 'Diterima (Rp)', 'Status']} empty={settlements.data.length === 0}>
                            {settlements.data.map((s) => (
                                <tr key={s.id}>
                                    <td className="py-2 pr-4 font-mono text-xs">
                                        <Link href={`/settlements/${s.id}`} className="text-sky-700 hover:underline">
                                            {s.settlement_number}
                                        </Link>
                                    </td>
                                    <td className="py-2 pr-4 text-xs">{formatDateTime(s.submitted_at)}</td>
                                    <td className="py-2 pr-4 font-mono text-xs">{s.attendant?.attendant_code}</td>
                                    <td className="py-2 pr-4">{formatRupiah(s.amount)}</td>
                                    <td className="py-2 pr-4">{s.verified_amount === null ? '—' : formatRupiah(s.verified_amount)}</td>
                                    <td className="py-2 pr-4">
                                        <Badge tone={settlementTone(s.status.value)}>{s.status.label}</Badge>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                        <Pagination page={settlements} />
                    </Card>
                </div>
                <Card title="Kas belum disetor per jukir" description="Saldo menurut buku kas: diharapkan − disetor.">
                    <Table head={['Jukir', 'Belum disetor']} empty={outstanding.length === 0}>
                        {outstanding.map((a) => (
                            <tr key={a.id}>
                                <td className="py-2 pr-4 text-xs">
                                    <Link href={`/attendants/${a.id}`} className="text-sky-700 hover:underline">
                                        {a.attendant_code}
                                    </Link>
                                    <div className="text-slate-500">{a.name}</div>
                                </td>
                                <td className={`py-2 pr-4 font-medium ${a.balance < 0 ? 'text-red-700' : ''}`}>{formatRupiah(a.balance)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>
        </AdminLayout>
    );
}
