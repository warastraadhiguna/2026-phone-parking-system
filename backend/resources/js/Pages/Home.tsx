import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { LocationMap, type MapLocation } from '@/Components/LocationMap';
import { Card, PageHeader, Table } from '@/Components/ui';
import { useSharedProps } from '@/hooks/useSharedProps';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatRupiah } from '@/lib/format';

interface Revenue {
    total_revenue: number;
    cash_revenue: number;
    qris_revenue: number;
    transaction_count: number;
}

interface Operational extends Revenue {
    date: string;
    active_attendants: number;
    active_locations: number;
    open_shifts: number;
    outstanding_cash: number;
    pending_settlements: { count: number; amount: number };
    payment_failures: { failed: number; expired: number; open: number };
    anomalies: { HIGH: number; MEDIUM: number; LOW: number };
    locations: MapLocation[];
}

interface Executive {
    date: string;
    today: Revenue;
    month: Revenue;
    target: number;
    target_progress: number | null;
    outstanding_cash: number;
    top_locations: { id: number; code: string; name: string; transactions: number; revenue: number }[];
    trend: { date: string; cash: number; qris: number }[];
    locations: MapLocation[];
}

interface Props {
    view: 'operational' | 'executive' | null;
    available: { operational: boolean; executive: boolean };
    operational: Operational | null;
    executive: Executive | null;
    mapTileUrl: string | null;
    mapAttribution: string | null;
}

function Tile({ label, value, hint, href, tone }: { label: string; value: ReactNode; hint?: string; href?: string; tone?: 'warn' | 'bad' }) {
    const body = (
        <div className={`h-full rounded-lg border bg-white p-4 ${tone === 'bad' ? 'border-red-300' : tone === 'warn' ? 'border-amber-300' : 'border-slate-200'}`}>
            <div className="text-xs text-slate-500">{label}</div>
            <div className="mt-1 text-xl font-semibold text-slate-900">{value}</div>
            {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
        </div>
    );

    return href ? (
        <Link href={href} className="block hover:opacity-80">
            {body}
        </Link>
    ) : (
        body
    );
}

function OperationalDashboard({ d, tileUrl, attribution }: { d: Operational; tileUrl: string | null; attribution: string | null }) {
    const anomalies = d.anomalies.HIGH + d.anomalies.MEDIUM + d.anomalies.LOW;

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Tile label="Pendapatan hari ini" value={formatRupiah(d.total_revenue)} hint={`${d.transaction_count} transaksi`} href={`/transactions?date=${d.date}`} />
                <Tile label="Tunai" value={formatRupiah(d.cash_revenue)} />
                <Tile label="QRIS" value={formatRupiah(d.qris_revenue)} />
                <Tile label="Shift terbuka" value={d.open_shifts} hint={`${d.active_attendants} jukir aktif · ${d.active_locations} lokasi aktif`} href="/shifts" />
                <Tile label="Kas belum disetor" value={formatRupiah(d.outstanding_cash)} href="/settlements" />
                <Tile label="Setoran menunggu verifikasi" value={d.pending_settlements.count} hint={formatRupiah(d.pending_settlements.amount)} href="/settlements?status=SUBMITTED" tone={d.pending_settlements.count > 0 ? 'warn' : undefined} />
                <Tile label="Pembayaran QRIS gagal hari ini" value={d.payment_failures.failed} hint={`${d.payment_failures.expired} kedaluwarsa · ${d.payment_failures.open} menunggu`} href="/payments" tone={d.payment_failures.failed > 0 ? 'warn' : undefined} />
                <Tile label="Anomali belum ditinjau" value={anomalies} hint={`${d.anomalies.HIGH} tinggi · ${d.anomalies.MEDIUM} sedang · ${d.anomalies.LOW} rendah`} href="/reviews" tone={d.anomalies.HIGH > 0 ? 'bad' : anomalies > 0 ? 'warn' : undefined} />
            </div>
            <div className="mt-6">
                <Card title="Peta lokasi hari ini">
                    <LocationMap locations={d.locations} tileUrl={tileUrl} attribution={attribution} />
                </Card>
            </div>
        </>
    );
}

