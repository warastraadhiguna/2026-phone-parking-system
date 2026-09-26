import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { Badge, Button, Card, Field, PageHeader, Table, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { Option } from '@/types';
import { paymentTone, type PaymentRow } from './types';

interface Props {
    payment: PaymentRow & {
        provider_order_id: string;
        provider_reference: string | null;
        expired_at: string | null;
        status_reason: string | null;
        charge_attempts: number;
        last_status_check_at: string | null;
        refunded: number;
        location: string | null;
        attendant_name: string | null;
    };
    events: {
        id: number;
        source: string;
        provider_status: string;
        reported_amount: number | null;
        outcome: string;
        status_before: string | null;
        status_after: string | null;
        created_at: string;
    }[];
    adjustments: { id: number; type: Option; amount: number; reason: string; refunded_at: string; recorded_by: string | null }[];
    can: { recordRefund: boolean };
}

const SOURCE: Record<string, string> = { CHARGE: 'Pembuatan QR', STATUS_CHECK: 'Cek status', CANCEL: 'Pembatalan', WEBHOOK: 'Notifikasi' };

export default function PaymentsShow({ payment: p, events, adjustments, can }: Props) {
    const form = useForm({ amount: String(p.amount - p.refunded), reason: '', refunded_at: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!window.confirm('Catat pengembalian dana? Catatan ini tidak dapat diubah atau dihapus.')) return;
        form.post(`/payments/${p.id}/refunds`, { preserveScroll: true, onSuccess: () => form.reset('reason') });
    };

    const row = (label: string, value: ReactNode) => (
        <div className="grid grid-cols-3 gap-4 py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="col-span-2 text-slate-900">{value}</dd>
        </div>
    );

    return (
        <AdminLayout>
            <Head title={`Pembayaran ${p.transaction.transaction_number}`} />
            <PageHeader
                title={`Pembayaran ${p.transaction.transaction_number}`}
                actions={
                    <Link href="/payments" className="text-sm text-sky-700 hover:underline">
                        ← Daftar pembayaran
                    </Link>
                }
            />
            <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Pembayaran" description="Status hanya berubah berdasarkan konfirmasi terverifikasi dari penyedia. Lunas tidak pernah mundur.">
                    <dl className="divide-y divide-slate-100">
                        {row('Status', <Badge tone={paymentTone(p.status.value)}>{p.status.label}</Badge>)}
                        {row('Jumlah', <strong>{formatRupiah(p.amount)}</strong>)}
                        {p.refunded > 0 && row('Dikembalikan manual', <span className="text-amber-700">{formatRupiah(p.refunded)}</span>)}
                        {row('Transaksi', <Link href={`/transactions/${p.transaction.id}`} className="text-sky-700 hover:underline">{`${p.transaction.transaction_number} (${p.transaction.status.label})`}</Link>)}
                        {row('Juru parkir / lokasi', `${p.transaction.attendant_code ?? '—'} ${p.attendant_name ? `— ${p.attendant_name}` : ''} · ${p.location ?? '—'}`)}
                        {row('Penyedia', p.provider)}
                        {row('Order id', <span className="font-mono text-xs">{p.provider_order_id}</span>)}
                        {row('Referensi penyedia', <span className="font-mono text-xs">{p.provider_reference ?? '—'}</span>)}
                        {row('Dibuat / kedaluwarsa', `${formatDateTime(p.created_at)} / ${p.expired_at ? formatDateTime(p.expired_at) : '—'}`)}
                        {row('Lunas pada', p.paid_at ? formatDateTime(p.paid_at) : '—')}
                        {p.status_reason && row('Keterangan', p.status_reason)}
                        {row('Cek status terakhir', p.last_status_check_at ? formatDateTime(p.last_status_check_at) : '—')}
                    </dl>
                </Card>
                <div className="grid content-start gap-6">
                    <Card title="Jawaban penyedia" description="Setiap jawaban terverifikasi dicatat permanen beserta tindakan sistem.">
                        <Table head={['Waktu', 'Sumber', 'Status penyedia', 'Nominal', 'Hasil']} empty={events.length === 0}>
                            {events.map((e) => (
                                <tr key={e.id}>
                                    <td className="py-2 pr-4 text-xs">{formatDateTime(e.created_at)}</td>
                                    <td className="py-2 pr-4 text-xs">{SOURCE[e.source] ?? e.source}</td>
                                    <td className="py-2 pr-4 text-xs">{e.provider_status}</td>
                                    <td className="py-2 pr-4 text-xs">{e.reported_amount === null ? '—' : formatRupiah(e.reported_amount)}</td>
                                    <td className="py-2 pr-4 text-xs">
                                        <Badge tone={e.outcome === 'APPLIED' ? 'success' : e.outcome === 'AMOUNT_MISMATCH' || e.outcome === 'REJECTED' ? 'danger' : 'neutral'}>{e.outcome}</Badge>
                                        {e.status_before !== e.status_after && (
                                            <div className="text-slate-500">
                                                {e.status_before} → {e.status_after}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Pengembalian dana manual" description="Pembayaran tetap Lunas. Pengembalian dicatat terpisah dan dihitung di rekonsiliasi (diterima − dikembalikan).">
                        <Table head={['Waktu', 'Jumlah', 'Alasan', 'Dicatat oleh']} empty={adjustments.length === 0}>
                            {adjustments.map((a) => (
                                <tr key={a.id}>
                                    <td className="py-2 pr-4 text-xs">{formatDateTime(a.refunded_at)}</td>
                                    <td className="py-2 pr-4 font-medium text-amber-700">{formatRupiah(a.amount)}</td>
                                    <td className="py-2 pr-4 text-xs">{a.reason}</td>
                                    <td className="py-2 pr-4 text-xs">{a.recorded_by}</td>
                                </tr>
                            ))}
                        </Table>
                        {can.recordRefund && (
                            <form onSubmit={submit} className="mt-4 grid gap-3 border-t border-slate-100 pt-4">
                                <Field label="Jumlah (Rp)" htmlFor="amount" error={form.errors.amount}>
                                    <TextInput id="amount" type="number" min={1} max={p.amount - p.refunded} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                                </Field>
                                <Field label="Waktu uang dikembalikan" htmlFor="refunded_at" error={form.errors.refunded_at}>
                                    <TextInput id="refunded_at" type="datetime-local" value={form.data.refunded_at} onChange={(e) => form.setData('refunded_at', e.target.value)} />
                                </Field>
                                <Field label="Alasan" htmlFor="reason" error={form.errors.reason}>
                                    <TextInput id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                                </Field>
                                <div>
                                    <Button type="submit" disabled={form.processing}>
                                        Catat pengembalian dana
                                    </Button>
                                </div>
                            </form>
                        )}
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
