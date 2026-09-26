import type { Option } from '@/types';

export function RoleCheckboxes({
    options,
    value,
    onChange,
}: {
    options: Option[];
    value: string[];
    onChange: (roles: string[]) => void;
}) {
    const toggle = (role: string, checked: boolean) =>
        onChange(checked ? [...value, role] : value.filter((r) => r !== role));

    return (
        <fieldset className="grid gap-2 sm:grid-cols-2">
            <legend className="sr-only">Peran</legend>
            {options.map((option) => (
                <label key={option.value} className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        className="rounded border-slate-300 text-sky-700 focus:ring-sky-600"
                        checked={value.includes(option.value)}
                        onChange={(e) => toggle(option.value, e.target.checked)}
                    />
                    {option.label}
                </label>
            ))}
        </fieldset>
    );
}
