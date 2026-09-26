import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, PageHeader, Select, Table } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { Option, Paginated } from '@/types';

interface Item {
    id: number;
    source: Option;
    code: string;
    label: string;
    severity: Option;
    status: Option;
    reference: string;
    href: string | null;
    amount: number | null;
    occurred_at: string;
    attendant: { id: number; attendant_code: string; name: string } | null;
    location: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
}

interface Props {
    items: Paginated<Item>;
    openCounts: { HIGH: number; MEDIUM: number; LOW: number };
    filters: { status: string; severity: string; source: string; code: string; attendant_id: string };
    options: { statuses: Option[]; severities: Option[]; sources: Option[] };
    can: { review: boolean };
}

const tone = (s: string) => (s === 'HIGH' ? 'danger' : s === 'MEDIUM' ? 'warning' : 'neutral');

export default function ReviewsIndex({ items, openCounts, filters, options, can }: Props) {
    const [query, setQuery] = useState(filters);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/reviews', query, { preserveState: true, replace: true });
    };

    const decide = (item: Item, decision: 'CONFIRMED' | 'DISMISSED') => {
        const note = window.prompt(decision === 'CONFIRMED' ? 'Temuan (apa masalahnya dan tindak lanjutnya):' : 'Penjelasan mengapa wajar:');
        if (!note) return;
        router.put(`/reviews/${item.id}`, { decision, decision_note: note }, { preserveScroll: true });
    };

    const select = (key: 'status' | 'severity' | 'source', label: string, list: Option[], allowEmpty = true) => (
        <Select value={query[key]} onChange={(e) => setQuery({ ...query, [key]: e.target.value })} aria-label={label}>
            {allowEmpty && <option value="">{label}</option>}
            {list.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.label}
                </option>
            ))}
        </Select>
    );

    return (
        <AdminLayout>
            <Head title="Tinjauan Anomali" />
            <PageHeader title="Tinjauan Anomali" />
            <p className="mb-4 text-sm text-slate-600">
                Belum ditinjau: <Badge tone="danger">{`${openCounts.HIGH} tinggi`}</Badge> <Badge tone="warning">{`${openCounts.MEDIUM} sedang`}</Badge>{' '}
                <Badge tone="neutral">{`${openCounts.LOW} rendah`}</Badge>. Keputusan tinjauan hanya mencatat temuan; koreksi uang dilakukan melalui pembatalan, pengembalian dana, atau setoran.
            </p>
            <Card>
                <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-4">
                    {select('status', 'Status', options.statuses, false)}
                    {select('severity', 'Semua tingkat', options.severities)}
                    {select('source', 'Semua sumber', options.sources)}
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </form>
                <Table head={['Tingkat', 'Sinyal', 'Data', 'Jukir / lokasi', 'Waktu', query.status === 'OPEN' ? 'Tindakan' : 'Hasil']} empty={items.data.length === 0}>
                    {items.data.map((i) => (
                        <tr key={i.id}>
                            <td className="py-2 pr-4">
                                <Badge tone={tone(i.severity.value)}>{i.severity.label}</Badge>
                            </td>
                            <td className="py-2 pr-4 text-xs">
                                {i.label}
                                <div className="text-slate-500">{i.source.label}</div>
                            </td>
                            <td className="py-2 pr-4 font-mono text-xs">
                                {i.href ? (
                                    <Link href={i.href} className="text-sky-700 hover:underline">
                                        {i.reference}
                                    </Link>
                                ) : (
                                    i.reference
                                )}
                                {i.amount !== null && <div className="text-slate-500">{formatRupiah(i.amount)}</div>}
                            </td>
                            <td className="py-2 pr-4 text-xs">
                                {i.attendant ? `${i.attendant.attendant_code} — ${i.attendant.name}` : '—'}
                                {i.location && <div className="text-slate-500">{i.location}</div>}
                            </td>
                            <td className="py-2 pr-4 text-xs">{formatDateTime(i.occurred_at)}</td>
                            <td className="py-2 pr-4 text-xs">
                                {i.status.value === 'OPEN' ? (
                                    can.review ? (
                                        <div className="flex gap-2">
                                            <Button variant="danger" onClick={() => decide(i, 'CONFIRMED')}>
                                                Bermasalah
                                            </Button>
                                            <Button variant="secondary" onClick={() => decide(i, 'DISMISSED')}>
                                                Wajar
                                            </Button>
                                        </div>
                                    ) : (
                                        'Menunggu supervisor'
                                    )
                                ) : (
                                    <>
                                        <Badge tone={i.status.value === 'CONFIRMED' ? 'danger' : 'success'}>{i.status.label}</Badge>
                                        <div className="text-slate-500">
                                            {i.decided_by}, {formatDateTime(i.decided_at)} — {i.decision_note}
                                        </div>
                                    </>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination page={items} />
            </Card>
        </AdminLayout>
    );
}
