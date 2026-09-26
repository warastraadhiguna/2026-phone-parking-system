/** Makes non-production environments visually obvious to operators. */
export function EnvironmentBadge({ environment }: { environment: string }) {
    if (environment === 'production') {
        return null;
    }

    return (
        <span className="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-amber-800">
            {environment}
        </span>
    );
}
