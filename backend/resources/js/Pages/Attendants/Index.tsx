import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, LinkButton, PageHeader, Select, Table, TextInput, statusTone } from '@/Components/ui';
import { usePermissions } from '@/hooks/usePermissions';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDate } from '@/lib/format';
import type { Option, Paginated } from '@/types';

interface Row {
    id: number;
    attendant_code: string;
    name: string;
    phone: string;
    identity_number_masked: string;
    status: Option;
    expired_at: string | null;
    current_location: { location_code: string; name: string } | null;
    devices: Option[];
}

export default function AttendantsIndex({ attendants, filters, statuses }: { attendants: Paginated<Row>; filters: { q: string; status: string }; statuses: Option[] }) {
    const { can } = usePermissions();
    const [query, setQuery] = useState(filters);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get('/attendants', query, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout>
            <Head title="Juru Parkir" />
            <PageHeader title="Juru Parkir" actions={can('attendants.manage') && <LinkButton href="/attendants/create">Registrasi juru parkir</LinkButton>} />
            <Card>
                <form onSubmit={apply} className="mb-4 grid gap-3 sm:grid-cols-3">
                    <TextInput placeholder="Cari kode, nama, telepon" value={query.q} onChange={(e) => setQuery({ ...query, q: e.target.value })} aria-label="Cari" />
                    <Select value={query.status} onChange={(e) => setQuery({ ...query, status: e.target.value })} aria-label="Status">
                        <option value="">Semua status</option>
                        {statuses.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </form>
                <Table head={['Kode', 'Nama', 'NIK', 'Telepon', 'Lokasi hari ini', 'Perangkat', 'Berlaku sampai', 'Status']} empty={attendants.data.length === 0}>
                    {attendants.data.map((a) => (
                        <tr key={a.id}>
                            <td className="py-2 pr-4 font-mono">
                                <Link href={`/attendants/${a.id}`} className="text-sky-700 hover:underline">
                                    {a.attendant_code}
                                </Link>
                            </td>
                            <td className="py-2 pr-4">{a.name}</td>
                            <td className="py-2 pr-4 font-mono text-xs">{a.identity_number_masked}</td>
                            <td className="py-2 pr-4">{a.phone}</td>
                            <td className="py-2 pr-4">{a.current_location ? `${a.current_location.location_code}` : <span className="text-slate-400">—</span>}</td>
                            <td className="py-2 pr-4">
                                {a.devices.length === 0 ? (
                                    <span className="text-slate-400">—</span>
                                ) : (
                                    a.devices.map((d, i) => (
                                        <span key={i} className="mr-1">
                                            <Badge tone={statusTone(d.value)}>{d.label}</Badge>
                                        </span>
                                    ))
                                )}
                            </td>
                            <td className="py-2 pr-4">{formatDate(a.expired_at)}</td>
                            <td className="py-2 pr-4">
                                <Badge tone={statusTone(a.status.value)}>{a.status.label}</Badge>
                            </td>
                        </tr>
                    ))}
                </Table>
                <p className="mt-3 text-xs text-slate-500">{attendants.total} juru parkir.</p>
                <Pagination page={attendants} />
            </Card>
        </AdminLayout>
    );
}
