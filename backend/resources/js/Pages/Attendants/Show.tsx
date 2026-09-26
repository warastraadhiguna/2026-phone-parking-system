import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Badge, Button, Card, Field, GeneralError, PageHeader, Select, Table, TextInput, statusTone } from '@/Components/ui';
import { usePermissions } from '@/hooks/usePermissions';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import type { Option } from '@/types';

interface Props {
    attendant: {
        id: number;
        attendant_code: string;
        username: string;
        user_id: number;
        name: string;
        identity_number: string;
        phone: string;
        status: Option;
        registered_at: string;
        expired_at: string | null;
        has_photo: boolean;
        cash_balance: number;
    };
    assignments: { id: number; location_code: string; location_name: string; effective_from: string; effective_until: string | null; phase: Option; cancel_reason: string | null }[];
    devices: { id: number; device_uuid: string; device_model: string | null; app_version: string | null; status: Option; registered_at: string; last_seen_at: string | null; deactivation_reason: string | null }[];
    locations: Option[];
    statuses: Option[];
    today: string;
}

export default function AttendantsShow({ attendant, assignments, devices, locations, statuses, today }: Props) {
    const { can } = usePermissions();
    const canManage = can('attendants.manage');
    const pageErrors = usePage().props.errors as Record<string, string | undefined>;

    const profile = useForm({
        name: attendant.name,
        identity_number: attendant.identity_number,
        phone: attendant.phone,
        registered_at: attendant.registered_at,
        expired_at: attendant.expired_at ?? '',
    });
    const status = useForm({ status: attendant.status.value, reason: '' });
    const assign = useForm({ location_id: '', effective_from: today, effective_until: '' });
    const photo = useForm<{ photo: File | null }>({ photo: null });
    const [photoVersion, setPhotoVersion] = useState(0);

    const submit = (form: { put: (url: string, o?: object) => void }, url: string) => (e: FormEvent) => {
        e.preventDefault();
        form.put(url, { preserveScroll: true });
    };

    const endAssignment = (id: number) => {
        const until = window.prompt('Tanggal terakhir bertugas (YYYY-MM-DD):', today);
        if (until) router.put(`/assignments/${id}/end`, { effective_until: until }, { preserveScroll: true });
    };
    const cancelAssignment = (id: number) => {
        const reason = window.prompt('Alasan pembatalan penugasan:');
        if (reason) router.put(`/assignments/${id}/cancel`, { reason }, { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title={attendant.attendant_code} />
            <PageHeader
                title={`${attendant.attendant_code} — ${attendant.name}`}
                actions={
                    <Link href="/attendants" className="text-sm text-sky-700 hover:underline">
                        ← Daftar juru parkir
                    </Link>
                }
            />
            <GeneralError errors={pageErrors} keys={['general', 'effective_until', 'reason']} />

            <div className="mb-6 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-slate-600">
                {attendant.has_photo && (
                    <img src={`/attendants/${attendant.id}/photo?v=${photoVersion}`} alt={`Foto ${attendant.name}`} className="h-20 w-20 rounded object-cover" />
                )}
                <span>
                    Status: <Badge tone={statusTone(attendant.status.value)}>{attendant.status.label}</Badge>
                </span>
                <span>
                    Username aplikasi: <span className="font-mono text-slate-900">{attendant.username}</span>
                </span>
                <span>NIK: <span className="font-mono">{attendant.identity_number}</span></span>
                <span>
                    Kas dipegang: <strong className="text-slate-900">{formatRupiah(attendant.cash_balance)}</strong>
                </span>
                <span>
                    Berlaku: {formatDate(attendant.registered_at)} – {attendant.expired_at ? formatDate(attendant.expired_at) : 'tanpa batas'}
                </span>
                {can('users.manage') && (
                    <Link href={`/users/${attendant.user_id}/edit`} className="text-sky-700 hover:underline">
                        Atur ulang kata sandi →
                    </Link>
                )}
            </div>

            <div className="grid gap-6">
                <Card title="Penugasan lokasi">
                    <Table head={['Lokasi', 'Mulai', 'Selesai', 'Status', '']} empty={assignments.length === 0}>
                        {assignments.map((a) => (
                            <tr key={a.id}>
                                <td className="py-2 pr-4">
                                    <span className="font-mono">{a.location_code}</span> {a.location_name}
                                </td>
                                <td className="py-2 pr-4">{formatDate(a.effective_from)}</td>
                                <td className="py-2 pr-4">{a.effective_until ? formatDate(a.effective_until) : 'seterusnya'}</td>
                                <td className="py-2 pr-4">
                                    <Badge tone={statusTone(a.phase.value)}>{a.phase.label}</Badge>
                                    {a.cancel_reason && <div className="text-xs text-slate-500">{a.cancel_reason}</div>}
                                </td>
                                <td className="py-2 text-right">
                                    {can('assignments.manage') && (a.phase.value === 'CURRENT' || a.phase.value === 'SCHEDULED') && (
                                        <button type="button" className="mr-3 text-sky-700 hover:underline" onClick={() => endAssignment(a.id)}>
                                            Atur tanggal selesai
                                        </button>
                                    )}
                                    {can('assignments.manage') && a.phase.value === 'SCHEDULED' && (
                                        <button type="button" className="text-red-700 hover:underline" onClick={() => cancelAssignment(a.id)}>
                                            Batalkan
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>

                    {can('assignments.manage') && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                assign.post(`/attendants/${attendant.id}/assignments`, { preserveScroll: true, onSuccess: () => assign.reset() });
                            }}
                            className="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-4"
                            noValidate
                        >
                            <Field label="Lokasi" htmlFor="location_id" error={assign.errors.location_id} className="sm:col-span-2">
                                <Select id="location_id" value={assign.data.location_id} onChange={(e) => assign.setData('location_id', e.target.value)}>
                                    <option value="">Pilih lokasi aktif…</option>
                                    {locations.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Mulai" htmlFor="effective_from" error={assign.errors.effective_from}>
                                <TextInput id="effective_from" type="date" min={today} value={assign.data.effective_from} onChange={(e) => assign.setData('effective_from', e.target.value)} />
                            </Field>
                            <Field label="Selesai (opsional)" htmlFor="effective_until" error={assign.errors.effective_until}>
                                <TextInput id="effective_until" type="date" min={assign.data.effective_from} value={assign.data.effective_until} onChange={(e) => assign.setData('effective_until', e.target.value)} />
                            </Field>
                            <div className="sm:col-span-4">
                                <Button type="submit" disabled={assign.processing}>
                                    Tugaskan
                                </Button>
                            </div>
                        </form>
                    )}
                </Card>

                <Card title="Perangkat" description="Perangkat baru terdaftar otomatis saat juru parkir login pertama kali, lalu menunggu persetujuan di menu Perangkat.">
                    <Table head={['UUID', 'Model', 'Versi aplikasi', 'Terdaftar', 'Terakhir aktif', 'Status']} empty={devices.length === 0}>
                        {devices.map((d) => (
                            <tr key={d.id}>
                                <td className="py-2 pr-4 font-mono text-xs">{d.device_uuid}</td>
                                <td className="py-2 pr-4">{d.device_model ?? '—'}</td>
                                <td className="py-2 pr-4">{d.app_version ?? '—'}</td>
                                <td className="py-2 pr-4">{formatDateTime(d.registered_at)}</td>
                                <td className="py-2 pr-4">{formatDateTime(d.last_seen_at)}</td>
                                <td className="py-2 pr-4">
                                    <Badge tone={statusTone(d.status.value)}>{d.status.label}</Badge>
                                    {d.deactivation_reason && <div className="text-xs text-slate-500">{d.deactivation_reason}</div>}
                                </td>
                            </tr>
                        ))}
                    </Table>
                    {can('devices.view') && (
                        <Link href={`/devices?status=&q=${attendant.attendant_code}`} className="mt-3 inline-block text-sm text-sky-700 hover:underline">
                            Kelola perangkat →
                        </Link>
                    )}
                </Card>

                {canManage && (
                    <>
                        <Card title="Data juru parkir">
                            <form onSubmit={submit(profile, `/attendants/${attendant.id}`)} className="grid max-w-2xl gap-4" noValidate>
                                <Field label="Nama lengkap" htmlFor="name" error={profile.errors.name}>
                                    <TextInput id="name" value={profile.data.name} onChange={(e) => profile.setData('name', e.target.value)} />
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="NIK" htmlFor="identity_number" error={profile.errors.identity_number}>
                                        <TextInput id="identity_number" inputMode="numeric" autoComplete="off" value={profile.data.identity_number} onChange={(e) => profile.setData('identity_number', e.target.value)} />
                                    </Field>
                                    <Field label="Nomor telepon" htmlFor="phone" error={profile.errors.phone}>
                                        <TextInput id="phone" value={profile.data.phone} onChange={(e) => profile.setData('phone', e.target.value)} />
                                    </Field>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Tanggal registrasi" htmlFor="registered_at" error={profile.errors.registered_at}>
                                        <TextInput id="registered_at" type="date" value={profile.data.registered_at} onChange={(e) => profile.setData('registered_at', e.target.value)} />
                                    </Field>
                                    <Field label="Berlaku sampai" htmlFor="expired_at" error={profile.errors.expired_at}>
                                        <TextInput id="expired_at" type="date" value={profile.data.expired_at} onChange={(e) => profile.setData('expired_at', e.target.value)} />
                                    </Field>
                                </div>
                                <div>
                                    <Button type="submit" disabled={profile.processing}>
                                        Simpan perubahan
                                    </Button>
                                </div>
                            </form>
                        </Card>

                        <Card title="Foto">
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    photo.post(`/attendants/${attendant.id}/photo`, {
                                        preserveScroll: true,
                                        forceFormData: true,
                                        onSuccess: () => {
                                            photo.reset();
                                            setPhotoVersion((v) => v + 1);
                                        },
                                    });
                                }}
                                className="flex flex-wrap items-end gap-3"
                            >
                                <Field label="File foto (JPG/PNG, maks. 2 MB)" htmlFor="photo" error={photo.errors.photo}>
                                    <input id="photo" type="file" accept="image/jpeg,image/png" onChange={(e) => photo.setData('photo', e.target.files?.[0] ?? null)} className="text-sm" />
                                </Field>
                                <Button type="submit" variant="secondary" disabled={photo.processing || !photo.data.photo}>
                                    Unggah
                                </Button>
                            </form>
                        </Card>

                        <Card title="Status juru parkir" description="Status nonaktif, ditangguhkan, atau kedaluwarsa menonaktifkan akun aplikasi dan mengakhiri sesinya.">
                            <form onSubmit={submit(status, `/attendants/${attendant.id}/status`)} className="grid max-w-2xl gap-4 sm:grid-cols-3" noValidate>
                                <Field label="Status" htmlFor="status" error={status.errors.status}>
                                    <Select id="status" value={status.data.status} onChange={(e) => status.setData('status', e.target.value)}>
                                        {statuses.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="Alasan (wajib, dicatat di audit)" htmlFor="reason" error={status.errors.reason} className="sm:col-span-2">
                                    <TextInput id="reason" value={status.data.reason} onChange={(e) => status.setData('reason', e.target.value)} />
                                </Field>
                                <div>
                                    <Button type="submit" variant="secondary" disabled={status.processing}>
                                        Ubah status
                                    </Button>
                                </div>
                            </form>
                        </Card>
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
