import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { EnvironmentBadge } from '@/Components/EnvironmentBadge';
import { Button, InputError, Label, TextInput } from '@/Components/ui';
import { useSharedProps } from '@/hooks/useSharedProps';

export default function Login() {
    const { app } = useSharedProps();
    const form = useForm({ username: '', password: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
            <Head title="Masuk" />
            <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
                <div className="mb-6 text-center">
                    <h1 className="text-lg font-semibold text-slate-900">Sistem Perparkiran Kabupaten Pati</h1>
                    <p className="mt-1 text-sm text-slate-500">Control Center</p>
                    <div className="mt-2">
                        <EnvironmentBadge environment={app.environment} />
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-4" noValidate>
                    <div>
                        <Label htmlFor="username">Username</Label>
                        <TextInput
                            id="username"
                            name="username"
                            autoComplete="username"
                            autoFocus
                            value={form.data.username}
                            onChange={(e) => form.setData('username', e.target.value)}
                        />
                        <InputError message={form.errors.username} />
                    </div>
                    <div>
                        <Label htmlFor="password">Kata sandi</Label>
                        <TextInput
                            id="password"
                            name="password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                        <InputError message={form.errors.password} />
                    </div>
                    <Button type="submit" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Memproses…' : 'Masuk'}
                    </Button>
                </form>
            </div>
        </div>
    );
}
