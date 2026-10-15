<?php

namespace UK\TheQuantum\ServerSplitter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Knuckles\Scribe\Attributes\Endpoint;
use Pterodactyl\Contracts\Servers\DeletesServers;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Throwable;
use UK\TheQuantum\ServerSplitter\Models\SplitLink;
use UK\TheQuantum\ServerSplitter\Services\MinecraftVersions;
use UK\TheQuantum\ServerSplitter\Services\SplitManager;
use UK\TheQuantum\ServerSplitter\Support\SplitterConfig;

/** Client API under /api/client/servers/{server}/extensions/server-splitter. */
class SplitController
{
    /** Upper bounds that keep every value well inside the database columns. */
    private const MAX_MIB = 16_777_216;

    private const MAX_CPU = 100_000;

    public function __construct(
        private readonly SplitManager $splits,
        private readonly SplitterConfig $config,
        private readonly MinecraftVersions $minecraft,
    ) {
    }

    #[Endpoint('Get the split overview', 'The main server, its sub-servers, the limits, and and the server types available.')]
    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');

        $link = $this->splits->linkFor($server);
        $main = $link?->parent ?? $server;
        $config = $this->config->all();

        return new JsonResponse([
            'role' => $link === null ? 'main' : 'sub',
            'main' => $this->describe($main),
            'current' => $this->describe($server),
            'children' => $this->splits->childrenOf($main)
                ->filter(fn (SplitLink $child): bool => $child->server instanceof Server)
                ->map(fn (SplitLink $child): array => $this->describe($child->server))->values(),
            'limits' => [
                'max_splits' => (int) $config['max_splits'],
                'min_memory' => (int) $config['min_memory'],
                'min_disk' => (int) $config['min_disk'],
                'min_cpu' => (int) $config['min_cpu'],
                'split_cpu' => $this->splits->splitsCpu($main),
                'allow_subuser_sync' => (bool) $config['allow_subuser_sync'],
                'allow_type_change' => (bool) $config['allow_type_change'],
                'blocked' => $link === null ? $this->splits->blockedReason($server) : null,
            ],
            'can_manage' => $request->user()->can('ext.server-splitter.manage', $server),
            'eggs' => $this->eggs($server),
            'has_free_port' => $link === null && $this->splits->hasFreePort($server),
        ]);
    }

    #[Endpoint('List Minecraft server software', 'The server software MCJars offers, such as Paper, Purpur, Fabric, and Velocity.')]
    public function types(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        abort_if($this->minecraft->eggId() === null, 404, 'Version lists are turned off.');

        return new JsonResponse(['types' => rescue(fn () => $this->minecraft->types(), [], false)]);
    }

    #[Endpoint('List Minecraft versions', 'Versions MCJars knows for the server software, newest first.')]
    public function versions(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        $type = $this->typeFromRequest($request);

        return new JsonResponse(['versions' => rescue(fn () => $this->minecraft->versions($type), [], false)]);
    }

    #[Endpoint('List Minecraft builds', 'Builds MCJars knows for the server software and version, newest first.')]
    public function builds(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        $type = $this->typeFromRequest($request);
        $version = (string) $request->validate(['version' => ['required', 'string', 'max:40', 'regex:/^[\w.+-]+$/']])['version'];

        return new JsonResponse(['builds' => rescue(fn () => $this->minecraft->builds($type, $version), [], false)]);
    }

    #[Endpoint('Create a sub-server', 'Splits memory, disk, and CPU off this server into a new server.')]
    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'manage');
        $this->throttle($request, $server);
        $this->keepRunning();
        $config = $this->config->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:191'],
            'egg_id' => ['required', 'integer'],
            'environment' => ['present', 'array'],
            'environment.*' => ['nullable', 'string', 'max:2048'],
            'memory' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MIB],
            'disk' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MIB],
            'cpu' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_CPU],
            'sync_subusers' => ['boolean'],
            'start' => ['boolean'],
        ]);

        $child = $this->splits->create($server, [
            ...$data,
            'cpu' => $data['cpu'] ?? null,
            'sync_subusers' => (bool) ($data['sync_subusers'] ?? false) && $config['allow_subuser_sync'],
            'start' => (bool) ($data['start'] ?? false),
        ]);

        Activity::event('server:split.create')->property(['name' => $child->name, 'identifier' => $child->uuidShort, 'memory' => $child->memory, 'disk' => $child->disk])->log();

        return new JsonResponse($this->describe($child->refresh()), 201);
    }

    #[Endpoint('Resize a sub-server', 'Moves memory, disk, and CPU between the sub-server and this server.')]
    public function resize(Request $request, Server $server, string $child): JsonResponse
    {
        $this->authorize($request, $server, 'manage');
        $this->throttle($request, $server);
        $this->keepRunning();
        $link = $this->link($server, $child);
        $data = $request->validate([
            'memory' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MIB],
            'disk' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MIB],
            'cpu' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_CPU],
        ]);

        $updated = $this->splits->resize($link, (int) $data['memory'], (int) $data['disk'], isset($data['cpu']) ? (int) $data['cpu'] : null);
        Activity::event('server:split.resize')->property(['identifier' => $updated->uuidShort, 'memory' => $updated->memory, 'disk' => $updated->disk])->log();

        return new JsonResponse($this->describe($updated));
    }

    #[Endpoint('Change a server type', 'Moves this server or one of its sub-servers to another server type and version.')]
    public function changeType(Request $request, Server $server, ?string $child = null): JsonResponse
    {
        $this->authorize($request, $server, 'manage');
        abort_unless((bool) $this->config->get('allow_type_change'), 403, 'Changing server types is turned off.');
        $this->throttle($request, $server);
        $this->keepRunning();
        $target = $child === null ? $server : $this->link($server, $child)->server;
        $data = $request->validate([
            'egg_id' => ['required', 'integer'],
            'environment' => ['present', 'array'],
            'environment.*' => ['nullable', 'string', 'max:2048'],
            'reinstall' => ['boolean'],
        ]);

        $updated = $this->splits->changeType($target, (int) $data['egg_id'], $data['environment'], (bool) ($data['reinstall'] ?? false));
        Activity::event('server:split.change-type')->property(['identifier' => $updated->uuidShort, 'egg' => $updated->egg?->name, 'reinstall' => (bool) ($data['reinstall'] ?? false)])->log();

        return new JsonResponse($this->describe($updated));
    }

    #[Endpoint('Make a sub-server the main server', 'This server becomes a sub-server of it. No resources move.')]
    public function promote(Request $request, Server $server, string $child): JsonResponse
    {
        $this->authorize($request, $server, 'manage');
        $this->throttle($request, $server);
        $this->keepRunning();
        $promoted = $this->splits->promote($this->link($server, $child));
        Activity::event('server:split.promote')->property(['identifier' => $promoted->uuidShort])->log();

        return new JsonResponse($this->describe($promoted));
    }

    #[Endpoint('Delete a sub-server', 'Deletes the sub-server and gives its resources back to this server.')]
    public function destroy(Request $request, Server $server, string $child, DeletesServers $deleter): Response
    {
        $this->authorize($request, $server, 'manage');
        $this->throttle($request, $server);
        $this->keepRunning();
        $target = $this->link($server, $child)->server;
        $name = $target->name;

        $deleter->delete($target);
        Activity::event('server:split.delete')->property(['name' => $name, 'identifier' => $child])->log();

        return new Response('', 204);
    }

    /** At most twenty changes a minute for each user and server. */
    private function throttle(Request $request, Server $server): void
    {
        $key = sprintf('server-splitter:%d:%d', $request->user()->id, $server->id);
        if (RateLimiter::tooManyAttempts($key, 20)) {
            abort(429, sprintf('Too many changes. Try again in %d seconds.', RateLimiter::availableIn($key)));
        }
        RateLimiter::hit($key, 60);
    }

    /** A change touches several servers, so it finishes even if the browser goes away. */
    private function keepRunning(): void
    {
        ignore_user_abort(true);
        @set_time_limit(300);
    }

    private function authorize(Request $request, Server $server, string $permission): void
    {
        abort_unless($request->user()->can('ext.server-splitter.'.$permission, $server), 403, $permission === 'read'
            ? 'You do not have permission to view this server\'s sub-servers.'
            : 'You do not have permission to manage this server\'s sub-servers.');
    }

    private function link(Server $server, string $identifier): SplitLink
    {
        $child = Server::query()->where(fn ($query) => $query->where('uuidShort', $identifier)->orWhere('uuid', $identifier))->first();
        $link = $child === null ? null : SplitLink::query()->where('parent_id', $server->id)->where('server_id', $child->id)->with('server', 'parent')->first();

        return $link ?? abort(404, 'That sub-server does not belong to this server.');
    }

    private function typeFromRequest(Request $request): string
    {
        abort_if($this->minecraft->eggId() === null, 404, 'Version lists are turned off.');

        return (string) $request->validate(['type' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,20}$/']])['type'];
    }

    private function describe(Server $server): array
    {
        $server->loadMissing('allocation', 'egg');
        $allocation = $server->allocation;

        return [
            'identifier' => $server->uuidShort,
            'uuid' => $server->uuid,
            'name' => $server->name,
            'memory' => (int) $server->memory,
            'disk' => (int) $server->disk,
            'cpu' => (int) $server->cpu,
            'status' => $server->status,
            'ip' => $allocation instanceof Allocation ? ($allocation->ip_alias ?: $allocation->ip) : null,
            'port' => $allocation instanceof Allocation ? (int) $allocation->port : null,
            'egg' => $server->egg ? ['id' => $server->egg->id, 'name' => $server->egg->name] : null,
            'environment' => $server->variables()->get()->mapWithKeys(fn ($variable) => [$variable->env_variable => $variable->server_value ?? $variable->default_value])->all(),
        ];
    }

    /** The server types users may choose, with the version and build variables they may set. */
    private function eggs(Server $server): array
    {
        $config = $this->config->all();
        $query = Egg::query()->with(['tags', 'variables'])->orderBy('name');
        if ($config['eggs'] !== 'all') {
            $query->where(fn ($inner) => $inner->whereIn('id', array_map('intval', $config['allowed_eggs']))->orWhere('id', $server->egg_id));
        }

        return $query->get()->map(function (Egg $egg) use ($config): array {
            $picker = $this->minecraft->pickerFor($egg);
            // Only the MCJars egg's software, version, and build are chosen; the rest are defaults.
            $variables = $egg->variables->filter(fn ($variable): bool => $picker !== null && in_array($variable->env_variable, $picker, true))->values();

            return [
                'id' => $egg->id,
                'name' => $egg->name,
                'category' => $egg->tags->first()?->name ?? 'Other',
                'allowed' => $this->config->eggAllowed($egg->id),
                'picker' => $picker,
                'variables' => $variables->map(fn ($variable): array => [
                    'env' => $variable->env_variable,
                    'name' => $variable->name,
                    'description' => (string) $variable->description,
                    'default' => (string) ($variable->default_value ?? ''),
                    'rules' => (string) $variable->rules,
                ])->all(),
            ];
        })->all();
    }
}
