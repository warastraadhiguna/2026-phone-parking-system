import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { RoleCheckboxes } from '@/Components/RoleCheckboxes';
import { Badge, Button, Card, InputError, Label, PageHeader, Select, TextInput, statusTone } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime } from '@/lib/format';
import type { Option } from '@/types';
import type { UserRow } from './types';

interface Props {
    user: UserRow & { created_at: string };
    roleOptions: Option[];
    statusOptions: Option[];
    isSelf: boolean;
}

export default function UsersEdit({ user, roleOptions, statusOptions, isSelf }: Props) {
    const profile = useForm({
        name: user.name,
        email: user.email ?? '',
        roles: user.roles.map((r) => r.value),
    });
    const status = useForm({ status: user.status.value, reason: '' });
    const password = useForm({ password: '', password_confirmation: '' });

    const saveProfile = (event: FormEvent) => {
        event.preventDefault();
        profile.put(`/users/${user.id}`, { preserveScroll: true });
    };

    const saveStatus = (event: FormEvent) => {
        event.preventDefault();
        status.put(`/users/${user.id}/status`, { preserveScroll: true, onSuccess: () => status.setData('reason', '') });
    };

    const savePassword = (event: FormEvent) => {
        event.preventDefault();
        password.put(`/users/${user.id}/password`, { preserveScroll: true, onFinish: () => password.reset() });
    };

    return (
        <AdminLayout>
            <Head title={`Kelola ${user.username}`} />
            <PageHeader
                title={user.name}
                actions={
                    <Link href="/users" className="text-sm text-sky-700 hover:underline">
                        ← Kembali ke daftar
                    </Link>
                }
            />

            <div className="mb-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-600">
                <span>
                    Username: <span className="font-mono text-slate-900">{user.username}</span>
                </span>
                <span>Jenis akun: {user.account_type.label}</span>
                <span>
                    Status: <Badge tone={statusTone(user.status.value)}>{user.status.label}</Badge>
                </span>
                <span>Login terakhir: {formatDateTime(user.last_login_at)}</span>
                <span>Dibuat: {formatDateTime(user.created_at)}</span>
            </div>

            <div className="grid gap-6">
                <Card title="Profil dan peran">
                    <form onSubmit={saveProfile} className="grid max-w-2xl gap-4" noValidate>
                        <div>
                            <Label htmlFor="name">Nama lengkap</Label>
                            <TextInput id="name" value={profile.data.name} onChange={(e) => profile.setData('name', e.target.value)} />
                            <InputError message={profile.errors.name} />
                        </div>
                        <div>
                            <Label htmlFor="email">Email (opsional)</Label>
                            <TextInput id="email" type="email" value={profile.data.email} onChange={(e) => profile.setData('email', e.target.value)} />
                            <InputError message={profile.errors.email} />
                        </div>
                        <div>
                            <Label htmlFor="roles">Peran</Label>
                            <RoleCheckboxes options={roleOptions} value={profile.data.roles} onChange={(roles) => profile.setData('roles', roles)} />
                            <InputError message={profile.errors.roles} />
                        </div>
                        <div>
                            <Button type="submit" disabled={profile.processing}>
                                Simpan perubahan
                            </Button>
                        </div>
                    </form>
                </Card>

                <Card
                    title="Status akun"
                    description="Akun yang tidak aktif tidak dapat masuk. Sesi aplikasi mobile langsung diakhiri; sesi Control Center berakhir pada permintaan berikutnya."
                >
                    <form onSubmit={saveStatus} className="grid max-w-2xl gap-4 sm:grid-cols-3" noValidate>
                        <div>
                            <Label htmlFor="status">Status</Label>
                            <Select id="status" value={status.data.status} onChange={(e) => status.setData('status', e.target.value)} disabled={isSelf}>
                                {statusOptions.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </Select>
                            <InputError message={status.errors.status} />
                        </div>
                        <div className="sm:col-span-2">
                            <Label htmlFor="reason">Alasan (dicatat di audit)</Label>
                            <TextInput id="reason" value={status.data.reason} onChange={(e) => status.setData('reason', e.target.value)} disabled={isSelf} />
                            <InputError message={status.errors.reason} />
                        </div>
                        <div className="sm:col-span-3">
                            <Button type="submit" variant="secondary" disabled={status.processing || isSelf}>
                                Ubah status
                            </Button>
                            {isSelf && <p className="mt-2 text-xs text-slate-500">Anda tidak dapat mengubah status akun Anda sendiri.</p>}
                        </div>
                    </form>
                </Card>

                <Card title="Atur ulang kata sandi" description="Semua sesi aplikasi mobile pengguna ini akan diakhiri.">
                    <form onSubmit={savePassword} className="grid max-w-2xl gap-4 sm:grid-cols-2" noValidate>
                        <div>
                            <Label htmlFor="new_password">Kata sandi baru</Label>
                            <TextInput
                                id="new_password"
                                type="password"
                                autoComplete="new-password"
                                value={password.data.password}
                                onChange={(e) => password.setData('password', e.target.value)}
                            />
                            <InputError message={password.errors.password} />
                        </div>
                        <div>
                            <Label htmlFor="new_password_confirmation">Ulangi kata sandi baru</Label>
                            <TextInput
                                id="new_password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={password.data.password_confirmation}
                                onChange={(e) => password.setData('password_confirmation', e.target.value)}
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <Button type="submit" variant="danger" disabled={password.processing}>
                                Atur ulang kata sandi
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </AdminLayout>
    );
}
