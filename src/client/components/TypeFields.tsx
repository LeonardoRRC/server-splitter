import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Label, Select, type SelectGroup } from '@pterodactyl/sdk';
import { getBuilds, getTypes, getVersions, type SplitEgg } from '../api';
import { groupByCategory, initialEnvironment } from '../format';

export interface TypeValue {
    eggId: number | null;
    environment: Record<string, string>;
}

interface Props {
    server: string;
    eggs: SplitEgg[];
    value: TypeValue;
    onChange: (value: TypeValue) => void;
    idPrefix: string;
    errors?: Record<string, string>;
}

const LATEST = { value: 'latest', label: 'Latest' };

/**
 * Category and server type. The MCJars egg also gets its server software, version, and
 * build from MCJars' lists; every other setting takes the egg's default.
 */
export default function TypeFields({ server, eggs, value, onChange, idPrefix, errors = {} }: Props) {
    const groups = useMemo(() => groupByCategory(eggs.filter((egg) => egg.allowed || egg.id === value.eggId)), [eggs, value.eggId]);
    const egg = eggs.find((candidate) => candidate.id === value.eggId) ?? null;
    const category = egg?.category ?? groups[0]?.category ?? null;
    const inCategory = groups.find((group) => group.category === category)?.eggs ?? [];
    const picker = egg?.picker ?? null;

    const pickEgg = (id: number) => {
        const next = eggs.find((candidate) => candidate.id === id);
        onChange({ eggId: id, environment: next ? initialEnvironment(next.variables) : {} });
    };
    const set = (patch: Record<string, string>) => onChange({ ...value, environment: { ...value.environment, ...patch } });

    const software = picker ? value.environment[picker.software] ?? '' : '';
    const version = picker ? value.environment[picker.version] ?? 'latest' : 'latest';

    const types = useQuery({ queryKey: ['server-splitter', 'types'], queryFn: () => getTypes(server), enabled: picker !== null, staleTime: 3_600_000 });
    const versions = useQuery({
        queryKey: ['server-splitter', 'versions', software],
        queryFn: () => getVersions(server, software),
        enabled: picker !== null && software !== '',
        staleTime: 3_600_000,
    });
    const builds = useQuery({
        queryKey: ['server-splitter', 'builds', software, version],
        queryFn: () => getBuilds(server, software, version),
        enabled: picker !== null && software !== '' && version !== 'latest',
        staleTime: 3_600_000,
    });

    const softwareGroups = useMemo<SelectGroup[]>(() => {
        const byGroup = new Map<string, { value: string; label: string }[]>();
        for (const type of types.data ?? []) {
            byGroup.set(type.group, [...(byGroup.get(type.group) ?? []), { value: type.value, label: type.label }]);
        }
        return [...byGroup.entries()].map(([label, options]) => ({ label, options }));
    }, [types.data]);

    const error = (env: string) => (errors[`environment.${env}`] ? <p className={'ss:mt-1 ss:text-xs ss:text-destructive'}>{errors[`environment.${env}`]}</p> : null);

    return (
        <div className={'ss:flex ss:flex-col ss:gap-4'}>
            {groups.length > 1 && (
                <div>
                    <Label htmlFor={`${idPrefix}-category`}>Server category</Label>
                    <Select
                        id={`${idPrefix}-category`}
                        value={category}
                        options={groups.map((group) => ({ value: group.category, label: group.category }))}
                        onChange={(next) => {
                            const first = groups.find((group) => group.category === next)?.eggs[0];
                            if (first) pickEgg(first.id);
                        }}
                    />
                </div>
            )}
            <div>
                <Label htmlFor={`${idPrefix}-egg`}>Server type</Label>
                <Select
                    id={`${idPrefix}-egg`}
                    value={value.eggId}
                    placeholder={'Choose a server type'}
                    options={inCategory.map((candidate) => ({ value: candidate.id, label: candidate.name }))}
                    onChange={(next) => pickEgg(Number(next))}
                    hasError={Boolean(errors.egg_id)}
                />
                {errors.egg_id && <p className={'ss:mt-1 ss:text-xs ss:text-destructive'}>{errors.egg_id}</p>}
            </div>
            {picker && (
                <>
                    <div>
                        <Label htmlFor={`${idPrefix}-software`}>Software</Label>
                        <Select
                            id={`${idPrefix}-software`}
                            value={software}
                            disabled={types.isLoading}
                            placeholder={types.isLoading ? 'Loading software…' : 'Choose a server software'}
                            searchPlaceholder={'Search software'}
                            groups={softwareGroups}
                            onChange={(next) => set({ [picker.software]: String(next), [picker.version]: 'latest', [picker.build]: 'latest' })}
                            hasError={Boolean(errors[`environment.${picker.software}`])}
                        />
                        {error(picker.software)}
                    </div>
                    <div className={'ss:grid ss:gap-4 ss:sm:grid-cols-2'}>
                        <div>
                            <Label htmlFor={`${idPrefix}-version`}>Version</Label>
                            <Select
                                id={`${idPrefix}-version`}
                                value={version}
                                disabled={versions.isLoading}
                                placeholder={versions.isLoading ? 'Loading versions…' : 'Choose a version'}
                                searchPlaceholder={'Search versions'}
                                options={[LATEST, ...(versions.data ?? []).map((item) => ({ value: item, label: item }))]}
                                onChange={(next) => set({ [picker.version]: String(next), [picker.build]: 'latest' })}
                                hasError={Boolean(errors[`environment.${picker.version}`])}
                            />
                            {error(picker.version)}
                        </div>
                        <div>
                            <Label htmlFor={`${idPrefix}-build`}>Build</Label>
                            <Select
                                id={`${idPrefix}-build`}
                                value={value.environment[picker.build] ?? 'latest'}
                                disabled={version === 'latest' || builds.isLoading}
                                options={[LATEST, ...(builds.data ?? []).map((build) => ({ value: build.id, label: build.name }))]}
                                onChange={(next) => set({ [picker.build]: String(next) })}
                                hasError={Boolean(errors[`environment.${picker.build}`])}
                            />
                            {error(picker.build)}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
