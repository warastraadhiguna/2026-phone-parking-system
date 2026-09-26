import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, LinkButton, PageHeader, Select, Table, statusTone } from '@/Components/ui';
import { usePermissions } from '@/hooks/usePermissions';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { Paginated } from '@/types';
import type { TariffOptions, TariffRow } from './types';

interface Props {
    tariffs: Paginated<TariffRow>;
    filters: { status: string; vehicle_type: string; location_type: string };
    options: TariffOptions;
}

export default function TariffsIndex({ tariffs, filters, options }: Props) {
    const { can } = usePermissions();
    const [query, setQuery] = useState(filters);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/tariffs', query, { preserveState: true, replace: true });
    };

    const select = (key: keyof typeof query, label: string, list: { value: string; label: string }[]) => (
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
            <Head title="Tarif" />
            <PageHeader title="Tarif Parkir" actions={can('tariffs.manage') && <LinkButton href="/tariffs/create">Buat draf tarif</LinkButton>} />
            <Card description="Tarif berlaku hanya setelah disetujui oleh pengguna selain pembuatnya. Tarif yang disetujui tidak dapat diubah; perubahan dilakukan dengan versi tarif baru.">
                <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-4">
                    {select('status', 'Semua status', options.statuses)}
                    {select('vehicle_type', 'Semua kendaraan', options.vehicle_types)}
                    {select('location_type', 'Semua jenis lokasi', options.location_types)}
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </form>
                <Table head={['#', 'Kendaraan', 'Cakupan', 'Tarif', 'Berlaku', 'Dasar hukum', 'Status']} empty={tariffs.data.length === 0}>
                    {tariffs.data.map((t) => (
                        <tr key={t.id}>
                            <td className="py-2 pr-4">
                                <Link href={`/tariffs/${t.id}`} className="text-sky-700 hover:underline">
                                    #{t.id}
                                </Link>
                            </td>
                            <td className="py-2 pr-4">{t.vehicle_type.label}</td>
                            <td className="py-2 pr-4">
                                {t.location_type.label}
                                {t.location && <div className="text-xs text-slate-500">Khusus {t.location.location_code}</div>}
                            </td>
                            <td className="py-2 pr-4 font-medium">{formatRupiah(t.amount)}</td>
                            <td className="py-2 pr-4 text-xs">
                                {formatDateTime(t.effective_from)}
                                <div className="text-slate-500">s.d. {t.effective_until ? formatDateTime(t.effective_until) : 'seterusnya'}</div>
                            </td>
                            <td className="py-2 pr-4 text-xs text-slate-500">{t.regulation_reference}</td>
                            <td className="py-2 pr-4">
                                <Badge tone={statusTone(t.status.value)}>{t.status.label}</Badge>
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination page={tariffs} />
            </Card>
        </AdminLayout>
    );
}
