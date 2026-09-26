import { Head, Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Badge, Button, Card, GeneralError, PageHeader } from '@/Components/ui';
import { usePermissions } from '@/hooks/usePermissions';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime } from '@/lib/format';
import type { Option } from '@/types';
import type { ShiftRow } from './types';

interface Position {
    latitude: string | null;
    longitude: string | null;
    accuracy_m: string | null;
    mock: boolean;
}

interface Props {
    shift: ShiftRow & {
        device: { device_uuid: string; device_model: string | null } | null;
        assignment_id: number | null;
        start: Position;
        end: Position & { geofence: Option | null; distance_m: number | null };
        close_reason: string | null;
    };
}

function position(p: Position): string {
    if (!p.latitude || !p.longitude) return 'Tidak ada data GPS';
    return `${p.latitude}, ${p.longitude}${p.accuracy_m ? ` (±${p.accuracy_m} m)` : ''}${p.mock ? ' — indikasi lokasi palsu' : ''}`;
}

export default function ShiftsShow({ shift }: Props) {
    const { can } = usePermissions();
    const errors = usePage().props.errors as Record<string, string | undefined>;

    const forceClose = () => {
        const reason = window.prompt('Alasan penutupan paksa (dicatat di audit):');
        if (reason) router.put(`/shifts/${shift.id}/force-close`, { reason }, { preserveScroll: true });
    };

    const row = (label: string, value: ReactNode) => (
        <div className="grid grid-cols-3 gap-4 py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="col-span-2 text-slate-900">{value}</dd>
        </div>
    );

    return (
        <AdminLayout>
            <Head title="Detail shift" />
            <PageHeader
                title={`Shift ${shift.attendant?.attendant_code ?? ''} — ${shift.location?.location_code ?? ''}`}
                actions={
                    <Link href="/shifts" className="text-sm text-sky-700 hover:underline">
                        ← Daftar shift
                    </Link>
                }
            />
            <GeneralError errors={errors} keys={['reason']} />
            <Card>
                <dl className="divide-y divide-slate-100">
                    {row('Status', <Badge tone={shift.status.value === 'OPEN' ? 'info' : shift.status.value === 'FORCED_CLOSED' ? 'danger' : 'neutral'}>{shift.status.label}</Badge>)}
                    {row('UUID shift', <span className="font-mono text-xs">{shift.shift_uuid}</span>)}
                    {row('Juru parkir', shift.attendant ? `${shift.attendant.attendant_code} — ${shift.attendant.name}` : '—')}
                    {row('Lokasi', shift.location ? `${shift.location.location_code} — ${shift.location.name}` : '—')}
                    {row('Penugasan', shift.assignment_id ? `#${shift.assignment_id}` : 'Tidak sesuai penugasan')}
                    {row('Perangkat', shift.device ? `${shift.device.device_model ?? 'Model tidak diketahui'} (${shift.device.device_uuid})` : '—')}
                    {row('Dibuat offline', shift.offline_created ? 'Ya' : 'Tidak')}
                    {row('Mulai (perangkat / server)', `${formatDateTime(shift.started_at_device)} / ${formatDateTime(shift.started_at_server)}`)}
                    {row('Posisi mulai', `${position(shift.start)} — ${shift.start_geofence.label}${shift.start_distance_m !== null ? `, ${shift.start_distance_m} m dari titik lokasi` : ''}`)}
                    {row('Selesai (perangkat / server)', `${formatDateTime(shift.ended_at_device)} / ${formatDateTime(shift.ended_at_server)}`)}
                    {shift.end.geofence && row('Posisi selesai', `${position(shift.end)} — ${shift.end.geofence.label}${shift.end.distance_m !== null ? `, ${shift.end.distance_m} m` : ''}`)}
                    {row(
                        'Tanda tinjauan',
                        shift.flags.length === 0
                            ? 'Tidak ada'
                            : shift.flags.map((f) => (
                                  <span key={f.value} className="mb-1 mr-1 inline-block">
                                      <Badge tone="warning">{f.label}</Badge>
                                  </span>
                              )),
                    )}
                    {shift.close_reason && row('Alasan tutup paksa', shift.close_reason)}
                </dl>
                {can('shifts.force_close') && shift.status.value === 'OPEN' && (
                    <div className="mt-6 border-t border-slate-100 pt-4">
                        <Button variant="danger" onClick={forceClose}>
                            Tutup paksa shift
                        </Button>
                    </div>
                )}
            </Card>
        </AdminLayout>
    );
}
