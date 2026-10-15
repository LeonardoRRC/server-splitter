/** "1.5 GiB" from MiB, as the Panel shows limits. */
export function formatMiB(mib: number): string {
    if (mib <= 0) return 'Unlimited';
    return mib >= 1024 ? `${Number((mib / 1024).toFixed(2))} GiB` : `${mib} MiB`;
}

export function formatCpu(cpu: number): string {
    return cpu <= 0 ? 'Unlimited' : `${cpu}%`;
}

/** The values the type form starts with: the server's own when it uses the egg, else the egg's defaults. */
export function initialEnvironment(variables: { env: string; default: string }[], current?: Record<string, string | null>): Record<string, string> {
    return Object.fromEntries(variables.map((variable) => [variable.env, current?.[variable.env] ?? variable.default ?? '']));
}

/** Eggs grouped by category, categories and eggs in name order. */
export function groupByCategory<T extends { category: string; name: string }>(eggs: T[]): { category: string; eggs: T[] }[] {
    const groups = new Map<string, T[]>();
    for (const egg of eggs) {
        groups.set(egg.category, [...(groups.get(egg.category) ?? []), egg]);
    }
    return [...groups.entries()]
        .sort(([a], [b]) => (a === 'Other' ? 1 : b === 'Other' ? -1 : a.localeCompare(b)))
        .map(([category, list]) => ({ category, eggs: [...list].sort((a, b) => a.name.localeCompare(b.name)) }));
}
