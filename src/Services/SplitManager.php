<?php

namespace UK\TheQuantum\ServerSplitter\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Allocations\CreatesAllocations;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Contracts\Servers\ChangesServerEgg;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Contracts\Servers\DeletesServers;
use Pterodactyl\Contracts\Servers\ReinstallsServers;
use Pterodactyl\Contracts\Servers\UpdatesServerBuild;
use Pterodactyl\Contracts\Subusers\CreatesSubusers;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Servers\VariableValidatorService;
use Throwable;
use UK\TheQuantum\ServerSplitter\Models\SplitLink;
use UK\TheQuantum\ServerSplitter\Support\SplitterConfig;

/**
 * Creates, resizes, and deletes sub-servers through the Panel's own server actions. A
 * sub-server's memory, disk, and CPU come out of its main server, and go back to it when
 * the sub-server is resized down or deleted.
 */
final class SplitManager
{
    /** The main server being deleted, whose sub-servers must not hand resources back to it. */
    private ?int $deletingParent = null;

    public function __construct(
        private readonly SplitterConfig $config,
        private readonly CreatesServers $creator,
        private readonly UpdatesServerBuild $builds,
        private readonly CreatesAllocations $allocations,
        private readonly CreatesSubusers $subusers,
        private readonly VariableValidatorService $variables,
        private readonly ChangesServerEgg $eggs,
        private readonly ReinstallsServers $reinstaller,
        private readonly MinecraftVersions $minecraft,
    ) {
    }

