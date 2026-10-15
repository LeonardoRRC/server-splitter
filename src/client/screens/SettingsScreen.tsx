import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Checkbox, Input, Label, NumberInput, PageContentBlock, Select, ServerError, Spinner, Switch, TitledGreyBox, toast } from '@pterodactyl/sdk';
import { adminKey, getAdminConfig, saveAdminConfig, type AdminConfig, type AdminPayload } from '../api';
import { errorMessage, fieldErrors } from '../errors';
import { groupByCategory } from '../format';

const plural = (count: number, noun: string) => `${count} ${noun}${count === 1 ? '' : 's'}`;

function NumberField({ id, label, help, value, min, max, step = 1, error, onChange }: {
    id: string;
    label: string;
    help: string;
    value: number;
    min: number;
    max: number;
    step?: number;
    error?: string;
    onChange: (value: number) => void;
}) {
    return (
        <div>
            <Label htmlFor={id}>{label}</Label>
            <NumberInput id={id} value={value} min={min} max={max} step={step} onValueChange={(next) => onChange(next ?? min)} $hasError={Boolean(error)} />
            <p className={`ss:mt-1 ss:text-xs ${error ? 'ss:text-destructive' : 'ss:text-muted-foreground'}`}>{error ?? help}</p>
        </div>
    );
}

