import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, Field, PageHeader, Table, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { Paginated } from '@/types';
import type { RunSummary } from './types';

interface Props {
    runs: Paginated<RunSummary>;
    can: { run: boolean };
    yesterday: string;
}

export default function ReconciliationIndex({ runs, can, yesterday }: Props) {
    const form = useForm({ business_date: yesterday });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/reconciliation');
    };

    return (
        <AdminLayout>
            <Head title="Rekonsiliasi" />
            <PageHeader title="Rekonsiliasi Harian" />
            {can.run && (
                <Card title="Jalankan rekonsiliasi" description="Menghitung ulang satu hari (WIB) dari data sumber. Hasil lama tetap disimpan sebagai riwayat.">
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                        <Field label="Tanggal" htmlFor="business_date" error={form.errors.business_date}>
                            <TextInput id="business_date" type="date" value={form.data.business_date} onChange={(e) => form.setData('business_date', e.target.value)} />
                        </Field>
                        <Button type="submit" disabled={form.processing}>
                            Jalankan
                        </Button>
                    </form>
                </Card>
            )}
            <div className="mt-6">
                <Card title="Riwayat rekonsiliasi">
                    <Table head={['Tanggal', 'Dijalankan', 'Pendapatan', 'Tunai diharapkan', 'Belum disetor', 'Selisih QRIS', 'Ketidaksesuaian']} empty={runs.data.length === 0}>
                        {runs.data.map((r) => (
                            <tr key={r.id}>
                                <td className="py-2 pr-4">
                                    <Link href={`/reconciliation/${r.id}`} className="text-sky-700 hover:underline">
                                        {r.business_date}
                                    </Link>
                                </td>
                                <td className="py-2 pr-4 text-xs">
                                    {formatDateTime(r.created_at)}
                                    <div className="text-slate-500">{r.run_by}</div>
                                </td>
                                <td className="py-2 pr-4 font-medium">{formatRupiah(r.total_revenue)}</td>
                                <td className="py-2 pr-4">{formatRupiah(r.expected_cash)}</td>
                                <td className="py-2 pr-4">{formatRupiah(r.cash_outstanding ?? 0)}</td>
                                <td className={`py-2 pr-4 ${r.qris_difference !== 0 ? 'text-amber-700' : ''}`}>{formatRupiah(r.qris_difference)}</td>
                                <td className="py-2 pr-4">
                                    {r.error_count > 0 ? (
                                        <Badge tone="danger">{`${r.error_count} kesalahan`}</Badge>
                                    ) : r.mismatch_count > 0 ? (
                                        <Badge tone="warning">{`${r.mismatch_count} perlu tindakan`}</Badge>
                                    ) : (
                                        <Badge tone="success">Sesuai</Badge>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <Pagination page={runs} />
                </Card>
            </div>
        </AdminLayout>
    );
}
