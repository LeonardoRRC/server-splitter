import { Cpu, HardDrive, MemoryStick } from 'lucide-react';
import { CopyOnClick, Table, type TableColumn } from '@pterodactyl/sdk';
import type { SplitServer } from '../api';
import { formatCpu, formatMiB } from '../format';

const STATUS: Record<string, { label: string; danger: boolean }> = {
    installing: { label: 'Installing', danger: false },
    restoring_backup: { label: 'Restoring', danger: false },
    install_failed: { label: 'Install failed', danger: true },
    suspended: { label: 'Suspended', danger: true },
};

interface Props {
    servers: SplitServer[];
    showCpu: boolean;
    emptyState: React.ReactNode;
    actions?: (server: SplitServer) => React.ReactNode;
}

function Status({ status }: { status: string | null }) {
    const info = status ? STATUS[status] : undefined;
    if (!info) return null;

    return (
        <span
            className={`ss:rounded-full ss:px-2 ss:py-0.5 ss:text-xs ss:font-medium ${
                info.danger ? 'ss:bg-destructive/15 ss:text-destructive' : 'ss:bg-muted ss:text-muted-foreground'
            }`}
        >
            {info.label}
        </span>
    );
}

function Resource({ icon: Icon, label, value }: { icon: typeof Cpu; label: string; value: string }) {
    return (
        <span className={'ss:inline-flex ss:items-center ss:gap-1.5 ss:whitespace-nowrap'} title={label}>
            <Icon className={'ss:size-3.5 ss:shrink-0 ss:text-muted-foreground'} aria-hidden />
            <span className={'ss:sr-only'}>{label}: </span>
            {value}
        </span>
    );
}

/**
 * Servers in the Panel's own table. Each row keeps to four columns, with details stacked
 * inside them, so the whole row fits without scrolling sideways.
 */
export default function ServerTable({ servers, showCpu, emptyState, actions }: Props) {
    const columns: TableColumn<SplitServer>[] = [
        {
            id: 'server',
            label: 'Server',
            cell: (server) => (
                <div className={'ss:flex ss:min-w-0 ss:flex-col ss:gap-0.5'}>
                    <div className={'ss:flex ss:flex-wrap ss:items-center ss:gap-2'}>
                        <a className={'ss:font-medium ss:text-foreground ss:hover:underline'} href={`/server/${server.identifier}`}>
                            {server.name}
                        </a>
                        <Status status={server.status} />
                    </div>
                    <span className={'ss:text-xs ss:text-muted-foreground'}>
                        <span className={'ss:font-mono'}>{server.identifier}</span> · {server.egg?.name ?? 'Unknown type'}
                    </span>
                </div>
            ),
        },
        {
            id: 'address',
            label: 'Address',
            cell: (server) =>
                server.ip ? (
                    <CopyOnClick text={`${server.ip}:${server.port}`}>
                        <span className={'ss:font-mono ss:text-xs ss:break-all'}>
                            {server.ip}:{server.port}
                        </span>
                    </CopyOnClick>
                ) : (
                    <span className={'ss:text-muted-foreground'}>—</span>
                ),
        },
        {
            id: 'resources',
            label: 'Resources',
            cell: (server) => (
                <div className={'ss:flex ss:flex-wrap ss:gap-x-4 ss:gap-y-1 ss:text-sm'}>
                    <Resource icon={MemoryStick} label={'Memory'} value={formatMiB(server.memory)} />
                    <Resource icon={HardDrive} label={'Disk'} value={formatMiB(server.disk)} />
                    {showCpu && <Resource icon={Cpu} label={'CPU'} value={formatCpu(server.cpu)} />}
                </div>
            ),
        },
    ];

    if (actions) {
        columns.push({ id: 'actions', label: <span className={'ss:sr-only'}>Actions</span>, cell: (server) => <div className={'ss:flex ss:justify-end'}>{actions(server)}</div> });
    }

    return <Table rows={servers} columns={columns} keyOf={(server) => server.uuid} emptyState={emptyState} />;
}
