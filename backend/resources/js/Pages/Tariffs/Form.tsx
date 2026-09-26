import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Card, Field, PageHeader, Select, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatRupiah } from '@/lib/format';
import type { TariffOptions, TariffRow } from './types';

/** Create a draft, or edit an existing draft. */
export default function TariffsForm({ tariff, options }: { tariff: TariffRow | null; options: TariffOptions }) {
    const form = useForm({
        vehicle_type: tariff?.vehicle_type.value ?? '',
        location_type: tariff?.location_type.value ?? '',
        location_id: tariff?.location ? String(tariff.location.id) : '',
        amount: tariff ? String(tariff.amount) : '',
        effective_from: tariff?.effective_from_local ?? '',
        regulation_reference: tariff?.regulation_reference ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (tariff) {
            form.put(`/tariffs/${tariff.id}`);
        } else {
            form.post('/tariffs');
        }
    };

    const locationsOfType = options.locations.filter((l) => l.location_type === form.data.location_type);
    const amount = Number.parseInt(form.data.amount, 10);

    return (
        <AdminLayout>
            <Head title={tariff ? `Ubah draf #${tariff.id}` : 'Buat draf tarif'} />
            <PageHeader title={tariff ? `Ubah draf tarif #${tariff.id}` : 'Buat draf tarif'} />
            <Card description="Draf belum berlaku. Setelah disimpan, pengguna lain yang berwenang harus menyetujuinya.">
                <form onSubmit={submit} className="grid max-w-2xl gap-4" noValidate>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Jenis kendaraan" htmlFor="vehicle_type" error={form.errors.vehicle_type}>
                            <Select id="vehicle_type" value={form.data.vehicle_type} onChange={(e) => form.setData('vehicle_type', e.target.value)}>
                                <option value="">Pilih…</option>
                                {options.vehicle_types.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Jenis lokasi" htmlFor="location_type" error={form.errors.location_type}>
                            <Select
                                id="location_type"
                                value={form.data.location_type}
                                onChange={(e) => form.setData({ ...form.data, location_type: e.target.value, location_id: '' })}
                            >
                                <option value="">Pilih…</option>
                                {options.location_types.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                    <Field label="Khusus lokasi (opsional)" htmlFor="location_id" error={form.errors.location_id} hint="Kosongkan untuk berlaku di semua lokasi dengan jenis tersebut.">
                        <Select id="location_id" value={form.data.location_id} onChange={(e) => form.setData('location_id', e.target.value)} disabled={!form.data.location_type}>
                            <option value="">Semua lokasi jenis ini</option>
                            {locationsOfType.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Tarif (Rupiah, bilangan bulat)"
                            htmlFor="amount"
                            error={form.errors.amount}
                            hint={Number.isFinite(amount) && amount > 0 ? formatRupiah(amount) : undefined}
                        >
                            <TextInput id="amount" inputMode="numeric" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value.replace(/\D/g, ''))} />
                        </Field>
                        <Field label="Mulai berlaku (WIB)" htmlFor="effective_from" error={form.errors.effective_from} hint="Harus di masa mendatang saat disetujui.">
                            <TextInput id="effective_from" type="datetime-local" value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Dasar hukum" htmlFor="regulation_reference" error={form.errors.regulation_reference} hint="Contoh: Perda Kab. Pati No. … Tahun … Pasal …">
                        <TextInput id="regulation_reference" value={form.data.regulation_reference} onChange={(e) => form.setData('regulation_reference', e.target.value)} />
                    </Field>
                    <div className="flex gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Simpan draf
                        </Button>
                        <Link href={tariff ? `/tariffs/${tariff.id}` : '/tariffs'} className="px-4 py-2 text-sm text-slate-600 hover:underline">
                            Batal
                        </Link>
                    </div>
                </form>
            </Card>
        </AdminLayout>
    );
}
