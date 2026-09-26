import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { EnvironmentBadge } from '@/Components/EnvironmentBadge';
import { FlashMessage } from '@/Components/FlashMessage';
import { usePermissions } from '@/hooks/usePermissions';
import { useSharedProps } from '@/hooks/useSharedProps';

interface NavItem {
    label: string;
    href: string;
    permission?: string;
}

/** Menu entries are added by each phase; items without permission are visible to all staff. */
const NAV_ITEMS: NavItem[] = [
    { label: 'Beranda', href: '/' },
    { label: 'Lokasi', href: '/locations', permission: 'locations.view' },
    { label: 'Juru Parkir', href: '/attendants', permission: 'attendants.view' },
    { label: 'Perangkat', href: '/devices', permission: 'devices.view' },
    { label: 'Tarif', href: '/tariffs', permission: 'tariffs.view' },
    { label: 'Shift', href: '/shifts', permission: 'shifts.view' },
    { label: 'Transaksi', href: '/transactions', permission: 'transactions.view' },
    { label: 'Pembayaran', href: '/payments', permission: 'payments.view' },
    { label: 'Setoran', href: '/settlements', permission: 'settlements.view' },
    { label: 'Rekonsiliasi', href: '/reconciliation', permission: 'reconciliation.view' },
    { label: 'Tinjauan', href: '/reviews', permission: 'anomalies.view' },
    { label: 'Laporan', href: '/reports', permission: 'reports.view' },
    { label: 'Log Audit', href: '/audit-logs', permission: 'audit.view' },
    { label: 'Pengguna', href: '/users', permission: 'users.view' },
    { label: 'Peran & Hak Akses', href: '/roles', permission: 'roles.view' },
    { label: 'Pengaturan', href: '/settings', permission: 'system.configure' },
];

export function AdminLayout({ children }: { children: ReactNode }) {
    const { app, auth } = useSharedProps();
    const { can } = usePermissions();
    const { url } = usePage();

    const isCurrent = (href: string) => (href === '/' ? url === '/' : url.startsWith(href));

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-6 py-3">
                    <div className="flex items-center gap-3">
                        <span className="font-semibold">{app.name}</span>
                        <EnvironmentBadge environment={app.environment} />
                    </div>
                    {auth.user && (
                        <div className="flex items-center gap-4 text-sm">
                            <span className="text-slate-600">
                                {auth.user.name}
                                <span className="ml-1 text-slate-400">({auth.user.roles.map((r) => r.label).join(', ')})</span>
                            </span>
                            <Link href="/logout" method="post" as="button" className="font-medium text-sky-700 hover:underline">
                                Keluar
                            </Link>
                        </div>
                    )}
                </div>
                <nav className="mx-auto flex max-w-6xl flex-wrap gap-1 px-6">
                    {NAV_ITEMS.filter((item) => !item.permission || can(item.permission)).map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={`border-b-2 px-3 py-2 text-sm font-medium ${
                                isCurrent(item.href)
                                    ? 'border-sky-700 text-sky-800'
                                    : 'border-transparent text-slate-600 hover:text-slate-900'
                            }`}
                        >
                            {item.label}
                        </Link>
                    ))}
                </nav>
            </header>
            <main className="mx-auto max-w-6xl px-6 py-8">
                <FlashMessage />
                {children}
            </main>
        </div>
    );
}
