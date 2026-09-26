import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Card, Field, PageHeader, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';

export default function AttendantsCreate({ today }: { today: string }) {
    const form = useForm({
        name: '',
        identity_number: '',
        phone: '',
        registered_at: today,
        expired_at: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/attendants', { onError: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AdminLayout>
            <Head title="Registrasi juru parkir" />
            <PageHeader title="Registrasi juru parkir" />
            <Card description="Kode juru parkir dibuat otomatis (JP-000001, …) dan menjadi username untuk aplikasi mobile.">
                <form onSubmit={submit} className="grid max-w-2xl gap-4" noValidate>
                    <Field label="Nama lengkap" htmlFor="name" error={form.errors.name}>
                        <TextInput id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="NIK (16 digit)" htmlFor="identity_number" error={form.errors.identity_number}>
                            <TextInput id="identity_number" inputMode="numeric" autoComplete="off" value={form.data.identity_number} onChange={(e) => form.setData('identity_number', e.target.value)} />
                        </Field>
                        <Field label="Nomor telepon" htmlFor="phone" error={form.errors.phone}>
                            <TextInput id="phone" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                        </Field>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Tanggal registrasi" htmlFor="registered_at" error={form.errors.registered_at}>
                            <TextInput id="registered_at" type="date" value={form.data.registered_at} onChange={(e) => form.setData('registered_at', e.target.value)} />
                        </Field>
                        <Field label="Berlaku sampai (opsional)" htmlFor="expired_at" error={form.errors.expired_at} hint="Setelah tanggal ini akun otomatis dinonaktifkan.">
                            <TextInput id="expired_at" type="date" value={form.data.expired_at} onChange={(e) => form.setData('expired_at', e.target.value)} />
                        </Field>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Kata sandi awal aplikasi" htmlFor="password" error={form.errors.password} hint="Minimal 10 karakter, huruf dan angka.">
                            <TextInput id="password" type="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                        </Field>
                        <Field label="Ulangi kata sandi" htmlFor="password_confirmation">
                            <TextInput id="password_confirmation" type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                        </Field>
                    </div>
                    <div className="flex gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Registrasi
                        </Button>
                        <Link href="/attendants" className="px-4 py-2 text-sm text-slate-600 hover:underline">
                            Batal
                        </Link>
                    </div>
                </form>
            </Card>
        </AdminLayout>
    );
}