    public function linkFor(Server $server): ?SplitLink
    {
        return SplitLink::query()->where('server_id', $server->id)->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SplitLink> */
    public function childrenOf(Server $parent)
    {
        return SplitLink::query()->where('parent_id', $parent->id)->with('server.allocation', 'server.egg')->orderBy('id')->get();
    }

    /**
     * Whether a sub-server can get a port on the main server's IP: one of the node's free
     * allocations, or an unused port in the configured range.
     */
    public function hasFreePort(Server $parent): bool
    {
        $primary = $parent->allocation;
        if (! $primary instanceof Allocation) {
            return false;
        }

        return $this->freeAllocations($parent, $primary)->exists() || $this->unusedRangePorts($parent, $primary) !== [];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Allocation> */
    private function freeAllocations(Server $parent, Allocation $primary)
    {
        return Allocation::query()->where('node_id', $parent->node_id)->where('ip', $primary->ip)->whereNull('server_id');
    }

    /** @return list<int> */
    private function unusedRangePorts(Server $parent, Allocation $primary): array
    {
        $range = $this->config->portRange();
        if ($range === null) {
            return [];
        }
        $taken = Allocation::query()->where('node_id', $parent->node_id)->where('ip', $primary->ip)
            ->whereBetween('port', $range)->pluck('port')->map(fn ($port): int => (int) $port)->flip();

        return array_values(array_filter(range($range[0], $range[1]), fn (int $port): bool => ! $taken->has($port)));
    }

    /**
     * Why the server cannot be split, or null when it can.
     */
    public function blockedReason(Server $parent): ?string
    {
        if ($this->linkFor($parent) !== null) {
            return 'This server is a sub-server, so it cannot be split.';
        }
        if ($parent->memory <= 0 || $parent->disk <= 0) {
            return 'Servers with unlimited memory or disk cannot be split.';
        }
        if (SplitLink::query()->where('parent_id', $parent->id)->count() >= (int) $this->config->get('max_splits')) {
            return sprintf('This server already has the maximum of %d sub-servers.', (int) $this->config->get('max_splits'));
        }

        return null;
    }

    /**
     * Creates a sub-server owned by the main server's owner.
     *
     * @param  array{name: string, egg_id: int, environment: array<string, scalar|null>, memory: int, disk: int, cpu: int|null, sync_subusers: bool, start: bool}  $data
     *
     * @throws DisplayException
     * @throws ValidationException
     * @throws Throwable
     */
    public function create(Server $parent, array $data): Server
    {
        return $this->locked($parent, function () use ($parent, $data): Server {
            $parent->refresh();
            $config = $this->config->all();
            if (($reason = $this->blockedReason($parent)) !== null) {
                throw new DisplayException($reason);
            }

            /** @var Egg $egg */
            $egg = Egg::query()->findOrFail($data['egg_id']);
            if (! $this->config->eggAllowed($egg->id)) {
                throw ValidationException::withMessages(['egg_id' => 'This server type is not available for sub-servers.']);
            }

            [$memory, $disk, $cpu] = $this->checkResources($parent, (int) $data['memory'], (int) $data['disk'], $data['cpu'] ?? null);
            $environment = $this->environment($egg, $data['environment']);

            // Ports are shared by every server on the node, so claiming one and creating the
            // server that uses it happen one at a time per node.
            return $this->lockedNode($parent, fn (): Server => $this->createOnNode($parent, $egg, $environment, $memory, $disk, $cpu, $data, $config));
        });
    }

    /**
     * Takes the sub-server's share from the main server and creates the sub-server on a
     * random free port. The share goes back when the creation fails.
     *
     * @param  array<string, scalar|null>  $environment
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $config
     *
     * @throws Throwable
     */
    private function createOnNode(Server $parent, Egg $egg, array $environment, int $memory, int $disk, int $cpu, array $data, array $config): Server
    {
        {
            [$allocation, $created] = $this->claimPort($parent);

            $before = $this->buildOf($parent);
            $this->builds->update($parent, [
                ...$before,
                'memory' => $parent->memory - $memory,
                'disk' => $parent->disk - $disk,
                'cpu' => $this->splitsCpu($parent) ? $parent->cpu - $cpu : $parent->cpu,
            ]);

            try {
                $image = array_values($egg->docker_images ?? [])[0] ?? null;
                throw_if($image === null, DisplayException::class, 'This server type has no Docker image.');

                $server = $this->creator->create([
                    'name' => $data['name'],
                    'description' => sprintf('Split from %s.', $parent->name),
                    'owner_id' => $parent->owner_id,
                    'egg_id' => $egg->id,
                    'image' => $image,
                    'startup' => $egg->startup,
                    'environment' => $environment,
                    'memory' => $memory,
                    'swap' => 0,
                    'disk' => $disk,
                    'io' => $parent->io,
                    'cpu' => $this->splitsCpu($parent) ? $cpu : $parent->cpu,
                    'threads' => $parent->threads,
                    'allocation_id' => $allocation->id,
                    'start_on_completion' => (bool) $data['start'],
                    'database_limit' => (int) $config['database_limit'],
                    'backup_limit' => (int) $config['backup_limit'],
                    'allocation_limit' => (int) $config['allocation_limit'],
                    'oom_disabled' => (bool) $parent->oom_disabled,
                ]);
            } catch (Throwable $throwable) {
                // Give the resources back and drop a port created for this sub-server.
                rescue(fn () => $this->builds->update($parent->refresh(), $before));
                if ($created) {
                    rescue(fn () => Allocation::query()->whereKey($allocation->id)->whereNull('server_id')->delete());
                }
                throw $throwable;
            }

            SplitLink::query()->create([
                'parent_id' => $parent->id,
                'server_id' => $server->id,
                'memory' => $memory,
                'disk' => $disk,
                'cpu' => $this->splitsCpu($parent) ? $cpu : 0,
            ]);

            if ($data['sync_subusers'] && $config['allow_subuser_sync']) {
                $this->copySubusers($parent, $server);
            }

            return $server;
        }
    }

    /**
     * Moves memory, disk, and CPU between a sub-server and its main server.
     *
     * @throws DisplayException
     * @throws Throwable
     */
    public function resize(SplitLink $link, int $memory, int $disk, ?int $cpu): Server
    {
        $parent = $link->parent ?? throw new DisplayException('The main server no longer exists.');

        return $this->locked($parent, function () use ($link, $parent, $memory, $disk, $cpu): Server {
            $parent->refresh();
            $link->refresh();
            $child = $link->server ?? throw new DisplayException('The sub-server no longer exists.');
            $this->assertLimited($parent);
            $splitsCpu = $link->cpu > 0 && $parent->cpu > 0;
            $cpu = $splitsCpu ? (int) $cpu : 0;

            // The main server temporarily holds both shares while the check runs.
            $pool = clone $parent;
            $pool->memory += $link->memory;
            $pool->disk += $link->disk;
            $pool->cpu += $splitsCpu ? $link->cpu : 0;
            if ($splitsCpu) {
                [$memory, $disk, $cpu] = $this->checkResources($pool, $memory, $disk, $cpu);
            } else {
                [$memory, $disk] = $this->checkResources($pool, $memory, $disk, null, cpuSplit: false);
            }

            $parentBuild = [...$this->buildOf($parent), 'memory' => $pool->memory - $memory, 'disk' => $pool->disk - $disk];
            $childBuild = [...$this->buildOf($child), 'memory' => $memory, 'disk' => $disk];
            if ($splitsCpu) {
                $parentBuild['cpu'] = $pool->cpu - $cpu;
                $childBuild['cpu'] = $cpu;
            }

            $child = $this->moveBetween($parent, $parentBuild, $child, $childBuild);
            $link->update(['memory' => $memory, 'disk' => $disk, 'cpu' => $cpu]);

            return $child;
        });
    }

    /**
     * Moves a main server or sub-server to another server type and version. Its files stay
     * unless it is reinstalled.
     *
     * @param  array<string, scalar|null>  $values
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function changeType(Server $server, int $eggId, array $values, bool $reinstall): Server
    {
        /** @var Egg $egg */
        $egg = Egg::query()->findOrFail($eggId);
        if ($egg->id !== $server->egg_id && ! $this->config->eggAllowed($egg->id)) {
            throw ValidationException::withMessages(['egg_id' => 'This server type is not available.']);
        }

        $environment = $this->environment($egg, $values);
        if ($egg->id !== $server->egg_id) {
            $server = $this->eggs->change($server, $egg);
        }

        // Store the version and build; every other setting keeps the egg's default.
        foreach ($egg->variables()->whereIn('env_variable', $this->choosable($egg))->get() as $variable) {
            if (array_key_exists($variable->env_variable, $values)) {
                ServerVariable::query()->updateOrCreate(
                    ['server_id' => $server->id, 'variable_id' => $variable->id],
                    ['variable_value' => (string) ($environment[$variable->env_variable] ?? '')],
                );
            }
        }

        return $reinstall ? $this->reinstaller->reinstall($server->refresh()) : $server->refresh();
    }

    /**
     * Makes a sub-server the main server. The main server becomes one of its sub-servers,
     * keeping what it has now, and the other sub-servers move to the new main server.
     * No resources move between the servers.
     *
     * @throws Throwable
     */
    public function promote(SplitLink $link): Server
    {
        $parent = $link->parent ?? throw new DisplayException('The main server no longer exists.');

        return $this->locked($parent, function () use ($link, $parent): Server {
            $parent->refresh();
            $child = $link->server ?? throw new DisplayException('The sub-server no longer exists.');
            $this->assertLimited($parent);

            DB::transaction(function () use ($link, $parent, $child): void {
                SplitLink::query()->where('parent_id', $parent->id)->whereKeyNot($link->id)->update(['parent_id' => $child->id]);
                $link->update([
                    'parent_id' => $child->id,
                    'server_id' => $parent->id,
                    'memory' => $parent->memory,
                    'disk' => $parent->disk,
                    'cpu' => $link->cpu > 0 ? $parent->cpu : 0,
                ]);
            });

            return $child;
        });
    }

    /**
     * Called around every server deletion. Deleting a main server deletes its sub-servers
     * first, or keeps them as ordinary servers, as configured.
     *
     * @throws Throwable
     */
    public function beforeDelete(Server $server, DeletesServers $deleter, bool $force): void
    {
        $children = SplitLink::query()->where('parent_id', $server->id)->with('server')->get();
        if ($children->isEmpty() || $this->config->get('on_parent_delete') !== 'delete') {
            return;
        }

        $this->deletingParent = $server->id;
        try {
            foreach ($children as $link) {
                if ($link->server instanceof Server) {
                    $deleter->withForce($force)->delete($link->server);
                }
            }
        } finally {
            $this->deletingParent = null;
        }
    }

    /**
     * Gives a deleted sub-server's resources back to its main server.
     */
    public function release(SplitLink $link): void
    {
        SplitLink::query()->whereKey($link->id)->delete();
        if ($this->deletingParent === $link->parent_id) {
            return;
        }

        $parent = Server::query()->find($link->parent_id);
        if (! $parent instanceof Server) {
            return;
        }

        try {
            $this->locked($parent, function () use ($parent, $link): void {
                $parent->refresh();
                $this->builds->update($parent, [
                    ...$this->buildOf($parent),
                    // A main server made unlimited in the meantime stays unlimited.
                    'memory' => $parent->memory > 0 ? $parent->memory + $link->memory : $parent->memory,
                    'disk' => $parent->disk > 0 ? $parent->disk + $link->disk : $parent->disk,
                    'cpu' => $link->cpu > 0 && $parent->cpu > 0 ? $parent->cpu + $link->cpu : $parent->cpu,
                ]);
            });
        } catch (Throwable $throwable) {
            Log::error('Server Splitter could not return a deleted sub-server\'s resources.', [
                'parent_id' => $link->parent_id, 'memory' => $link->memory, 'disk' => $link->disk, 'cpu' => $link->cpu, 'exception' => $throwable,
            ]);
            throw $throwable;
        }
    }

    /**
     * Applies new limits to two servers that share a fixed total. Every value that goes down
     * is lowered on both servers first, then the values that go up are raised, so at no point
     * do the two hold more than the total. When a step fails, both servers get their old
     * limits back.
     *
     * @param  array<string, mixed>  $parentBuild
     * @param  array<string, mixed>  $childBuild
     *
     * @throws Throwable
     */
    private function moveBetween(Server $parent, array $parentBuild, Server $child, array $childBuild): Server
    {
        $parentBefore = $this->buildOf($parent);
        $childBefore = $this->buildOf($child);
        $lower = fn (array $before, array $after): array => array_merge($after, array_map(
            fn (string $key) => min((int) $before[$key], (int) $after[$key]),
            array_combine(['memory', 'disk', 'cpu'], ['memory', 'disk', 'cpu']),
        ));

        try {
            $this->builds->update($child, $lower($childBefore, $childBuild));
            $this->builds->update($parent, $lower($parentBefore, $parentBuild));
            $this->builds->update($parent->refresh(), $parentBuild);

            return $this->builds->update($child->refresh(), $childBuild);
        } catch (Throwable $throwable) {
            // Both lower steps keep the total; restoring the old limits keeps it too.
            rescue(fn () => $this->builds->update($child->refresh(), $childBefore));
            rescue(fn () => $this->builds->update($parent->refresh(), $parentBefore));
            throw $throwable;
        }
    }

    /**
     * An administrator may give a main server unlimited memory or disk after splitting it.
     * Its sub-servers then cannot be resized or promoted, since there is no total to share.
     *
     * @throws DisplayException
     */
    private function assertLimited(Server $parent): void
    {
        if ($parent->memory <= 0 || $parent->disk <= 0) {
            throw new DisplayException('The main server now has unlimited memory or disk, so its sub-servers cannot be resized or promoted. Ask an administrator.');
        }
    }

    /**
     * Checks a sub-server's share against the limits and what the main server keeps.
     *
     * @return array{0: int, 1: int, 2: int}
     *
     * @throws ValidationException
     */
    private function checkResources(Server $parent, int $memory, int $disk, ?int $cpu, bool $cpuSplit = true): array
    {
        $config = $this->config->all();
        $errors = [];
        $minMemory = (int) $config['min_memory'];
        $minDisk = (int) $config['min_disk'];

        if ($memory < $minMemory) {
            $errors['memory'] = sprintf('A sub-server needs at least %d MiB of memory.', $minMemory);
        } elseif ($parent->memory - $memory < $minMemory) {
            $errors['memory'] = sprintf('The main server must keep at least %d MiB of memory. You can give up to %d MiB.', $minMemory, max(0, $parent->memory - $minMemory));
        }
        if ($disk < $minDisk) {
            $errors['disk'] = sprintf('A sub-server needs at least %d MiB of disk.', $minDisk);
        } elseif ($parent->disk - $disk < $minDisk) {
            $errors['disk'] = sprintf('The main server must keep at least %d MiB of disk. You can give up to %d MiB.', $minDisk, max(0, $parent->disk - $minDisk));
        }

        $cpu = (int) $cpu;
        if ($cpuSplit && $this->splitsCpu($parent)) {
            $minCpu = (int) $config['min_cpu'];
            if ($cpu < $minCpu) {
                $errors['cpu'] = sprintf('A sub-server needs at least %d%% CPU.', $minCpu);
            } elseif ($parent->cpu - $cpu < $minCpu) {
                $errors['cpu'] = sprintf('The main server must keep at least %d%% CPU. You can give up to %d%%.', $minCpu, max(0, $parent->cpu - $minCpu));
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$memory, $disk, $cpu];
    }

    public function splitsCpu(Server $parent): bool
    {
        return (bool) $this->config->get('split_cpu') && $parent->cpu > 0;
    }

    /**
     * The variables users choose: the software, version, and build of the MCJars egg. Every
     * other setting, and every setting of other eggs, takes the egg's default.
     *
     * @return list<string>
     */
    public function choosable(Egg $egg): array
    {
        $picker = $this->minecraft->pickerFor($egg);

        return $picker === null ? [] : array_values($picker);
    }

    /**
     * The egg's defaults, with the user's software, version, and build for the MCJars egg.
     *
     * @param  array<string, scalar|null>  $values
     * @return array<string, scalar|null>
     *
     * @throws ValidationException
     */
    private function environment(Egg $egg, array $values): array
    {
        $values = array_intersect_key($values, array_flip($this->choosable($egg)));

        $environment = [];
        foreach ($egg->variables()->get() as $variable) {
            $environment[$variable->env_variable] = $variable->default_value;
        }
        foreach ($values as $key => $value) {
            $environment[$key] = $value;
        }

        // The egg's own rules apply when the server is created; MCJars confirms the choice.
        $this->variables->setUserLevel(User::USER_LEVEL_USER)->handle($egg, $environment);
        if ($values !== []) {
            $this->minecraft->validate($environment);
        }

        return $environment;
    }

    /**
     * Picks a random free port on the main server's IP: one of the node's free allocations,
     * or else an unused port from the configured range, which is added to the node.
     *
     * @return array{0: Allocation, 1: bool} the allocation, and whether it was created for this
     *
     * @throws DisplayException
     */
    private function claimPort(Server $parent): array
    {
        $primary = $parent->allocation ?? throw new DisplayException('The main server has no address.');

        $allocation = $this->freeAllocations($parent, $primary)->inRandomOrder()->first();
        if ($allocation instanceof Allocation) {
            return [$allocation, false];
        }

        $ports = $this->unusedRangePorts($parent, $primary);
        if ($ports === []) {
            throw new DisplayException('There are no free ports on this server\'s IP. Ask an administrator to add some.');
        }
        $port = $ports[array_rand($ports)];
        $this->allocations->create($parent->node, ['allocation_ip' => $primary->ip, 'allocation_ports' => [(string) $port]]);

        return [Allocation::query()->where('node_id', $parent->node_id)->where('ip', $primary->ip)->where('port', $port)->firstOrFail(), true];
    }

    /** Gives the sub-server the main server's subusers with the same permissions. */
    private function copySubusers(Server $parent, Server $server): void
    {
        foreach ($parent->subusers()->with('user')->get() as $subuser) {
            if ($subuser->user === null || $subuser->user_id === $server->owner_id) {
                continue;
            }
            rescue(fn () => $this->subusers->create($server, $subuser->user->email, $subuser->permissions));
        }
    }

    /**
     * The build values UpdateServerBuild resets when they are missing.
     *
     * @return array<string, mixed>
     */
    private function buildOf(Server $server): array
    {
        return [
            'memory' => $server->memory,
            'disk' => $server->disk,
            'cpu' => $server->cpu,
            'database_limit' => $server->database_limit,
            'allocation_limit' => $server->allocation_limit,
            'backup_limit' => $server->backup_limit,
        ];
    }

    /**
     * Runs one port claim at a time on the main server's node.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function lockedNode(Server $parent, callable $callback): mixed
    {
        try {
            return Cache::lock('server-splitter:node:'.$parent->node_id, 120)->block(60, $callback);
        } catch (LockTimeoutException) {
            throw new DisplayException('Other servers are being created on this node. Try again in a moment.');
        }
    }

    /**
     * Runs one change to a main server's resources at a time.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(Server $parent, callable $callback): mixed
    {
        /** @var Lock $lock */
        $lock = Cache::lock('server-splitter:'.$parent->id, 60);

        try {
            return $lock->block(15, $callback);
        } catch (LockTimeoutException) {
            throw new DisplayException('Another change to this server\'s sub-servers is still running. Try again in a moment.');
        }
    }
}
