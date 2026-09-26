import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';

/** Small set of shared form and display primitives for the control center. */

export function Label({ htmlFor, children }: { htmlFor: string; children: ReactNode }) {
    return (
        <label htmlFor={htmlFor} className="mb-1 block text-sm font-medium text-slate-700">
            {children}
        </label>
    );
}

export function InputError({ message }: { message?: string | undefined }) {
    return message ? <p className="mt-1 text-sm text-red-600">{message}</p> : null;
}

export function TextInput({ className = '', ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            className={`block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-sky-600 focus:outline-none focus:ring-1 focus:ring-sky-600 disabled:bg-slate-100 ${className}`}
        />
    );
}

export function Select({ className = '', children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select
            {...props}
            className={`block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-sky-600 focus:outline-none focus:ring-1 focus:ring-sky-600 ${className}`}
        >
            {children}
        </select>
    );
}

type ButtonVariant = 'primary' | 'secondary' | 'danger';

const buttonStyles: Record<ButtonVariant, string> = {
    primary: 'bg-sky-700 text-white hover:bg-sky-800',
    secondary: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
    danger: 'bg-red-700 text-white hover:bg-red-800',
};

export function Button({
    variant = 'primary',
    className = '',
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant }) {
    return (
        <button
            {...props}
            className={`inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium shadow-sm transition disabled:cursor-not-allowed disabled:opacity-60 ${buttonStyles[variant]} ${className}`}
        />
    );
}

type BadgeTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

const badgeStyles: Record<BadgeTone, string> = {
    neutral: 'bg-slate-100 text-slate-700',
    success: 'bg-emerald-100 text-emerald-800',
    warning: 'bg-amber-100 text-amber-800',
    danger: 'bg-red-100 text-red-800',
    info: 'bg-sky-100 text-sky-800',
};

export function Badge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
    return <span className={`inline-flex rounded px-2 py-0.5 text-xs font-medium ${badgeStyles[tone]}`}>{children}</span>;
}

export function statusTone(status: string): BadgeTone {
    switch (status) {
        case 'ACTIVE':
        case 'APPROVED':
        case 'CURRENT':
            return 'success';
        case 'PENDING_APPROVAL':
        case 'DRAFT':
        case 'SCHEDULED':
            return 'info';
        case 'SUSPENDED':
        case 'REVOKED':
        case 'LOST':
        case 'REJECTED':
            return 'danger';
        case 'FINISHED':
        case 'CANCELLED':
            return 'neutral';
        default:
            return 'warning';
    }
}

export function Card({ title, description, children }: { title?: string; description?: string; children: ReactNode }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            {title && <h2 className="text-base font-semibold text-slate-900">{title}</h2>}
            {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
            <div className={title || description ? 'mt-4' : ''}>{children}</div>
        </section>
    );
}

export function PageHeader({ title, actions }: { title: string; actions?: ReactNode }) {
    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-2xl font-semibold text-slate-900">{title}</h1>
            {actions}
        </div>
    );
}

/** Label + control + error + optional hint. */
export function Field({
    label,
    htmlFor,
    error,
    hint,
    className = '',
    children,
}: {
    label: string;
    htmlFor: string;
    error?: string | undefined;
    hint?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={className}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

export function Table({ head, children, empty }: { head: string[]; children: ReactNode; empty?: boolean }) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr className="text-left text-slate-500">
                        {head.map((h, i) => (
                            <th key={i} className="py-2 pr-4 font-medium">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {children}
                    {empty && (
                        <tr>
                            <td colSpan={head.length} className="py-6 text-center text-slate-500">
                                Tidak ada data.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

export function LinkButton({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link href={href} className="rounded-md bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800">
            {children}
        </Link>
    );
}

/** Errors not tied to a visible form field (rule violations from table actions). */
export function GeneralError({
    errors,
    keys = ['general', 'device', 'tariff'],
}: {
    errors: Record<string, string | undefined>;
    keys?: string[];
}) {
    const messages = keys.map((k) => errors[k]).filter(Boolean);
    return messages.length ? (
        <div role="alert" className="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {messages.join(' ')}
        </div>
    ) : null;
}