function Editor({ payload }: { payload: AdminPayload }) {
    const client = useQueryClient();
    const [draft, setDraft] = useState<AdminConfig>(payload.config);
    const [errors, setErrors] = useState<Record<string, string>>({});
    useEffect(() => setDraft(payload.config), [payload.config]);

    const set = (patch: Partial<AdminConfig>) => setDraft((current) => ({ ...current, ...patch }));
    const groups = useMemo(() => groupByCategory(payload.eggs), [payload.eggs]);
    const mcjarsEgg = payload.eggs.find((egg) => egg.id === payload.mcjars_egg) ?? null;
    const dirty = JSON.stringify(draft) !== JSON.stringify(payload.config);

    const save = useMutation({
        mutationFn: () => saveAdminConfig(draft),
        onSuccess: (next) => {
            client.setQueryData(adminKey, next);
            setErrors({});
            toast.success('Server Splitter settings saved.');
        },
        onError: (error) => {
            setErrors(fieldErrors(error));
            toast.error(errorMessage(error));
        },
    });

    const toggleEgg = (id: number, on: boolean) =>
        set({ allowed_eggs: on ? [...new Set([...draft.allowed_eggs, id])] : draft.allowed_eggs.filter((egg) => egg !== id) });
    const toggleGroup = (ids: number[], on: boolean) =>
        set({ allowed_eggs: on ? [...new Set([...draft.allowed_eggs, ...ids])] : draft.allowed_eggs.filter((egg) => !ids.includes(egg)) });

    return (
        <div className={'ss:flex ss:flex-col ss:gap-6'}>
            <div className={'ss:flex ss:flex-wrap ss:items-center ss:justify-between ss:gap-4'}>
                <p className={'ss:text-sm ss:text-muted-foreground'}>
                    {plural(payload.stats.sub_servers, 'sub-server')} split from {plural(payload.stats.main_servers, 'main server')}. Users need the
                    “Server Splitter” subuser permissions, which owners have.
                </p>
                <div className={'ss:flex ss:gap-2'}>
                    <Button.Text onClick={() => setDraft(payload.config)} disabled={!dirty || save.isPending}>Discard</Button.Text>
                    <Button onClick={() => save.mutate()} disabled={!dirty} isLoading={save.isPending}>Save</Button>
                </div>
            </div>

            <div className={'ss:grid ss:items-start ss:gap-6 ss:lg:grid-cols-2'}>
                <TitledGreyBox title={'Limits'}>
                    <div className={'ss:grid ss:gap-4 ss:sm:grid-cols-2'}>
                        <NumberField id={'ss-max'} label={'Sub-servers per server'} help={'The most sub-servers one main server can have.'} value={draft.max_splits} min={1} max={50} error={errors.max_splits} onChange={(max_splits) => set({ max_splits })} />
                        <div />
                        <NumberField id={'ss-min-memory'} label={'Minimum memory (MiB)'} help={'For each sub-server, and what the main server keeps.'} value={draft.min_memory} min={64} max={1048576} step={128} error={errors.min_memory} onChange={(min_memory) => set({ min_memory })} />
                        <NumberField id={'ss-min-disk'} label={'Minimum disk (MiB)'} help={'For each sub-server, and what the main server keeps.'} value={draft.min_disk} min={64} max={10485760} step={512} error={errors.min_disk} onChange={(min_disk) => set({ min_disk })} />
                    </div>
                    <div className={'ss:mt-5 ss:flex ss:flex-col ss:gap-4'}>
                        <Switch
                            checked={draft.split_cpu}
                            onChange={(split_cpu) => set({ split_cpu })}
                            label={'Split the CPU limit'}
                            description={'Sub-servers take part of the main server\'s CPU limit. When off, they get the same limit as the main server. Servers with unlimited CPU are never split.'}
                        />
                        {draft.split_cpu && (
                            <div className={'ss:sm:w-1/2'}>
                                <NumberField id={'ss-min-cpu'} label={'Minimum CPU (%)'} help={'For each sub-server, and what the main server keeps.'} value={draft.min_cpu} min={1} max={10000} step={5} error={errors.min_cpu} onChange={(min_cpu) => set({ min_cpu })} />
                            </div>
                        )}
                    </div>
                </TitledGreyBox>

                <TitledGreyBox title={'Every sub-server gets'}>
                    <div className={'ss:grid ss:gap-4 ss:sm:grid-cols-3'}>
                        <NumberField id={'ss-databases'} label={'Databases'} help={'Database limit.'} value={draft.database_limit} min={0} max={100} error={errors.database_limit} onChange={(database_limit) => set({ database_limit })} />
                        <NumberField id={'ss-backups'} label={'Backups'} help={'Backup limit.'} value={draft.backup_limit} min={0} max={100} error={errors.backup_limit} onChange={(backup_limit) => set({ backup_limit })} />
                        <NumberField id={'ss-allocations'} label={'Extra ports'} help={'Ports users can add.'} value={draft.allocation_limit} min={0} max={100} error={errors.allocation_limit} onChange={(allocation_limit) => set({ allocation_limit })} />
                    </div>
                    <p className={'ss:mt-4 ss:text-xs ss:text-muted-foreground'}>These limits are not taken from the main server.</p>
                </TitledGreyBox>

                <TitledGreyBox title={'Ports'}>
                    <p className={'ss:mb-4 ss:text-sm ss:text-muted-foreground'}>
                        Every sub-server gets a random free port on its main server's IP, from the node's unassigned allocations. When
                        there are none, it gets an unused port from this range, which is added to the node.
                    </p>
                    <Label htmlFor={'ss-range'}>Port range</Label>
                    <Input id={'ss-range'} value={draft.port_range} placeholder={'25566-25600'} onChange={(event) => set({ port_range: event.currentTarget.value })} $hasError={Boolean(errors.port_range)} />
                    <p className={`ss:mt-1 ss:text-xs ${errors.port_range ? 'ss:text-destructive' : 'ss:text-muted-foreground'}`}>
                        {errors.port_range ?? 'Leave empty to use only the node\'s free allocations.'}
                    </p>
                </TitledGreyBox>

                <TitledGreyBox title={'Behavior'}>
                    <div className={'ss:flex ss:flex-col ss:gap-4'}>
                        <div>
                            <Label htmlFor={'ss-parent-delete'}>When a main server is deleted</Label>
                            <Select
                                id={'ss-parent-delete'}
                                value={draft.on_parent_delete}
                                options={[
                                    { value: 'delete', label: 'Delete its sub-servers' },
                                    { value: 'detach', label: 'Keep its sub-servers as ordinary servers' },
                                ]}
                                onChange={(value) => set({ on_parent_delete: value as AdminConfig['on_parent_delete'] })}
                            />
                        </div>
                        <Switch checked={draft.mirror_suspension} onChange={(mirror_suspension) => set({ mirror_suspension })} label={'Suspend sub-servers with their main server'} description={'Suspending or unsuspending a main server does the same to its sub-servers.'} />
                        <Switch checked={draft.allow_subuser_sync} onChange={(allow_subuser_sync) => set({ allow_subuser_sync })} label={'Let users copy subusers'} description={'Offers to give a new sub-server the main server\'s subusers and their permissions.'} />
                        <Switch checked={draft.allow_type_change} onChange={(allow_type_change) => set({ allow_type_change })} label={'Let users change server types'} description={'Lets users move their main server and sub-servers to another allowed egg, version, or build.'} />
                        <div>
                            <Label htmlFor={'ss-version-egg'}>Minecraft software lists</Label>
                            <Select
                                id={'ss-version-egg'}
                                value={draft.version_egg}
                                options={[
                                    {
                                        value: 'auto',
                                        label: mcjarsEgg ? `Automatic: ${mcjarsEgg.name}` : 'Automatic: the MCJars egg (not installed)',
                                    },
                                    { value: 'off', label: 'Off' },
                                    ...payload.eggs.map((egg) => ({ value: egg.id, label: egg.name })),
                                ]}
                                onChange={(value) => set({ version_egg: value === 'auto' || value === 'off' ? value : Number(value) })}
                                hasError={Boolean(errors.version_egg)}
                            />
                            <p className={`ss:mt-1 ss:text-xs ${errors.version_egg ? 'ss:text-destructive' : 'ss:text-muted-foreground'}`}>
                                {errors.version_egg ??
                                    "For the official MCJars egg (by rjansengd@gmail.com): users choose the software, version, and build from MCJars' lists. Other eggs always use their defaults."}
                            </p>
                        </div>
                    </div>
                </TitledGreyBox>
            </div>

            <TitledGreyBox title={'Server types'}>
                <div className={'ss:flex ss:flex-col ss:gap-4'}>
                    <div className={'ss:sm:w-1/2'}>
                        <Label htmlFor={'ss-eggs'}>Users can create</Label>
                        <Select
                            id={'ss-eggs'}
                            value={draft.eggs}
                            options={[
                                { value: 'all', label: 'Every egg' },
                                { value: 'selected', label: 'Only the eggs selected below' },
                            ]}
                            onChange={(value) => set({ eggs: value as AdminConfig['eggs'] })}
                        />
                    </div>
                    {draft.eggs === 'selected' && (
                        <div className={'ss:grid ss:gap-4 ss:md:grid-cols-2 ss:xl:grid-cols-3'}>
                            {groups.map((group) => {
                                const ids = group.eggs.map((egg) => egg.id);
                                const chosen = ids.filter((id) => draft.allowed_eggs.includes(id)).length;
                                return (
                                    <div key={group.category} className={'ss:rounded-md ss:border ss:border-border ss:bg-card ss:p-4'}>
                                        <label className={'ss:flex ss:cursor-pointer ss:items-center ss:gap-2.5 ss:text-sm ss:font-semibold'}>
                                            <Checkbox checked={chosen === ids.length} indeterminate={chosen > 0 && chosen < ids.length} onChange={(on) => toggleGroup(ids, on)} />
                                            <span>{group.category}</span>
                                        </label>
                                        <div className={'ss:mt-2 ss:flex ss:flex-col ss:gap-2 ss:pl-6'}>
                                            {group.eggs.map((egg) => (
                                                <label key={egg.id} className={'ss:flex ss:cursor-pointer ss:items-center ss:gap-2.5 ss:text-sm'}>
                                                    <Checkbox checked={draft.allowed_eggs.includes(egg.id)} onChange={(on) => toggleEgg(egg.id, on)} />
                                                    <span>{egg.name}</span>
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    {draft.eggs === 'selected' && draft.allowed_eggs.length === 0 && (
                        <p className={'ss:text-sm ss:text-destructive'}>No eggs are selected, so users cannot create sub-servers.</p>
                    )}
                </div>
            </TitledGreyBox>
        </div>
    );
}

export default function SettingsScreen() {
    const payload = useQuery({ queryKey: adminKey, queryFn: getAdminConfig });

    return (
        <PageContentBlock title={'Server Splitter'}>
            <div className={'ss:mb-6'}>
                <h1 className={'ss:text-2xl ss:font-semibold'}>Server Splitter</h1>
                <p className={'ss:text-sm ss:text-muted-foreground'}>Let users split their servers into sub-servers.</p>
            </div>
            {payload.isLoading ? (
                <Spinner centered size={'large'} />
            ) : payload.isError || !payload.data ? (
                <ServerError message={errorMessage(payload.error)} onRetry={() => payload.refetch()} />
            ) : (
                <Editor payload={payload.data} />
            )}
        </PageContentBlock>
    );
}
