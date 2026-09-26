import { Head, Link } from '@inertiajs/react';
import { Badge, Card, PageHeader, Table } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { Option } from '@/types';
import type { Metrics, RunSummary } from './types';

type Line = Metrics & { dimension_id: number; label: string };

interface Props {
    run: RunSummary;
    attendants: Line[];
    locations: Line[];
    mismatches: {
        id: number;
        code: string;
        label: string;
        severity: Option;
        entity_type: string;
        entity_id: string;
        reference: string | null;
        expected_amount: number | null;
        actual_amount: number | null;
    }[];
    newerRun: number | null;
}

const rp = (v: number | null) => (v === null ? '—' : formatRupiah(v));

function entityLink(type: string, id: string, reference: string | null) {
    const text = reference ?? id;
    if (type === 'parking_attendant') return <Link href={`/attendants/${id}`} className="text-sky-700 hover:underline">{`Jukir #${id}`}</Link>;
    return <span className="font-mono text-xs">{text}</span>;
}

export default function ReconciliationShow({ run, attendants, locations, mismatches, newerRun }: Props) {
    const stat = (label: string, value: string, tone = '') => (
        <div className="rounded-lg border border-slate-200 bg-white p-4">
            <div className="text-xs text-slate-500">{label}</div>
            <div className={`mt-1 text-lg font-semibold ${tone || 'text-slate-900'}`}>{value}</div>
        </div>
    );

    const lineTable = (rows: Line[], cash: boolean) => (
        <Table
            head={cash ? ['Jukir', 'Trx', 'Tunai diharapkan', 'Tunai batal', 'Disetor', 'Belum disetor (akhir hari)', 'QRIS diharapkan', 'QRIS lunas', 'Selisih QRIS', 'Pendapatan'] : ['Lokasi', 'Trx', 'Tunai diharapkan', 'Tunai batal', 'QRIS diharapkan', 'QRIS lunas', 'Selisih QRIS', 'Pendapatan']}
            empty={rows.length === 0}
        >
            {rows.map((l) => (
                <tr key={l.dimension_id}>
                    <td className="py-2 pr-4 text-xs">{l.label}</td>
                    <td className="py-2 pr-4">{l.transaction_count}</td>
                    <td className="py-2 pr-4">{rp(l.expected_cash)}</td>
                    <td className="py-2 pr-4">{rp(l.voided_cash)}</td>
                    {cash && <td className="py-2 pr-4">{rp(l.cash_deposited)}</td>}
                    {cash && <td className="py-2 pr-4 font-medium">{rp(l.cash_outstanding)}</td>}
                    <td className="py-2 pr-4">{rp(l.qris_expected)}</td>
                    <td className="py-2 pr-4">{rp(l.qris_paid - l.qris_refunded)}</td>
                    <td className={`py-2 pr-4 ${l.qris_difference !== 0 ? 'text-amber-700' : ''}`}>{rp(l.qris_difference)}</td>
                    <td className="py-2 pr-4 font-medium">{rp(l.total_revenue)}</td>
                </tr>
            ))}
        </Table>
    );

    return (
        <AdminLayout>
            <Head title={`Rekonsiliasi ${run.business_date}`} />
            <PageHeader
                title={`Rekonsiliasi ${run.business_date}`}
                actions={
                    <Link href="/reconciliation" className="text-sm text-sky-700 hover:underline">
                        ← Riwayat
                    </Link>
                }
            />
            <p className="mb-4 text-sm text-slate-600">
                Dijalankan {formatDateTime(run.created_at)} oleh {run.run_by}. Data dihitung ulang dari transaksi, pembayaran, buku kas dan setoran; hasil ini tidak dapat diubah.
                {newerRun && (
                    <>
                        {' '}
                        Ada hasil yang lebih baru:{' '}
                        <Link href={`/reconciliation/${newerRun}`} className="text-sky-700 hover:underline">
                            lihat
                        </Link>
                        .
                    </>
                )}
            </p>
            <div className="mb-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                {stat('Total pendapatan', formatRupiah(run.total_revenue))}
                {stat('Tunai diharapkan', formatRupiah(run.expected_cash))}
                {stat('Tunai disetor (hari ini)', formatRupiah(run.cash_deposited ?? 0))}
                {stat('Belum disetor (akhir hari)', formatRupiah(run.cash_outstanding ?? 0))}
                {stat('QRIS diharapkan / lunas', `${formatRupiah(run.qris_expected)} / ${formatRupiah(run.qris_paid - run.qris_refunded)}`)}
                {stat('Selisih QRIS', formatRupiah(run.qris_difference), run.qris_difference !== 0 ? 'text-amber-700' : '')}
            </div>
            <div className="grid gap-6">
                <Card title="Ketidaksesuaian" description="Kesalahan integritas seharusnya tidak pernah terjadi dan harus diselidiki. 'Perlu tindakan' menunggu keputusan manusia.">
                    <Table head={['Tingkat', 'Jenis', 'Data', 'Diharapkan', 'Aktual']} empty={mismatches.length === 0}>
                        {mismatches.map((m) => (
                            <tr key={m.id}>
                                <td className="py-2 pr-4">
                                    <Badge tone={m.severity.value === 'ERROR' ? 'danger' : 'warning'}>{m.severity.label}</Badge>
                                </td>
                                <td className="py-2 pr-4 text-xs">{m.label}</td>
                                <td className="py-2 pr-4">{entityLink(m.entity_type, m.entity_id, m.reference)}</td>
                                <td className="py-2 pr-4 text-xs">{rp(m.expected_amount)}</td>
                                <td className="py-2 pr-4 text-xs">{rp(m.actual_amount)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <Card title="Per juru parkir">{lineTable(attendants, true)}</Card>
                <Card title="Per lokasi">{lineTable(locations, false)}</Card>
            </div>
        </AdminLayout>
    );
}
