import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Badge, Button, Card, Field, PageHeader, Select, Table, TextInput, statusTone } from '@/Components/ui';
import { usePermissions } from '@/hooks/usePermissions';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import type { Option } from '@/types';
import { LocationFields, type LocationForm } from './LocationFields';
import type { LocationRow } from './types';

interface Props {
    location: LocationRow;
    assignments: { id: number; attendant_id: number; attendant_code: string; attendant_name: string; effective_from: string; effective_until: string | null }[];
    tariffs: { id: number; vehicle_type: Option; amount: number; specific: boolean; regulation_reference: string }[];
    locationTypes: Option[];
    statuses: Option[];
}

export default function LocationsShow({ location, assignments, tariffs, locationTypes, statuses }: Props) {
    const { can } = usePermissions();
    const canManage = can('locations.manage');

    const form = useForm<LocationForm>({
        name: location.name,
        address: location.address,
        latitude: location.latitude,
        longitude: location.longitude,
        geofence_radius_m: String(location.geofence_radius_m),
        location_type: location.location_type.value,
        motorcycle_capacity: String(location.motorcycle_capacity),
        car_capacity: String(location.car_capacity),
    });
    const status = useForm({ status: location.status.value, reason: '' });

    const save = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/locations/${location.id}`, { preserveScroll: true });
    };
    const saveStatus = (e: FormEvent) => {
        e.preventDefault();
        status.put(`/locations/${location.id}/status`, { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title={location.location_code} />
            <PageHeader
                title={`${location.location_code} — ${location.name}`}
                actions={
                    <Link href="/locations" className="text-sm text-sky-700 hover:underline">
                        ← Daftar lokasi
                    </Link>
                }
            />
            <div className="mb-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-600">
                <span>
                    Status: <Badge tone={statusTone(location.status.value)}>{location.status.label}</Badge>
                </span>
                <span>Jenis: {location.location_type.label}</span>
                <span>
                    Koordinat: {location.latitude}, {location.longitude} (radius {location.geofence_radius_m} m)
                </span>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Juru parkir bertugas hari ini">
                    <Table head={['Kode', 'Nama', 'Periode']} empty={assignments.length === 0}>
                        {assignments.map((a) => (
                            <tr key={a.id}>
                                <td className="py-2 pr-4 font-mono">
                                    <Link href={`/attendants/${a.attendant_id}`} className="text-sky-700 hover:underline">
                                        {a.attendant_code}
                                    </Link>
                                </td>
                                <td className="py-2 pr-4">{a.attendant_name}</td>
                                <td className="py-2 pr-4">
                                    {formatDate(a.effective_from)} – {a.effective_until ? formatDate(a.effective_until) : 'seterusnya'}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <Card title="Tarif berlaku saat ini">
                    <Table head={['Kendaraan', 'Tarif', 'Cakupan', 'Dasar hukum']} empty={tariffs.length === 0}>
                        {tariffs.map((t) => (
                            <tr key={t.id}>
                                <td className="py-2 pr-4">{t.vehicle_type.label}</td>
                                <td className="py-2 pr-4 font-medium">{formatRupiah(t.amount)}</td>
                                <td className="py-2 pr-4">{t.specific ? 'Khusus lokasi ini' : 'Jenis lokasi'}</td>
                                <td className="py-2 pr-4 text-xs text-slate-500">{t.regulation_reference}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>

            {canManage && (
                <div className="mt-6 grid gap-6">
                    <Card title="Ubah data lokasi">
                        <form onSubmit={save} className="grid max-w-3xl gap-4" noValidate>
                            <LocationFields data={form.data} errors={form.errors} set={(k, v) => form.setData(k, v)} locationTypes={locationTypes} />
                            <div>
                                <Button type="submit" disabled={form.processing}>
                                    Simpan perubahan
                                </Button>
                            </div>
                        </form>
                    </Card>
                    <Card title="Status lokasi" description="Lokasi yang tidak aktif tidak dapat menerima penugasan baru maupun shift.">
                        <form onSubmit={saveStatus} className="grid max-w-3xl gap-4 sm:grid-cols-3" noValidate>
                            <Field label="Status" htmlFor="status" error={status.errors.status}>
                                <Select id="status" value={status.data.status} onChange={(e) => status.setData('status', e.target.value)}>
                                    {statuses.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Alasan (dicatat di audit)" htmlFor="reason" error={status.errors.reason} className="sm:col-span-2">
                                <TextInput id="reason" value={status.data.reason} onChange={(e) => status.setData('reason', e.target.value)} />
                            </Field>
                            <div>
                                <Button type="submit" variant="secondary" disabled={status.processing}>
                                    Ubah status
                                </Button>
                            </div>
                        </form>
                    </Card>
                </div>
            )}
        </AdminLayout>
    );
}
