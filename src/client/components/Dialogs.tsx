import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Alert, Button, Dialog, Label, NumberInput, Switch, toast } from '@pterodactyl/sdk';
import { changeType, resizeSubServer, type Overview, type SplitServer } from '../api';
import { errorMessage, fieldErrors } from '../errors';
import { formatCpu, formatMiB, initialEnvironment } from '../format';
import TypeFields, { type TypeValue } from './TypeFields';

interface ResizeProps {
    server: string;
    overview: Overview;
    target: SplitServer | null;
    onClose: () => void;
    onDone: () => void;
}

/** Moves memory, disk, and CPU between a sub-server and the main server. */
export function ResizeDialog({ server, overview, target, onClose, onDone }: ResizeProps) {
    const { limits, main } = overview;
    const [memory, setMemory] = useState<number | null>(null);
    const [disk, setDisk] = useState<number | null>(null);
    const [cpu, setCpu] = useState<number | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const splitsCpu = limits.split_cpu && (target?.cpu ?? 0) > 0;

    useEffect(() => {
        if (target) {
            setMemory(target.memory);
            setDisk(target.disk);
            setCpu(target.cpu);
            setErrors({});
        }
    }, [target]);

    const mutation = useMutation({
        mutationFn: () => resizeSubServer(server, target!.identifier, { memory: memory ?? 0, disk: disk ?? 0, cpu: splitsCpu ? cpu ?? 0 : null }),
        onSuccess: (updated) => {
            toast.success(`${updated.name} now has ${formatMiB(updated.memory)} of memory and ${formatMiB(updated.disk)} of disk.`);
            onDone();
            onClose();
        },
        onError: (error) => {
            setErrors(fieldErrors(error));
            toast.error(errorMessage(error));
        },
    });

    const most = target && {
        memory: target.memory + main.memory - limits.min_memory,
        disk: target.disk + main.disk - limits.min_disk,
        cpu: target.cpu + main.cpu - limits.min_cpu,
    };

    return (
        <Dialog open={target !== null} onClose={onClose} title={`Edit ${target?.name ?? 'sub-server'}`}>
            {target && most && (
                <div className={'ss:flex ss:flex-col ss:gap-4'}>
                    <Alert type={'warning'}>
                        Lowering a value gives the difference back to <strong>{main.name}</strong>; raising it takes it from there.
                        Both servers use their new limits after their next restart.
                    </Alert>
                    <p className={'ss:text-sm ss:text-muted-foreground'}>
                        Now: {formatMiB(target.memory)} memory, {formatMiB(target.disk)} disk{splitsCpu ? `, ${formatCpu(target.cpu)} CPU` : ''}.
                        Up to {formatMiB(most.memory)} memory and {formatMiB(most.disk)} disk.
                    </p>
                    <div className={'ss:grid ss:gap-4 ss:sm:grid-cols-2'}>
                        <div>
                            <Label htmlFor={'ss-resize-memory'}>Memory (MiB)</Label>
                            <NumberInput id={'ss-resize-memory'} value={memory} min={limits.min_memory} max={most.memory} step={128} onValueChange={setMemory} $hasError={Boolean(errors.memory)} />
                            {errors.memory && <p className={'ss:mt-1 ss:text-xs ss:text-destructive'}>{errors.memory}</p>}
                        </div>
                        <div>
                            <Label htmlFor={'ss-resize-disk'}>Disk (MiB)</Label>
                            <NumberInput id={'ss-resize-disk'} value={disk} min={limits.min_disk} max={most.disk} step={512} onValueChange={setDisk} $hasError={Boolean(errors.disk)} />
                            {errors.disk && <p className={'ss:mt-1 ss:text-xs ss:text-destructive'}>{errors.disk}</p>}
                        </div>
                        {splitsCpu && (
                            <div>
                                <Label htmlFor={'ss-resize-cpu'}>CPU (%)</Label>
                                <NumberInput id={'ss-resize-cpu'} value={cpu} min={limits.min_cpu} max={most.cpu} step={5} onValueChange={setCpu} $hasError={Boolean(errors.cpu)} />
                                {errors.cpu && <p className={'ss:mt-1 ss:text-xs ss:text-destructive'}>{errors.cpu}</p>}
                            </div>
                        )}
                    </div>
                </div>
            )}
            <Dialog.Footer>
                <Button.Text onClick={onClose}>Cancel</Button.Text>
                <Button onClick={() => mutation.mutate()} isLoading={mutation.isPending}>
                    Save
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
}

interface TypeProps {
    server: string;
    overview: Overview;
    /** The server to change; the main server itself is changed through its own endpoint. */
    target: SplitServer | null;
    onClose: () => void;
    onDone: () => void;
}

/** Changes a server's type, version, and build, and optionally reinstalls it. */
export function TypeDialog({ server, overview, target, onClose, onDone }: TypeProps) {
    const [type, setType] = useState<TypeValue>({ eggId: null, environment: {} });
    const [reinstall, setReinstall] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!target) return;
        const egg = overview.eggs.find((candidate) => candidate.id === target.egg?.id);
        setType({ eggId: egg?.id ?? null, environment: egg ? initialEnvironment(egg.variables, target.environment) : {} });
        setReinstall(false);
        setErrors({});
    }, [target, overview.eggs]);

    const isServerItself = target?.identifier === server;
    const mutation = useMutation({
        mutationFn: () => changeType(server, isServerItself ? null : target!.identifier, { egg_id: type.eggId ?? 0, environment: type.environment, reinstall }),
        onSuccess: (updated) => {
            toast.success(reinstall ? `${updated.name} is reinstalling as ${updated.egg?.name}.` : `${updated.name} now uses ${updated.egg?.name}. Restart it to apply.`);
            onDone();
            onClose();
        },
        onError: (error) => {
            setErrors(fieldErrors(error));
            toast.error(errorMessage(error));
        },
    });

    const changesEgg = target !== null && type.eggId !== target.egg?.id;

    return (
        <Dialog open={target !== null} onClose={onClose} title={'Change server type'} description={target?.name}>
            {target && (
                <div className={'ss:flex ss:flex-col ss:gap-4'}>
                    <TypeFields server={server} eggs={overview.eggs} value={type} onChange={setType} idPrefix={'ss-type'} errors={errors} />
                    <Switch
                        checked={reinstall}
                        onChange={setReinstall}
                        label={'Reinstall server'}
                        description={'Runs the new type\'s installer, which downloads its files. Files you added are usually kept, but back up first.'}
                    />
                    {changesEgg && !reinstall && (
                        <Alert type={'info'}>Without a reinstall, the server keeps its current files and only its startup settings change.</Alert>
                    )}
                </div>
            )}
            <Dialog.Footer>
                <Button.Text onClick={onClose}>Cancel</Button.Text>
                <Button onClick={() => mutation.mutate()} isLoading={mutation.isPending} disabled={type.eggId === null}>
                    Save
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
}
