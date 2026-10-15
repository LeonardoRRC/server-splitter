import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { GitFork } from 'lucide-react';
import { Alert, Button, Input, Label, NumberInput, Switch, TitledGreyBox, toast } from '@pterodactyl/sdk';
import { createSubServer, type Overview } from '../api';
import { errorMessage, fieldErrors } from '../errors';
import { formatCpu, formatMiB, initialEnvironment } from '../format';
import TypeFields, { type TypeValue } from './TypeFields';

interface Props {
    server: string;
    overview: Overview;
    onCreated: () => void;
}

function startingType(overview: Overview): TypeValue {
    const egg = overview.eggs.find((candidate) => candidate.id === overview.main.egg?.id && candidate.allowed)
        ?? overview.eggs.find((candidate) => candidate.allowed);
    return { eggId: egg?.id ?? null, environment: egg ? initialEnvironment(egg.variables) : {} };
}

/** The "Split server" card: name, type, and resources for a new sub-server, which gets a random free port on this server's IP. */
export default function CreateForm({ server, overview, onCreated }: Props) {
    const { limits, main } = overview;
    const [name, setName] = useState('');
    const [type, setType] = useState<TypeValue>(() => startingType(overview));
    const [memory, setMemory] = useState<number | null>(limits.min_memory);
    const [disk, setDisk] = useState<number | null>(limits.min_disk);
    const [cpu, setCpu] = useState<number | null>(limits.min_cpu);
    const [syncSubusers, setSyncSubusers] = useState(true);
    const [start, setStart] = useState(true);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const mutation = useMutation({
        mutationFn: () =>
            createSubServer(server, {
                name: name.trim(),
                egg_id: type.eggId ?? 0,
                environment: type.environment,
                memory: memory ?? 0,
                disk: disk ?? 0,
                cpu: limits.split_cpu ? cpu ?? 0 : null,
                sync_subusers: limits.allow_subuser_sync && syncSubusers,
                start,
            }),
        onSuccess: (created) => {
            toast.success(`${created.name} is being installed at ${created.ip}:${created.port}.`);
            setName('');
            setErrors({});
            onCreated();
        },
        onError: (error) => {
            setErrors(fieldErrors(error));
            toast.error(errorMessage(error));
        },
    });

    const room = {
        memory: Math.max(0, main.memory - limits.min_memory),
        disk: Math.max(0, main.disk - limits.min_disk),
        cpu: Math.max(0, main.cpu - limits.min_cpu),
    };
    const used = overview.children.length;
    const blocked = limits.blocked ?? (overview.has_free_port ? null : "There are no free ports on this server's IP. Ask an administrator to add some.");
    const error = (field: string) => errors[field] && <p className={'ss:mt-1 ss:text-xs ss:text-destructive'}>{errors[field]}</p>;

    return (
        <TitledGreyBox icon={GitFork} title={`Split server (${used} of ${limits.max_splits})`}>
            <div className={'ss:flex ss:flex-col ss:gap-4'}>
                <p className={'ss:text-sm ss:text-muted-foreground'}>
                    Move part of this server's resources into a new server, which gets a free port on {main.ip ?? 'the same IP'}. Resizing or deleting a sub-server gives them back.
                    You can give up to <strong className={'ss:text-foreground'}>{formatMiB(room.memory)}</strong> of memory and{' '}
                    <strong className={'ss:text-foreground'}>{formatMiB(room.disk)}</strong> of disk
                    {limits.split_cpu && (
                        <>
                            {' '}and <strong className={'ss:text-foreground'}>{formatCpu(room.cpu)}</strong> CPU
                        </>
                    )}
                    .
                </p>

                {blocked && <Alert type={'warning'}>{blocked}</Alert>}

                <fieldset disabled={Boolean(blocked) || mutation.isPending || !overview.can_manage} className={'ss:m-0 ss:flex ss:min-w-0 ss:flex-col ss:gap-4 ss:border-0 ss:p-0'}>
                    <div>
                        <Label htmlFor={'ss-name'}>Server name</Label>
                        <Input id={'ss-name'} value={name} maxLength={191} placeholder={'Lobby'} onChange={(event) => setName(event.currentTarget.value)} $hasError={Boolean(errors.name)} />
                        {error('name')}
                    </div>

                    <TypeFields server={server} eggs={overview.eggs} value={type} onChange={setType} idPrefix={'ss-new'} errors={errors} />

                    <div className={'ss:grid ss:gap-4 ss:sm:grid-cols-2'}>
                        <div>
                            <Label htmlFor={'ss-memory'}>Memory (MiB)</Label>
                            <NumberInput id={'ss-memory'} value={memory} min={limits.min_memory} max={room.memory} step={128} onValueChange={setMemory} $hasError={Boolean(errors.memory)} />
                            {error('memory')}
                        </div>
                        <div>
                            <Label htmlFor={'ss-disk'}>Disk (MiB)</Label>
                            <NumberInput id={'ss-disk'} value={disk} min={limits.min_disk} max={room.disk} step={512} onValueChange={setDisk} $hasError={Boolean(errors.disk)} />
                            {error('disk')}
                        </div>
                        {limits.split_cpu && (
                            <div>
                                <Label htmlFor={'ss-cpu'}>CPU (%)</Label>
                                <NumberInput id={'ss-cpu'} value={cpu} min={limits.min_cpu} max={room.cpu} step={5} onValueChange={setCpu} $hasError={Boolean(errors.cpu)} />
                                {error('cpu')}
                            </div>
                        )}
                    </div>

                    <div className={'ss:flex ss:flex-col ss:gap-4'}>
                        {limits.allow_subuser_sync && (
                            <Switch checked={syncSubusers} onChange={setSyncSubusers} label={'Copy subusers'} description={"Give this server's subusers the same access to the new server."} />
                        )}
                        <Switch checked={start} onChange={setStart} label={'Start when installed'} description={'Start the new server as soon as it finishes installing.'} />
                    </div>

                    <div className={'ss:flex ss:justify-end'}>
                        <Button onClick={() => mutation.mutate()} isLoading={mutation.isPending} disabled={!name.trim() || type.eggId === null}>
                            Split server
                        </Button>
                    </div>
                </fieldset>
            </div>
        </TitledGreyBox>
    );
}
