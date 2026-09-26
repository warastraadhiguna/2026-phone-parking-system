import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination } from '@/Components/Pagination';
import { Badge, Button, Card, PageHeader, Select, TextInput, statusTone } from '@/Components/ui';
import { usePermissions } from '@/hooks/usePermissions';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime } from '@/lib/format';
import type { Option, Paginated } from '@/types';
import type { UserRow } from './types';

interface Props {
    users: Paginated<UserRow>;
    filters: { q: string; account_type: string; status: string };
    options: { account_types: Option[]; statuses: Option[] };
}

export default function UsersIndex({ users, filters, options }: Props) {
    const { can } = usePermissions();
    const [query, setQuery] = useState(filters);

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get('/users', query, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout>
            <Head title="Pengguna" />
            <PageHeader
                title="Pengguna"
                actions={
                    can('users.manage') && (
                        <Link href="/users/create" className="rounded-md bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800">
                            Tambah staf
                        </Link>
                    )
                }
            />

            <Card>
                <form onSubmit={applyFilters} className="mb-4 grid gap-3 sm:grid-cols-4">
                    <TextInput
                        placeholder="Cari username atau nama"
                        value={query.q}
                        onChange={(e) => setQuery({ ...query, q: e.target.value })}
                        aria-label="Cari"
                    />
                    <Select value={query.account_type} onChange={(e) => setQuery({ ...query, account_type: e.target.value })} aria-label="Jenis akun">
                        <option value="">Semua jenis akun</option>
                        {options.account_types.map((o) => (
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

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead>
                            <tr className="text-left text-slate-500">
                                <th className="py-2 pr-4 font-medium">Username</th>
                                <th className="py-2 pr-4 font-medium">Nama</th>
                                <th className="py-2 pr-4 font-medium">Jenis</th>
                                <th className="py-2 pr-4 font-medium">Peran</th>
                                <th className="py-2 pr-4 font-medium">Status</th>
                                <th className="py-2 pr-4 font-medium">Login terakhir</th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {users.data.map((user) => (
                                <tr key={user.id}>
                                    <td className="py-2 pr-4 font-mono">{user.username}</td>
                                    <td className="py-2 pr-4">{user.name}</td>
                                    <td className="py-2 pr-4">{user.account_type.label}</td>
                                    <td className="py-2 pr-4">{user.roles.map((r) => r.label).join(', ')}</td>
                                    <td className="py-2 pr-4">
                                        <Badge tone={statusTone(user.status.value)}>{user.status.label}</Badge>
                                    </td>
                                    <td className="py-2 pr-4 text-slate-500">{formatDateTime(user.last_login_at)}</td>
                                    <td className="py-2 text-right">
                                        {can('users.manage') && (
                                            <Link href={`/users/${user.id}/edit`} className="font-medium text-sky-700 hover:underline">
                                                Kelola
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {users.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="py-6 text-center text-slate-500">
                                        Tidak ada pengguna yang cocok.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <p className="mt-3 text-xs text-slate-500">
                    {users.total} pengguna. Akun juru parkir dibuat melalui registrasi juru parkir (tahap berikutnya).
                </p>
                <Pagination page={users} />
            </Card>
        </AdminLayout>
    );
}