function ExecutiveDashboard({ d, tileUrl, attribution }: { d: Executive; tileUrl: string | null; attribution: string | null }) {
    const max = Math.max(1, ...d.trend.map((t) => t.cash + t.qris));
    const monthTotal = d.month.total_revenue || 1;

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Tile label="Pendapatan hari ini" value={formatRupiah(d.today.total_revenue)} hint={`${d.today.transaction_count} transaksi`} />
                <Tile label="Pendapatan bulan ini" value={formatRupiah(d.month.total_revenue)} hint={`${d.month.transaction_count} transaksi`} />
                <Tile
                    label="Progres target bulanan"
                    value={d.target_progress === null ? 'Belum ada target' : `${d.target_progress}%`}
                    hint={d.target > 0 ? `Target ${formatRupiah(d.target)}` : 'Atur di Pengaturan'}
                />
                <Tile label="Kas belum disetor" value={formatRupiah(d.outstanding_cash)} />
            </div>
            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card title="Tunai vs QRIS (bulan ini)">
                    <div className="flex h-4 overflow-hidden rounded bg-slate-100">
                        <div className="bg-emerald-600" style={{ width: `${(d.month.cash_revenue * 100) / monthTotal}%` }} />
                        <div className="bg-sky-600" style={{ width: `${(d.month.qris_revenue * 100) / monthTotal}%` }} />
                    </div>
                    <p className="mt-2 text-sm text-slate-700">
                        <span className="text-emerald-700">Tunai {formatRupiah(d.month.cash_revenue)}</span> · <span className="text-sky-700">QRIS {formatRupiah(d.month.qris_revenue)}</span>
                    </p>
                </Card>
                <div className="lg:col-span-2">
                    <Card title="Tren pendapatan 30 hari">
                        <div className="flex h-40 items-end gap-1">
                            {d.trend.map((t) => (
                                <div key={t.date} className="flex flex-1 flex-col justify-end" title={`${t.date}: ${formatRupiah(t.cash + t.qris)}`}>
                                    <div className="bg-sky-600" style={{ height: `${(t.qris * 100) / max}%` }} />
                                    <div className="bg-emerald-600" style={{ height: `${(t.cash * 100) / max}%` }} />
                                </div>
                            ))}
                        </div>
                        <div className="mt-1 flex justify-between text-xs text-slate-500">
                            <span>{d.trend[0]?.date}</span>
                            <span>{d.trend[d.trend.length - 1]?.date}</span>
                        </div>
                    </Card>
                </div>
            </div>
            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card title="Lokasi teratas (bulan ini)">
                    <Table head={['Lokasi', 'Trx', 'Pendapatan']} empty={d.top_locations.length === 0}>
                        {d.top_locations.map((l) => (
                            <tr key={l.id}>
                                <td className="py-2 pr-4 text-xs">
                                    {l.code}
                                    <div className="text-slate-500">{l.name}</div>
                                </td>
                                <td className="py-2 pr-4">{l.transactions}</td>
                                <td className="py-2 pr-4 font-medium">{formatRupiah(l.revenue)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <div className="lg:col-span-2">
                    <Card title="Peta lokasi hari ini">
                        <LocationMap locations={d.locations} tileUrl={tileUrl} attribution={attribution} />
                    </Card>
                </div>
            </div>
        </>
    );
}

export default function Home({ view, available, operational, executive, mapTileUrl, mapAttribution }: Props) {
    const { auth } = useSharedProps();

    return (
        <AdminLayout>
            <Head title="Beranda" />
            <PageHeader
                title={view === 'executive' ? 'Dashboard Pimpinan' : view === 'operational' ? 'Dashboard Operasional' : `Selamat datang, ${auth.user?.name ?? ''}`}
                actions={
                    available.operational && available.executive ? (
                        <Link href={view === 'executive' ? '/' : '/?view=executive'} className="text-sm text-sky-700 hover:underline">
                            {view === 'executive' ? 'Lihat dashboard operasional' : 'Lihat dashboard pimpinan'}
                        </Link>
                    ) : undefined
                }
            />
            {view === 'operational' && operational && <OperationalDashboard d={operational} tileUrl={mapTileUrl} attribution={mapAttribution} />}
            {view === 'executive' && executive && <ExecutiveDashboard d={executive} tileUrl={mapTileUrl} attribution={mapAttribution} />}
            {view === null && (
                <Card title="Sistem Perparkiran Kabupaten Pati" description="Control Center">
                    <p className="text-sm text-slate-600">
                        Peran Anda: <strong>{auth.user?.roles.map((r) => r.label).join(', ')}</strong>. Menu yang tersedia menyesuaikan hak akses peran tersebut.
                    </p>
                </Card>
            )}
        </AdminLayout>
    );
}
