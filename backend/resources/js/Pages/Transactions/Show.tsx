import { Head, Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Badge, Button, Card, GeneralError, PageHeader, Table } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { Option } from '@/types';
import type { TransactionRow } from './types';

interface Props {
    transaction: TransactionRow & {
        shift: { id: number; shift_uuid: string } | null;
        device: { device_uuid: string; device_model: string | null } | null;
        tariff: { id: number; amount: number; regulation_reference: string } | null;
        device_tariff_id: number | null;
        expected_amount: number | null;
        difference_amount: number | null;
        transaction_time_device: string;
        sync_sequence: number;
        gps: { latitude: string | null; longitude: string | null; accuracy_m: string | null; mock: boolean; distance_m: number | null };
    };
    ledger: { id: number; type: Option; amount: number; balance_after: number; description: string | null; created_at: string }[];
    voidRequests: {
        id: number;
        status: Option;
        channel: string;
        reason: string;
        requested_by: string | null;
        decided_by: string | null;
        decided_at: string | null;
        decision_note: string | null;
        created_at: string;
    }[];
    can: { requestVoid: boolean; decideVoid: boolean };
    pendingVoidId: number | null;
    payment: { id: number; payment_uuid: string; status: Option; amount: number; paid_at: string | null; refunded: number } | null;
}

