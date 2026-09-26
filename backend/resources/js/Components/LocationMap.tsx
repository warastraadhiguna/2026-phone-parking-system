import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { useEffect, useRef } from 'react';
import { formatRupiah } from '@/lib/format';

export interface MapLocation {
    id: number;
    code: string;
    name: string;
    status: string;
    latitude: number | null;
    longitude: number | null;
    transactions: number;
    revenue: number;
    staffed: boolean;
}

/**
 * Monitoring map (master doc §31): parking locations with status and today's figures.
 * No vehicle tracking. Tiles come from configuration; without a tile URL only the points are drawn.
 */
export function LocationMap({ locations, tileUrl, attribution }: { locations: MapLocation[]; tileUrl: string | null; attribution: string | null }) {
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!container.current) return;
        const points = locations.filter((l) => l.latitude !== null && l.longitude !== null);
        const map = L.map(container.current, { scrollWheelZoom: false });
        if (tileUrl) L.tileLayer(tileUrl, { maxZoom: 19, attribution: attribution ?? '' }).addTo(map);

        const bounds: L.LatLngTuple[] = [];
        for (const l of points) {
            const at: L.LatLngTuple = [l.latitude as number, l.longitude as number];
            bounds.push(at);
            const color = l.status !== 'ACTIVE' ? '#94a3b8' : l.staffed ? '#059669' : '#d97706';
            L.circleMarker(at, { radius: 6 + Math.min(10, Math.sqrt(l.transactions)), color, fillColor: color, fillOpacity: 0.6, weight: 2 })
                .bindPopup(`<strong>${escapeHtml(l.code)}</strong><br>${escapeHtml(l.name)}<br>${l.transactions} transaksi · ${formatRupiah(l.revenue)}<br>${l.status === 'ACTIVE' ? (l.staffed ? 'Ada jukir bertugas' : 'Tidak ada shift terbuka') : 'Tidak aktif'}`)
                .addTo(map);
        }
        if (bounds.length > 0) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
        else map.setView([-6.7551, 111.038], 12);

        return () => {
            map.remove();
        };
    }, [locations, tileUrl, attribution]);

    return (
        <div>
            <div ref={container} className="h-80 w-full rounded-md border border-slate-200" />
            <p className="mt-2 text-xs text-slate-500">
                <span className="mr-3 text-emerald-700">● ada jukir bertugas</span>
                <span className="mr-3 text-amber-600">● aktif tanpa shift</span>
                <span className="text-slate-400">● tidak aktif</span> — ukuran titik: jumlah transaksi hari ini.
            </p>
        </div>
    );
}

function escapeHtml(s: string): string {
    return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string);
}
