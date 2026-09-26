import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, PageHeader, Select, Table, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { Option, Paginated } from '@/types';
import { paymentTone, type PaymentRow } from './types';

interface Props {
    payments: Paginated<PaymentRow>;
    totals: { received: number; refunded: number; net: number };
    filters: { q: string; status: string; date: string };
    options: { statuses: Option[] };
}

export default function PaymentsIndex({ payments, totals, filters, options }: Props) {
    const [query, setQuery] = useState(filters);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/payments', query, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout>
            <Head title="Pembayaran" />
            <PageHeader title="Pembayaran QRIS" />
            <Card>
                <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-4">
                    <TextInput placeholder="No. transaksi / order id / ref. penyedia" value={query.q} onChange={(e) => setQuery({ ...query, q: e.target.value })} aria-label="Cari" />
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
                <p className="mb-3 text-sm text-slate-600">
                    Sesuai filter — diterima (lunas): <strong>{formatRupiah(totals.received)}</strong>, dikembalikan manual: <strong>{formatRupiah(totals.refunded)}</strong>, bersih:{' '}
                    <strong>{formatRupiah(totals.net)}</strong>.
                </p>
                <Table head={['Dibuat', 'Transaksi', 'Jukir', 'Penyedia', 'Jumlah', 'Lunas pada', 'Status']} empty={payments.data.length === 0}>
                    {payments.data.map((p) => (
                        <tr key={p.id}>
                            <td className="py-2 pr-4 text-xs">
                                <Link href={`/payments/${p.id}`} className="text-sky-700 hover:underline">
                                    {formatDateTime(p.created_at)}
                                </Link>
                            </td>
                            <td className="py-2 pr-4 font-mono text-xs">
                                <Link href={`/transactions/${p.transaction.id}`} className="text-sky-700 hover:underline">
                                    {p.transaction.transaction_number}
                                </Link>
                                <div className="text-slate-500">{p.transaction.status.label}</div>
                            </td>
                            <td className="py-2 pr-4 font-mono text-xs">{p.transaction.attendant_code}</td>
                            <td className="py-2 pr-4 text-xs">{p.provider}</td>
                            <td className="py-2 pr-4 font-medium">{formatRupiah(p.amount)}</td>
                            <td className="py-2 pr-4 text-xs">{p.paid_at ? formatDateTime(p.paid_at) : '—'}</td>
                            <td className="py-2 pr-4">
                                <Badge tone={paymentTone(p.status.value)}>{p.status.label}</Badge>
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination page={payments} />
            </Card>
        </AdminLayout>
    );
}