export default function TransactionsShow({ transaction: t, ledger, voidRequests, can, pendingVoidId, payment }: Props) {
    const errors = usePage().props.errors as Record<string, string | undefined>;

    const requestVoid = () => {
        const reason = window.prompt('Alasan pengajuan pembatalan:');
        if (reason) router.post(`/transactions/${t.id}/void-request`, { reason }, { preserveScroll: true });
    };
    const decide = (decision: 'approve' | 'reject') => {
        const note = window.prompt(decision === 'approve' ? 'Catatan persetujuan (opsional):' : 'Alasan penolakan:');
        if (note === null) return;
        router.put(`/void-requests/${pendingVoidId}`, { decision, decision_note: note }, { preserveScroll: true });
    };

    const row = (label: string, value: ReactNode) => (
        <div className="grid grid-cols-3 gap-4 py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="col-span-2 text-slate-900">{value}</dd>
        </div>
    );

    return (
        <AdminLayout>
            <Head title={t.transaction_number} />
            <PageHeader
                title={t.transaction_number}
                actions={
                    <Link href="/transactions" className="text-sm text-sky-700 hover:underline">
                        ← Daftar transaksi
                    </Link>
                }
            />
            <GeneralError errors={errors} keys={['reason', 'decision_note', 'ledger']} />
            <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Transaksi">
                    <dl className="divide-y divide-slate-100">
                        {row('Status', <Badge tone={t.status.value === 'COMPLETED' ? 'success' : t.status.value === 'VOIDED' ? 'danger' : 'info'}>{t.status.label}</Badge>)}
                        {row('Metode', t.payment_method.label)}
                        {row('Kendaraan', `${t.vehicle_type.label}${t.vehicle_plate ? ` · ${t.vehicle_plate}` : ''}`)}
                        {row('Ditagih', <strong>{formatRupiah(t.charged_amount)}</strong>)}
                        {row('Tarif menurut server', t.expected_amount === null ? 'Tidak ditemukan' : `${formatRupiah(t.expected_amount)} (tarif #${t.tariff?.id})`)}
                        {t.difference_amount !== null && t.difference_amount !== 0 && row('Selisih (server − ditagih)', <span className="text-amber-700">{formatRupiah(t.difference_amount)}</span>)}
                        {row('Tarif dipakai perangkat', t.device_tariff_id ? `#${t.device_tariff_id}` : '—')}
                        {row('Juru parkir', t.attendant ? `${t.attendant.attendant_code} — ${t.attendant.name}` : '—')}
                        {row('Lokasi', t.location?.location_code ?? '—')}
                        {row('Shift', t.shift ? <Link href={`/shifts/${t.shift.id}`} className="text-sky-700 hover:underline">{t.shift.shift_uuid}</Link> : '—')}
                        {row('Waktu (perangkat / server)', `${formatDateTime(t.transaction_time_device)} / ${formatDateTime(t.transaction_time_server)}`)}
                        {row('Offline', t.offline_created ? `Ya (urutan sinkron #${t.sync_sequence})` : `Tidak (urutan #${t.sync_sequence})`)}
                        {row('Posisi', t.gps.latitude ? `${t.gps.latitude}, ${t.gps.longitude} (±${t.gps.accuracy_m ?? '?'} m) — ${t.geofence.label}${t.gps.distance_m !== null ? `, ${t.gps.distance_m} m` : ''}${t.gps.mock ? ' — indikasi lokasi palsu' : ''}` : `Tidak ada GPS — ${t.geofence.label}`)}
                        {row('Perangkat', t.device ? `${t.device.device_model ?? '?'} (${t.device.device_uuid})` : '—')}
                        {row(
                            'Tanda tinjauan',
                            t.flags.length === 0
                                ? 'Tidak ada'
                                : t.flags.map((f) => (
                                      <span key={f.value} className="mb-1 mr-1 inline-block">
                                          <Badge tone="warning">{f.label}</Badge>
                                      </span>
                                  )),
                        )}
                    </dl>
                    {(can.requestVoid || can.decideVoid) && (
                        <div className="mt-6 flex flex-wrap gap-3 border-t border-slate-100 pt-4">
                            {can.requestVoid && (
                                <Button variant="secondary" onClick={requestVoid}>
                                    Ajukan pembatalan
                                </Button>
                            )}
                            {can.decideVoid && (
                                <>
                                    <Button variant="danger" onClick={() => decide('approve')}>
                                        Setujui pembatalan
                                    </Button>
                                    <Button variant="secondary" onClick={() => decide('reject')}>
                                        Tolak pembatalan
                                    </Button>
                                </>
                            )}
                        </div>
                    )}
                </Card>
                <div className="grid content-start gap-6">
                    {payment && (
                        <Card title="Pembayaran QRIS" description="Status berasal dari konfirmasi penyedia. Pembatalan transaksi tidak mengubah pembayaran yang sudah lunas.">
                            <dl className="divide-y divide-slate-100">
                                {row('Status', <Badge tone={payment.status.value === 'PAID' ? 'success' : 'info'}>{payment.status.label}</Badge>)}
                                {row('Jumlah', formatRupiah(payment.amount))}
                                {row('Lunas pada', payment.paid_at ? formatDateTime(payment.paid_at) : '—')}
                                {payment.refunded > 0 && row('Dikembalikan manual', formatRupiah(payment.refunded))}
                            </dl>
                            <Link href={`/payments/${payment.id}`} className="mt-3 inline-block text-sm text-sky-700 hover:underline">
                                Detail pembayaran →
                            </Link>
                        </Card>
                    )}
                    <Card title="Buku kas (ledger)" description="Catatan tidak dapat diubah. Pembatalan dicatat sebagai entri reversal.">
                        <Table head={['#', 'Jenis', 'Jumlah', 'Saldo setelah', 'Waktu']} empty={ledger.length === 0}>
                            {ledger.map((e) => (
                                <tr key={e.id}>
                                    <td className="py-2 pr-4 text-xs">{e.id}</td>
                                    <td className="py-2 pr-4">{e.type.label}</td>
                                    <td className={`py-2 pr-4 font-medium ${e.amount < 0 ? 'text-red-700' : 'text-emerald-700'}`}>{formatRupiah(e.amount)}</td>
                                    <td className="py-2 pr-4">{formatRupiah(e.balance_after)}</td>
                                    <td className="py-2 pr-4 text-xs">{formatDateTime(e.created_at)}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Pengajuan pembatalan">
                        <Table head={['Diajukan', 'Oleh', 'Alasan', 'Keputusan']} empty={voidRequests.length === 0}>
                            {voidRequests.map((v) => (
                                <tr key={v.id}>
                                    <td className="py-2 pr-4 text-xs">
                                        {formatDateTime(v.created_at)}
                                        <div className="text-slate-500">{v.channel === 'MOBILE' ? 'aplikasi' : 'control center'}</div>
                                    </td>
                                    <td className="py-2 pr-4 text-xs">{v.requested_by}</td>
                                    <td className="py-2 pr-4 text-xs">{v.reason}</td>
                                    <td className="py-2 pr-4 text-xs">
                                        <Badge tone={v.status.value === 'APPROVED' ? 'danger' : v.status.value === 'REJECTED' ? 'neutral' : 'info'}>{v.status.label}</Badge>
                                        {v.decided_by && (
                                            <div className="text-slate-500">
                                                {v.decided_by}, {formatDateTime(v.decided_at)}
                                                {v.decision_note && ` — ${v.decision_note}`}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
