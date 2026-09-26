const dateTime = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
});

/** Server timestamps are UTC ISO-8601; the UI shows WIB (Asia/Jakarta). */
export function formatDateTime(iso: string | null | undefined): string {
    return iso ? `${dateTime.format(new Date(iso))} WIB` : '—';
}

const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

/** Amounts are integer rupiah from the server. */
export function formatRupiah(amount: number): string {
    return rupiah.format(amount);
}

const date = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeZone: 'UTC' });

/** Calendar dates (Y-m-d) are already WIB days; format without shifting. */
export function formatDate(ymd: string | null | undefined): string {
    return ymd ? date.format(new Date(`${ymd}T00:00:00Z`)) : '—';
}
