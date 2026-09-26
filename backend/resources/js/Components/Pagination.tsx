import { Link } from '@inertiajs/react';
import type { Paginated } from '@/types';

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav className="mt-4 flex flex-wrap items-center gap-1 text-sm" aria-label="Halaman">
            {page.links.map((link, index) => {
                // Laravel labels the first/last links "&laquo; Previous" / "Next &raquo;".
                const label = index === 0 ? '‹' : index === page.links.length - 1 ? '›' : link.label;

                return link.url ? (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        className={`rounded px-3 py-1 ${link.active ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-slate-100'}`}
                    >
                        {label}
                    </Link>
                ) : (
                    <span key={index} className="px-3 py-1 text-slate-400">
                        {label}
                    </span>
                );
            })}
        </nav>
    );
}
