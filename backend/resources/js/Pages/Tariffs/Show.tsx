import { Head, Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Badge, Button, Card, GeneralError, PageHeader, statusTone } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { TariffRow } from './types';

export default function TariffsShow({ tariff, can }: { tariff: TariffRow; can: { edit: boolean; approve: boolean; reject: boolean } }) {
    const errors = usePage().props.errors as Record<string, string | undefined>;

    const approve = () => {
        if (window.confirm(`Setujui tarif ${formatRupiah(tariff.amount)} untuk ${tariff.vehicle_type.label}, berlaku ${formatDateTime(tariff.effective_from)}?`)) {
            router.put(`/tariffs/${tariff.id}/approve`, {}, { preserveScroll: true });
        }
    };
    const reject = () => {
        const reason = window.prompt('Alasan penolakan:');
        if (reason) router.put(`/tariffs/${tariff.id}/reject`, { reason }, { preserveScroll: true });
    };

    const row = (label: string, value: ReactNode) => (
        <div className="grid grid-cols-3 gap-4 py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="col-span-2 text-slate-900">{value}</dd>
        </div>
    );

    return (
        <AdminLayout>
            <Head title={`Tarif #${tariff.id}`} />
            <PageHeader
                title={`Tarif #${tariff.id}`}
                actions={
                    <Link href="/tariffs" className="text-sm text-sky-700 hover:underline">
                        ← Daftar tarif
                    </Link>
                }
            />
            <GeneralError errors={errors} keys={['tariff', 'effective_from', 'reason', 'amount']} />
            <Card>
                <dl className="divide-y divide-slate-100">
                    {row('Status', <Badge tone={statusTone(tariff.status.value)}>{tariff.status.label}</Badge>)}
                    {row('Jenis kendaraan', tariff.vehicle_type.label)}
                    {row('Jenis lokasi', tariff.location_type.label)}
                    {row('Cakupan', tariff.location ? `Khusus ${tariff.location.location_code} — ${tariff.location.name}` : 'Semua lokasi jenis ini')}
                    {row('Tarif', <span className="font-semibold">{formatRupiah(tariff.amount)}</span>)}
                    {row('Mulai berlaku', formatDateTime(tariff.effective_from))}
                    {row('Berakhir', tariff.effective_until ? formatDateTime(tariff.effective_until) : 'Belum ditentukan (sampai diganti versi baru)')}
                    {row('Dasar hukum', tariff.regulation_reference)}
                    {row('Dibuat oleh', tariff.created_by ?? '—')}
                    {row('Disetujui oleh', tariff.approved_by ? `${tariff.approved_by} (${formatDateTime(tariff.approved_at)})` : '—')}
                    {tariff.rejection_reason && row('Alasan penolakan', tariff.rejection_reason)}
                </dl>
                {(can.edit || can.approve || can.reject) && (
                    <div className="mt-6 flex flex-wrap gap-3 border-t border-slate-100 pt-4">
                        {can.edit && (
                            <Link href={`/tariffs/${tariff.id}/edit`} className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                Ubah draf
                            </Link>
                        )}
                        {can.approve && <Button onClick={approve}>Setujui</Button>}
                        {can.reject && (
                            <Button variant="danger" onClick={reject}>
                                Tolak
                            </Button>
                        )}
                    </div>
                )}
                {tariff.status.value === 'DRAFT' && !can.approve && (
                    <p className="mt-4 text-xs text-slate-500">Draf ini menunggu persetujuan pengguna lain yang berwenang.</p>
                )}
            </Card>
        </AdminLayout>
    );
}
