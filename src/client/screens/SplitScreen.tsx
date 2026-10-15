import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Crown, Egg, ExternalLink, GitFork, MoreHorizontal, Pencil, Server, Trash2 } from 'lucide-react';
import { Alert, Dialog, DropdownMenu, ServerContentBlock, ServerError, Spinner, toast, useCurrentServerRequired } from '@pterodactyl/sdk';
import { deleteSubServer, getOverview, overviewKey, promoteSubServer, type Overview, type SplitServer } from '../api';
import { errorMessage } from '../errors';
import { formatMiB } from '../format';
import ServerTable from '../components/ServerTable';
import CreateForm from '../components/CreateForm';
import { ResizeDialog, TypeDialog } from '../components/Dialogs';

type Action = { kind: 'resize' | 'type' | 'promote' | 'delete'; server: SplitServer } | null;

function Section({ icon: Icon, title, aside, children }: { icon: typeof Server; title: string; aside?: React.ReactNode; children: React.ReactNode }) {
    return (
        <section className={'ss:flex ss:min-w-0 ss:flex-col ss:gap-3'}>
            <div className={'ss:flex ss:items-center ss:justify-between ss:gap-3'}>
                <h2 className={'ss:flex ss:items-center ss:gap-2 ss:text-sm ss:font-semibold ss:text-foreground'}>
                    <Icon className={'ss:size-4 ss:text-muted-foreground'} aria-hidden />
                    {title}
                </h2>
                {aside}
            </div>
            {children}
        </section>
    );
}

function Content({ server, overview }: { server: string; overview: Overview }) {
    const client = useQueryClient();
    const [action, setAction] = useState<Action>(null);
    const refresh = () => client.invalidateQueries({ queryKey: overviewKey(server) });
    const close = () => setAction(null);
    const isMain = overview.role === 'main';
    const manage = isMain && overview.can_manage;
    const showCpu = overview.limits.split_cpu || overview.main.cpu > 0;

    const promote = useMutation({
        mutationFn: (target: SplitServer) => promoteSubServer(server, target.identifier),
        onSuccess: (target) => {
            toast.success(`${target.name} is now the main server.`);
            close();
            window.location.assign(`/server/${target.identifier}/split`);
        },
        onError: (error) => toast.error(errorMessage(error)),
    });
    const remove = useMutation({
        mutationFn: (target: SplitServer) => deleteSubServer(server, target.identifier),
        onSuccess: () => {
            toast.success('The sub-server was deleted and its resources returned.');
            close();
            refresh();
        },
        onError: (error) => toast.error(errorMessage(error)),
    });

    const menu = (target: SplitServer, isChild: boolean) => (
        <DropdownMenu triggerContent={<MoreHorizontal className={'ss:size-4'} aria-label={`Actions for ${target.name}`} />} triggerClassName={'ss:inline-grid ss:size-8 ss:place-items-center ss:rounded-md ss:text-muted-foreground ss:hover:bg-muted ss:hover:text-foreground'}>
            {isChild && <DropdownMenu.Item icon={Pencil} onClick={() => setAction({ kind: 'resize', server: target })}>Edit memory and disk</DropdownMenu.Item>}
            {isChild && <DropdownMenu.Item icon={Crown} onClick={() => setAction({ kind: 'promote', server: target })}>Set as main server</DropdownMenu.Item>}
            {overview.limits.allow_type_change && <DropdownMenu.Item icon={Egg} onClick={() => setAction({ kind: 'type', server: target })}>Change server type</DropdownMenu.Item>}
            <DropdownMenu.Item icon={ExternalLink} onClick={() => window.location.assign(`/server/${target.identifier}`)}>Open server</DropdownMenu.Item>
            {isChild && <DropdownMenu.Item icon={Trash2} danger onClick={() => setAction({ kind: 'delete', server: target })}>Delete</DropdownMenu.Item>}
        </DropdownMenu>
    );

    return (
        <>
            {!isMain && (
                <Alert type={'info'} className={'ss:mb-6'}>
                    This is a sub-server of <a className={'ss:font-semibold ss:underline'} href={`/server/${overview.main.identifier}/split`}>{overview.main.name}</a>. Manage
                    sub-servers from the main server.
                </Alert>
            )}
            {/* The form sits beside the tables only when the page is wide enough for both. */}
            <div className={'ss:@container'}>
            <div className={'ss:grid ss:items-start ss:gap-6 ss:@[78rem]:grid-cols-[minmax(0,1fr)_24rem]'}>
                <div className={'ss:flex ss:min-w-0 ss:flex-col ss:gap-6'}>
                    <Section icon={Server} title={'Main server'}>
                        <ServerTable servers={[overview.main]} showCpu={showCpu} emptyState={null} actions={manage ? (target) => menu(target, false) : undefined} />
                    </Section>
                    <Section
                        icon={GitFork}
                        title={'Sub-servers'}
                        aside={<span className={'ss:text-xs ss:text-muted-foreground'}>{overview.children.length} of {overview.limits.max_splits}</span>}
                    >
                        <ServerTable
                            servers={overview.children}
                            showCpu={showCpu}
                            emptyState={`There are no sub-servers yet. You can create up to ${overview.limits.max_splits}.`}
                            actions={manage ? (target) => menu(target, true) : undefined}
                        />
                    </Section>
                </div>
                {isMain && <CreateForm server={server} overview={overview} onCreated={refresh} />}
            </div>
            </div>

            <ResizeDialog server={server} overview={overview} target={action?.kind === 'resize' ? action.server : null} onClose={close} onDone={refresh} />
            <TypeDialog server={server} overview={overview} target={action?.kind === 'type' ? action.server : null} onClose={close} onDone={refresh} />
            <Dialog.Confirm
                open={action?.kind === 'promote'}
                onClose={close}
                title={`Make ${action?.server.name} the main server?`}
                confirm={'Set as main server'}
                pending={promote.isPending}
                onConfirmed={() => action && promote.mutate(action.server)}
            >
                {overview.main.name} becomes one of its sub-servers and keeps its resources. The other sub-servers move to {action?.server.name}. No server is restarted.
            </Dialog.Confirm>
            <Dialog.Confirm
                open={action?.kind === 'delete'}
                onClose={close}
                title={`Delete ${action?.server.name}?`}
                confirm={'Delete server'}
                pending={remove.isPending}
                onConfirmed={() => action && remove.mutate(action.server)}
            >
                The server and all of its files are deleted permanently. Its {formatMiB(action?.server.memory ?? 0)} of memory and {formatMiB(action?.server.disk ?? 0)} of disk go back to{' '}
                {overview.main.name}.
            </Dialog.Confirm>
        </>
    );
}

export default function SplitScreen() {
    const server = useCurrentServerRequired().attributes.identifier;
    const overview = useQuery({ queryKey: overviewKey(server), queryFn: () => getOverview(server) });

    return (
        <ServerContentBlock title={'Server Split'}>
            {overview.isLoading ? (
                <Spinner centered size={'large'} />
            ) : overview.isError || !overview.data ? (
                <ServerError message={errorMessage(overview.error)} onRetry={() => overview.refetch()} />
            ) : (
                <Content server={server} overview={overview.data} />
            )}
        </ServerContentBlock>
    );
}
