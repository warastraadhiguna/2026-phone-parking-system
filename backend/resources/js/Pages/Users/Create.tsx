import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { RoleCheckboxes } from '@/Components/RoleCheckboxes';
import { Button, Card, InputError, Label, PageHeader, TextInput } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import type { Option } from '@/types';

export default function UsersCreate({ roleOptions }: { roleOptions: Option[] }) {
    const form = useForm({
        username: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        roles: [] as string[],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/users', { onError: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AdminLayout>
            <Head title="Tambah staf" />
            <PageHeader title="Tambah staf" />
            <Card description="Akun staf hanya dapat masuk ke Control Center. Username tidak dapat diubah setelah dibuat.">
                <form onSubmit={submit} className="grid max-w-2xl gap-4" noValidate>
                    <div>
                        <Label htmlFor="username">Username</Label>
                        <TextInput id="username" value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} autoComplete="off" />
                        <InputError message={form.errors.username} />
                    </div>
                    <div>
                        <Label htmlFor="name">Nama lengkap</Label>
                        <TextInput id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <InputError message={form.errors.name} />
                    </div>
                    <div>
                        <Label htmlFor="email">Email (opsional)</Label>
                        <TextInput id="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        <InputError message={form.errors.email} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="password">Kata sandi awal</Label>
                            <TextInput
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                            />
                            <InputError message={form.errors.password} />
                        </div>
                        <div>
                            <Label htmlFor="password_confirmation">Ulangi kata sandi</Label>
                            <TextInput
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                            />
                        </div>
                    </div>
                    <p className="-mt-2 text-xs text-slate-500">Minimal 10 karakter, mengandung huruf dan angka.</p>
                    <div>
                        <Label htmlFor="roles">Peran</Label>
                        <RoleCheckboxes options={roleOptions} value={form.data.roles} onChange={(roles) => form.setData('roles', roles)} />
                        <InputError message={form.errors.roles} />
                    </div>
                    <div className="flex gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Simpan
                        </Button>
                        <Link href="/users" className="px-4 py-2 text-sm text-slate-600 hover:underline">
                            Batal
                        </Link>
                    </div>
                </form>
            </Card>
        </AdminLayout>
    );
}
