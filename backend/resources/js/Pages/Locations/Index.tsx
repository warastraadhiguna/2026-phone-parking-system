import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, LinkButton, PageHeader, Select, Table, TextInput, statusTone } from '@/Components/ui';
import { usePermissions } from '@/hooks/usePermissions';
import { AdminLayout } from '@/Layouts/AdminLayout';
import type { Option, Paginated } from '@/types';
import type { LocationRow } from './types';

interface Props {
    locations: Paginated<LocationRow & { current_attendants: number }>;
    filters: { q: string; location_type: string; status: string };
    options: { location_types: Option[]; statuses: Option[] };
}

export default function LocationsIndex({ locations, filters, options }: Props) {
    const { can } = usePermissions();
    const [query, setQuery] = useState(filters);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/locations', query, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout>
            <Head title="Lokasi Parkir" />
            <PageHeader title="Lokasi Parkir" actions={can('locations.manage') && <LinkButton href="/locations/create">Tambah lokasi</LinkButton>} />
            <Card>
                <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-4">
                    <TextInput placeholder="Cari kode, nama, alamat" value={query.q} onChange={(e) => setQuery({ ...query, q: e.target.value })} aria-label="Cari" />
                    <Select value={query.location_type} onChange={(e) => setQuery({ ...query, location_type: e.target.value })} aria-label="Jenis">
                        <option value="">Semua jenis</option>
                        {options.location_types.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                    <Select value={query.status} onChange={(e) => setQuery({ ...query, status: e.target.value })} aria-label="Status">
                        <option value="">Semua status</option>
                        {options.statuses.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </form>
                <Table head={['Kode', 'Nama', 'Jenis', 'Radius', 'Kapasitas (motor/mobil)', 'Jukir hari ini', 'Status']} empty={locations.data.length === 0}>
                    {locations.data.map((l) => (
                        <tr key={l.id}>
                            <td className="py-2 pr-4 font-mono">
                                <Link href={`/locations/${l.id}`} className="text-sky-700 hover:underline">
                                    {l.location_code}
                                </Link>
                            </td>
                            <td className="py-2 pr-4">
                                <div>{l.name}</div>
                                <div className="text-xs text-slate-500">{l.address}</div>
                            </td>
                            <td className="py-2 pr-4">{l.location_type.label}</td>
                            <td className="py-2 pr-4">{l.geofence_radius_m} m</td>
                            <td className="py-2 pr-4">
                                {l.motorcycle_capacity} / {l.car_capacity}
                            </td>
                            <td className="py-2 pr-4">{l.current_attendants}</td>
                            <td className="py-2 pr-4">
                                <Badge tone={statusTone(l.status.value)}>{l.status.label}</Badge>
                            </td>
                        </tr>
                    ))}
                </Table>
                <p className="mt-3 text-xs text-slate-500">{locations.total} lokasi.</p>
                <Pagination page={locations} />
            </Card>
        </AdminLayout>
    );
}
