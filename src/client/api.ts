import { http } from '@pterodactyl/sdk';

export interface SplitServer {
    identifier: string;
    uuid: string;
    name: string;
    memory: number;
    disk: number;
    cpu: number;
    status: string | null;
    ip: string | null;
    port: number | null;
    egg: { id: number; name: string } | null;
    environment: Record<string, string | null>;
}

export interface EggVariable {
    env: string;
    name: string;
    description: string;
    default: string;
    rules: string;
}

export interface SplitEgg {
    id: number;
    name: string;
    category: string;
    allowed: boolean;
    /** The MCJars egg's software, version, and build variables. */
    picker: { software: string; version: string; build: string } | null;
    variables: EggVariable[];
}

export interface Overview {
    role: 'main' | 'sub';
    main: SplitServer;
    current: SplitServer;
    children: SplitServer[];
    limits: {
        max_splits: number;
        min_memory: number;
        min_disk: number;
        min_cpu: number;
        split_cpu: boolean;
        allow_subuser_sync: boolean;
        allow_type_change: boolean;
        blocked: string | null;
    };
    can_manage: boolean;
    eggs: SplitEgg[];
    has_free_port: boolean;
}

export interface CreatePayload {
    name: string;
    egg_id: number;
    environment: Record<string, string>;
    memory: number;
    disk: number;
    cpu: number | null;
    sync_subusers: boolean;
    start: boolean;
}

export interface TypePayload {
    egg_id: number;
    environment: Record<string, string>;
    reinstall: boolean;
}

const base = (server: string) => `/api/client/servers/${server}/extensions/server-splitter`;

export const overviewKey = (server: string) => ['server-splitter', server] as const;

export async function getOverview(server: string): Promise<Overview> {
    return (await http.get<Overview>(base(server))).data;
}

export interface SoftwareOption {
    value: string;
    label: string;
    group: string;
}

export async function getTypes(server: string): Promise<SoftwareOption[]> {
    return (await http.get<{ types: SoftwareOption[] }>(`${base(server)}/types`)).data.types;
}

export async function getVersions(server: string, type: string): Promise<string[]> {
    return (await http.get<{ versions: string[] }>(`${base(server)}/versions`, { params: { type } })).data.versions;
}

/** Builds by MCJars build ID, which the MCJars egg installs; `name` is the visible build number. */
export async function getBuilds(server: string, type: string, version: string): Promise<{ id: string; name: string }[]> {
    return (await http.get<{ builds: { id: string; name: string }[] }>(`${base(server)}/builds`, { params: { type, version } })).data.builds;
}

export async function createSubServer(server: string, payload: CreatePayload): Promise<SplitServer> {
    return (await http.post<SplitServer>(base(server), payload)).data;
}

export async function resizeSubServer(server: string, child: string, payload: { memory: number; disk: number; cpu: number | null }): Promise<SplitServer> {
    return (await http.patch<SplitServer>(`${base(server)}/${child}/resources`, payload)).data;
}

/** Changes the type of a sub-server, or of the server itself when child is null. */
export async function changeType(server: string, child: string | null, payload: TypePayload): Promise<SplitServer> {
    return (await http.put<SplitServer>(child === null ? `${base(server)}/type` : `${base(server)}/${child}/type`, payload)).data;
}

export async function promoteSubServer(server: string, child: string): Promise<SplitServer> {
    return (await http.post<SplitServer>(`${base(server)}/${child}/promote`)).data;
}

export async function deleteSubServer(server: string, child: string): Promise<void> {
    await http.delete(`${base(server)}/${child}`);
}

export interface AdminConfig {
    max_splits: number;
    eggs: 'all' | 'selected';
    allowed_eggs: number[];
    min_memory: number;
    min_disk: number;
    split_cpu: boolean;
    min_cpu: number;
    database_limit: number;
    backup_limit: number;
    allocation_limit: number;
    port_range: string;
    allow_subuser_sync: boolean;
    allow_type_change: boolean;
    on_parent_delete: 'delete' | 'detach';
    mirror_suspension: boolean;
    /** 'auto' finds the official MCJars egg; 'off' turns the lists off. */
    version_egg: 'auto' | 'off' | number;
}

export interface AdminPayload {
    config: AdminConfig;
    eggs: { id: number; name: string; category: string }[];
    mcjars_egg: number | null;
    stats: { sub_servers: number; main_servers: number };
}

export const adminKey = ['server-splitter', 'admin'] as const;

export async function getAdminConfig(): Promise<AdminPayload> {
    return (await http.get<AdminPayload>('/api/admin/extensions/server-splitter/config')).data;
}

export async function saveAdminConfig(config: AdminConfig): Promise<AdminPayload> {
    return (await http.put<AdminPayload>('/api/admin/extensions/server-splitter/config', config)).data;
}
