import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, PageHeader, Select, Table, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime } from '@/lib/format';
import type { Option, Paginated } from '@/types';
import type { ShiftRow } from './types';

interface Props {
    shifts: Paginated<ShiftRow>;
    filters: { status: string; location_id: string; date: string; flagged: boolean };
    statuses: Option[];
    locations: Option[];
    maxOpenHours: number;
}

export default function ShiftsIndex({ shifts, filters, statuses, locations, maxOpenHours }: Props) {
    const [query, setQuery] = useState(filters);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/shifts', { ...query, flagged: query.flagged ? 1 : '' }, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout>
            <Head title="Shift" />
            <PageHeader title="Shift Juru Parkir" />
            <Card description={`Shift yang terbuka lebih dari ${maxOpenHours} jam ditandai "Melebihi batas waktu shift" dan dapat ditutup paksa oleh supervisor.`}>
                <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-5">
                    <Select value={query.status} onChange={(e) => setQuery({ ...query, status: e.target.value })} aria-label="Status">
                        <option value="">Semua status</option>
                        {statuses.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                    <Select value={query.location_id} onChange={(e) => setQuery({ ...query, location_id: e.target.value })} aria-label="Lokasi">
                        <option value="">Semua lokasi</option>
                        {locations.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                    <TextInput type="date" value={query.date} onChange={(e) => setQuery({ ...query, date: e.target.value })} aria-label="Tanggal" />
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={query.flagged} onChange={(e) => setQuery({ ...query, flagged: e.target.checked })} />
                        Hanya yang bertanda
                    </label>
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </form>
                <Table head={['Juru parkir', 'Lokasi', 'Mulai', 'Selesai', 'Geofence', 'Tanda', 'Status']} empty={shifts.data.length === 0}>
                    {shifts.data.map((s) => (
                        <tr key={s.id}>
                            <td className="py-2 pr-4">
                                <Link href={`/shifts/${s.id}`} className="text-sky-700 hover:underline">
                                    <span className="font-mono">{s.attendant?.attendant_code}</span> {s.attendant?.name}
                                </Link>
                            </td>
                            <td className="py-2 pr-4">{s.location?.location_code}</td>
                            <td className="py-2 pr-4 text-xs">
                                {formatDateTime(s.started_at_server)}
                                {s.offline_created && <div className="text-amber-700">offline</div>}
                            </td>
                            <td className="py-2 pr-4 text-xs">{formatDateTime(s.ended_at_server)}</td>
                            <td className="py-2 pr-4 text-xs">
                                {s.start_geofence.label}
                                {s.start_distance_m !== null && <div className="text-slate-500">{s.start_distance_m} m</div>}
                            </td>
                            <td className="py-2 pr-4">
                                {s.flags.map((f) => (
                                    <span key={f.value} className="mb-1 mr-1 inline-block">
                                        <Badge tone="warning">{f.label}</Badge>
                                    </span>
                                ))}
                            </td>
                            <td className="py-2 pr-4">
                                <Badge tone={s.status.value === 'OPEN' ? 'info' : s.status.value === 'FORCED_CLOSED' ? 'danger' : 'neutral'}>{s.status.label}</Badge>
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination page={shifts} />
            </Card>
        </AdminLayout>
    );
}

