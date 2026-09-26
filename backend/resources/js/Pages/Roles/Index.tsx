import { Head } from '@inertiajs/react';
import { Card, PageHeader } from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';

interface RoleRow {
    value: string;
    label: string;
    account_type: string;
    users: number;
    permissions: string[];
}

/** Read-only role → permission matrix. The matrix is defined in code and changed only by release. */
export default function RolesIndex({ roles, permissions }: { roles: RoleRow[]; permissions: string[] }) {
    return (
        <AdminLayout>
            <Head title="Peran & Hak Akses" />
            <PageHeader title="Peran & Hak Akses" />
            <Card description="Matriks ini ditetapkan dalam kode aplikasi dan hanya berubah melalui rilis yang disetujui. Perubahan tercatat di audit (ROLE_PERMISSIONS_SYNCED).">
                <div className="overflow-x-auto">
                    <table className="min-w-full border-collapse text-xs">
                        <thead>
                            <tr>
                                <th className="sticky left-0 bg-white py-2 pr-4 text-left font-medium text-slate-500">Hak akses</th>
                                {roles.map((role) => (
                                    <th key={role.value} className="px-2 py-2 text-center align-bottom font-medium text-slate-700">
                                        <div>{role.label}</div>
                                        <div className="font-normal text-slate-400">
                                            {role.account_type} · {role.users} pengguna
                                        </div>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {permissions.map((permission) => (
                                <tr key={permission}>
                                    <td className="sticky left-0 bg-white py-1.5 pr-4 font-mono text-slate-700">{permission}</td>
                                    {roles.map((role) => (
                                        <td key={role.value} className="px-2 py-1.5 text-center">
                                            {role.permissions.includes(permission) ? (
                                                <span className="text-emerald-700" aria-label="diizinkan">
                                                    ●
                                                </span>
                                            ) : (
                                                <span className="text-slate-300" aria-label="tidak diizinkan">
                                                    ·
                                                </span>
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AdminLayout>
    );
}
