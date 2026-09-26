import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, PageHeader, Select, Table, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { Option, Paginated } from '@/types';
import type { TransactionRow } from './types';

interface Props {
    transactions: Paginated<TransactionRow>;
    totals: { count: number; amount: number };
    filters: { q: string; status: string; payment_method: string; location_id: string; date: string; flagged: boolean };
    options: { statuses: Option[]; methods: Option[]; locations: Option[] };
}

export default function TransactionsIndex({ transactions, totals, filters, options }: Props) {
    const [query, setQuery] = useState(filters);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/transactions', { ...query, flagged: query.flagged ? 1 : '' }, { preserveState: true, replace: true });
    };

    const select = (key: 'status' | 'payment_method' | 'location_id', label: string, list: Option[]) => (
        <Select value={query[key]} onChange={(e) => setQuery({ ...query, [key]: e.target.value })} aria-label={label}>
            <option value="">{label}</option>
            {list.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.label}
                </option>
            ))}
        </Select>
    );

    return (
        <AdminLayout>
            <Head title="Transaksi" />
            <PageHeader title="Transaksi Parkir" />
            <Card>
                <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-4 lg:grid-cols-7">
                    <TextInput placeholder="No. transaksi / plat" value={query.q} onChange={(e) => setQuery({ ...query, q: e.target.value })} aria-label="Cari" />
                    {select('status', 'Semua status', options.statuses)}
                    {select('payment_method', 'Semua metode', options.methods)}
                    {select('location_id', 'Semua lokasi', options.locations)}
                    <TextInput type="date" value={query.date} onChange={(e) => setQuery({ ...query, date: e.target.value })} aria-label="Tanggal" />
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={query.flagged} onChange={(e) => setQuery({ ...query, flagged: e.target.checked })} />
                        Bertanda
                    </label>
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </form>
                <p className="mb-3 text-sm text-slate-600">
                    Pendapatan sesuai filter (selesai/diajukan batal): <strong>{formatRupiah(totals.amount)}</strong> dari {totals.count} transaksi.
                </p>
                <Table head={['No. transaksi', 'Waktu', 'Jukir', 'Lokasi', 'Kendaraan', 'Metode', 'Tarif', 'Tanda', 'Status']} empty={transactions.data.length === 0}>
                    {transactions.data.map((t) => (
                        <tr key={t.id}>
                            <td className="py-2 pr-4 font-mono text-xs">
                                <Link href={`/transactions/${t.id}`} className="text-sky-700 hover:underline">
                                    {t.transaction_number}
                                </Link>
                                {t.offline_created && <div className="text-amber-700">offline</div>}
                            </td>
                            <td className="py-2 pr-4 text-xs">{formatDateTime(t.transaction_time_server)}</td>
                            <td className="py-2 pr-4 font-mono text-xs">{t.attendant?.attendant_code}</td>
                            <td className="py-2 pr-4 text-xs">{t.location?.location_code}</td>
                            <td className="py-2 pr-4">
                                {t.vehicle_type.label}
                                {t.vehicle_plate && <div className="font-mono text-xs text-slate-500">{t.vehicle_plate}</div>}
                            </td>
                            <td className="py-2 pr-4">{t.payment_method.label}</td>
                            <td className="py-2 pr-4 font-medium">{formatRupiah(t.charged_amount)}</td>
                            <td className="py-2 pr-4">
                                {t.flags.map((f) => (
                                    <span key={f.value} className="mb-1 mr-1 inline-block">
                                        <Badge tone="warning">{f.label}</Badge>
                                    </span>
                                ))}
                            </td>
                            <td className="py-2 pr-4">
                                <Badge tone={t.status.value === 'COMPLETED' ? 'success' : t.status.value === 'VOIDED' ? 'danger' : 'info'}>{t.status.label}</Badge>
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination page={transactions} />
            </Card>
        </AdminLayout>
    );
}

