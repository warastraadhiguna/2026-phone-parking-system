import { Field, Select, TextInput } from '@/Components/ui';
import type { Option } from '@/types';

export interface LocationForm {
    name: string;
    address: string;
    latitude: string;
    longitude: string;
    geofence_radius_m: string;
    location_type: string;
    motorcycle_capacity: string;
    car_capacity: string;
}

/** Shared by Create and Show (edit). The location code is handled by the page. */
interface Props {
    data: LocationForm;
    errors: Partial<Record<keyof LocationForm, string>>;
    set: (key: keyof LocationForm, value: string) => void;
    locationTypes: Option[];
}

export function LocationFields({ data, errors, set, locationTypes }: Props) {
    const form = { errors };
    const bind = (key: keyof LocationForm) => ({
        id: key,
        value: data[key],
        onChange: (e: { target: { value: string } }) => set(key, e.target.value),
    });

    return (
        <>
            <Field label="Nama lokasi" htmlFor="name" error={form.errors.name}>
                <TextInput {...bind('name')} />
            </Field>
            <Field label="Alamat" htmlFor="address" error={form.errors.address}>
                <TextInput {...bind('address')} />
            </Field>
            <Field label="Jenis lokasi" htmlFor="location_type" error={form.errors.location_type}>
                <Select {...bind('location_type')}>
                    <option value="">Pilih…</option>
                    {locationTypes.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </Select>
            </Field>
            <div className="grid gap-4 sm:grid-cols-3">
                <Field label="Lintang (latitude)" htmlFor="latitude" error={form.errors.latitude} hint="Contoh: -6.7550000">
                    <TextInput {...bind('latitude')} inputMode="decimal" />
                </Field>
                <Field label="Bujur (longitude)" htmlFor="longitude" error={form.errors.longitude} hint="Contoh: 111.0380000">
                    <TextInput {...bind('longitude')} inputMode="decimal" />
                </Field>
                <Field label="Radius geofence (meter)" htmlFor="geofence_radius_m" error={form.errors.geofence_radius_m} hint="5–1000 m">
                    <TextInput {...bind('geofence_radius_m')} inputMode="numeric" />
                </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Kapasitas motor" htmlFor="motorcycle_capacity" error={form.errors.motorcycle_capacity}>
                    <TextInput {...bind('motorcycle_capacity')} inputMode="numeric" />
                </Field>
                <Field label="Kapasitas mobil" htmlFor="car_capacity" error={form.errors.car_capacity}>
                    <TextInput {...bind('car_capacity')} inputMode="numeric" />
                </Field>
            </div>
        </>
    );
}
