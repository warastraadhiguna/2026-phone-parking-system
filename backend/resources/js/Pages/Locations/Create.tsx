import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Card, Field, PageHeader, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import type { Option } from '@/types';
import { LocationFields, type LocationForm } from './LocationFields';

export default function LocationsCreate({ locationTypes }: { locationTypes: Option[] }) {
    const form = useForm<LocationForm & { location_code: string }>({
        location_code: '',
        name: '',
        address: '',
        latitude: '',
        longitude: '',
        geofence_radius_m: '',
        location_type: '',
        motorcycle_capacity: '0',
        car_capacity: '0',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/locations');
    };

    return (
        <AdminLayout>
            <Head title="Tambah lokasi" />
            <PageHeader title="Tambah lokasi" />
            <Card description="Kode lokasi dipakai di laporan dan tidak dapat diubah setelah dibuat. Radius geofence harus diisi sesuai kondisi lapangan.">
                <form onSubmit={submit} className="grid max-w-3xl gap-4" noValidate>
                    <Field label="Kode lokasi" htmlFor="location_code" error={form.errors.location_code} hint="Huruf besar, angka, tanda hubung. Contoh: PTI-ALUN-01">
                        <TextInput id="location_code" value={form.data.location_code} onChange={(e) => form.setData('location_code', e.target.value)} />
                    </Field>
                    <LocationFields data={form.data} errors={form.errors} set={(k, v) => form.setData(k, v)} locationTypes={locationTypes} />
                    <div className="flex gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Simpan
                        </Button>
                        <Link href="/locations" className="px-4 py-2 text-sm text-slate-600 hover:underline">
                            Batal
                        </Link>
                    </div>
                </form>
            </Card>
        </AdminLayout>
    );
}
