import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { Badge, Button, Card, Field, PageHeader, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { settlementTone, type SettlementRow } from './types';

interface Props {
    settlement: SettlementRow & {
        balance_at_submission: number;
        notes: string | null;
        has_proof: boolean;
        shift: { id: number; shift_uuid: string } | null;
        submitted_by: string | null;
        decided_by: string | null;
        decided_at: string | null;
        decision_note: string | null;
        ledger: { id: number; amount: number; balance_after: number } | null;
    };
    attendantSummary: { collected: number; deposited: number; outstanding: number };
    can: { decide: boolean };
}

export default function SettlementsShow({ settlement: s, attendantSummary: sum, can }: Props) {
    const form = useForm({ decision: 'verify', verified_amount: String(s.amount), decision_note: '' });

    const submit = (decision: 'verify' | 'reject') => (e: FormEvent) => {
        e.preventDefault();
        const text =
            decision === 'verify'
                ? `Verifikasi setoran: kas diterima ${formatRupiah(Number(form.data.verified_amount) || 0)}? Keputusan tidak dapat diubah.`
                : 'Tolak setoran ini? Keputusan tidak dapat diubah.';
        if (!window.confirm(text)) return;
        form.transform((data) => ({ ...data, decision }));
        form.put(`/settlements/${s.id}/decision`, { preserveScroll: true });
    };

    const row = (label: string, value: ReactNode) => (
        <div className="grid grid-cols-3 gap-4 py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="col-span-2 text-slate-900">{value}</dd>
        </div>
    );
    const differs = form.data.verified_amount !== '' && Number(form.data.verified_amount) !== s.amount;

    return (
        <AdminLayout>
            <Head title={s.settlement_number} />
            <PageHeader
                title={`Setoran ${s.settlement_number}`}
                actions={
                    <Link href="/settlements" className="text-sm text-sky-700 hover:underline">
                        ← Daftar setoran
                    </Link>
                }
            />
            <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Setoran" description="Setoran yang sudah diputuskan tidak dapat diubah.">
                    <dl className="divide-y divide-slate-100">
                        {row('Status', <Badge tone={settlementTone(s.status.value)}>{s.status.label}</Badge>)}
                        {row('Juru parkir', s.attendant ? <Link href={`/attendants/${s.attendant.id}`} className="text-sky-700 hover:underline">{`${s.attendant.attendant_code} — ${s.attendant.name}`}</Link> : '—')}
                        {row('Jumlah diajukan', <strong>{formatRupiah(s.amount)}</strong>)}
                        {row('Kas tercatat saat mengajukan', formatRupiah(s.balance_at_submission))}
                        {s.verified_amount !== null && row('Jumlah diterima', <strong>{formatRupiah(s.verified_amount)}</strong>)}
                        {row('Diajukan', `${formatDateTime(s.submitted_at)} oleh ${s.submitted_by ?? '—'}`)}
                        {row('Shift', s.shift ? <Link href={`/shifts/${s.shift.id}`} className="text-sky-700 hover:underline">{s.shift.shift_uuid}</Link> : '—')}
                        {row('Catatan jukir', s.notes ?? '—')}
                        {row(
                            'Bukti',
                            s.has_proof ? (
                                <a href={`/settlements/${s.id}/proof`} target="_blank" rel="noreferrer" className="text-sky-700 hover:underline">
                                    Lihat foto bukti
                                </a>
                            ) : (
                                'Tidak ada'
                            ),
                        )}
                        {s.decided_by && row('Diputuskan', `${formatDateTime(s.decided_at)} oleh ${s.decided_by}`)}
                        {s.decision_note && row('Catatan keputusan', s.decision_note)}
                        {s.ledger && row('Buku kas', `Entri #${s.ledger.id}: ${formatRupiah(s.ledger.amount)}, saldo setelahnya ${formatRupiah(s.ledger.balance_after)}`)}
                    </dl>
                </Card>
                <div className="grid content-start gap-6">
                    <Card title="Kas juru parkir (buku kas)">
                        <dl className="divide-y divide-slate-100">
                            {row('Diharapkan (terkumpul)', formatRupiah(sum.collected))}
                            {row('Sudah disetor', formatRupiah(sum.deposited))}
                            {row('Belum disetor', <strong>{formatRupiah(sum.outstanding)}</strong>)}
                        </dl>
                    </Card>
                    {can.decide && (
                        <Card title="Keputusan keuangan" description="Masukkan jumlah uang yang benar-benar dihitung. Hanya jumlah itu yang mengurangi kas jukir; sisanya tetap tercatat belum disetor.">
                            <form onSubmit={submit('verify')} className="grid gap-3">
                                <Field label="Jumlah diterima (Rp)" htmlFor="verified_amount" error={form.errors.verified_amount}>
                                    <TextInput id="verified_amount" type="number" min={1} value={form.data.verified_amount} onChange={(e) => form.setData('verified_amount', e.target.value)} />
                                </Field>
                                <Field label={differs ? 'Catatan (wajib: jumlah berbeda)' : 'Catatan'} htmlFor="decision_note" error={form.errors.decision_note}>
                                    <TextInput id="decision_note" value={form.data.decision_note} onChange={(e) => form.setData('decision_note', e.target.value)} />
                                </Field>
                                <div className="flex flex-wrap gap-3">
                                    <Button type="submit" disabled={form.processing}>
                                        Verifikasi
                                    </Button>
                                    <Button type="button" variant="danger" disabled={form.processing} onClick={submit('reject')}>
                                        Tolak (isi catatan)
                                    </Button>
                                </div>
                            </form>
                        </Card>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
