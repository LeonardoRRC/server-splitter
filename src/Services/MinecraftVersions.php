<?php

namespace UK\TheQuantum\ServerSplitter\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\Egg;
use UK\TheQuantum\ServerSplitter\Support\SplitterConfig;

/**
 * Software, version, and build lists from MCJars (versions.mcjars.app) for the official MCJars
 * egg, which installs any Minecraft Java server software from its SOFTWARE, VERSION, and BUILD
 * variables. Lists are cached for an hour.
 */
final class MinecraftVersions
{
    /** The MCJars egg's author, which identifies it on any Panel. */
    public const EGG_AUTHOR = 'rjansengd@gmail.com';

    /** The MCJars egg's variables: server software, Minecraft version, and build ID. */
    public const VARIABLES = ['software' => 'SOFTWARE', 'version' => 'VERSION', 'build' => 'BUILD'];

    private const API = 'https://versions.mcjars.app/api/v2/';

    public function __construct(private readonly SplitterConfig $config)
    {
    }

    /** The egg that gets version lists: the one chosen in the settings, or the MCJars egg. */
    public function eggId(): ?int
    {
        $choice = $this->config->get('version_egg');
        if ($choice === 'off') {
            return null;
        }
        if ($choice !== 'auto' && is_numeric($choice)) {
            return Egg::query()->whereKey((int) $choice)->exists() ? (int) $choice : null;
        }

        $id = Egg::query()->where('author', self::EGG_AUTHOR)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{software: string, version: string, build: string}|null */
    public function pickerFor(Egg $egg): ?array
    {
        if ($egg->id !== $this->eggId()) {
            return null;
        }
        $names = $egg->variables()->pluck('env_variable')->all();

        return array_diff(self::VARIABLES, $names) === [] ? self::VARIABLES : null;
    }

    /** @return list<array{value: string, label: string, group: string}> */
    public function types(): array
    {
        return Cache::remember('server-splitter:mcjars:v2:types', 3600, function (): array {
            $groups = $this->get('types')['types'] ?? [];
            $types = [];
            foreach (is_array($groups) ? $groups : [] as $group => $entries) {
                foreach (is_array($entries) ? $entries : [] as $type => $info) {
                    if (is_array($info) && ! ($info['deprecated'] ?? false)) {
                        $types[] = ['value' => (string) $type, 'label' => (string) ($info['name'] ?? $type), 'group' => ucfirst((string) $group)];
                    }
                }
            }

            return $types;
        });
    }

    /** @return list<string> versions, newest first; releases only when the software has any */
    public function versions(string $type): array
    {
        return Cache::remember('server-splitter:mcjars:v2:'.$type, 3600, function () use ($type): array {
            $builds = $this->get('builds/'.$type)['builds'] ?? [];
            if (! is_array($builds)) {
                return [];
            }
            $releases = array_filter($builds, fn ($info): bool => is_array($info) && ($info['type'] ?? 'RELEASE') === 'RELEASE');
            $versions = $releases !== [] ? $releases : $builds;
            uasort($versions, fn ($a, $b): int => strcmp((string) ($b['created'] ?? ''), (string) ($a['created'] ?? '')));

            return array_map('strval', array_keys($versions));
        });
    }

    /** @return list<array{id: string, name: string}> builds, newest first, by MCJars build ID */
    public function builds(string $type, string $version): array
    {
        return Cache::remember('server-splitter:mcjars:v2:'.$type.':'.$version, 3600, function () use ($type, $version): array {
            $builds = $this->get('builds/'.$type.'/'.rawurlencode($version))['builds'] ?? [];
            $list = [];
            foreach (is_array($builds) ? $builds : [] as $build) {
                if (is_array($build) && isset($build['id'])) {
                    $list[] = ['id' => (string) $build['id'], 'name' => (string) ($build['name'] ?? '#'.($build['buildNumber'] ?? $build['id']))];
                }
            }
            usort($list, fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);

            return array_slice($list, 0, 50);
        });
    }

    /**
     * Checks a software, version, and build against MCJars. "latest" is always accepted for the
     * version and build; the egg's installer resolves it.
     *
     * @param  array<string, scalar|null>  $values
     *
     * @throws ValidationException
     */
    public function validate(array $values): void
    {
        $software = (string) ($values[self::VARIABLES['software']] ?? '');
        $version = (string) ($values[self::VARIABLES['version']] ?? 'latest');
        $build = (string) ($values[self::VARIABLES['build']] ?? 'latest');
        $field = fn (string $key): string => 'environment.'.self::VARIABLES[$key];

        if (preg_match('/^[A-Z0-9_]{1,20}$/', $software) !== 1) {
            throw ValidationException::withMessages([$field('software') => 'Choose a server software.']);
        }
        if ($version === 'latest' && $build !== 'latest') {
            throw ValidationException::withMessages([$field('build') => 'Choose a version before choosing a build.']);
        }

        try {
            if (! in_array($software, array_column($this->types(), 'value'), true)) {
                throw ValidationException::withMessages([$field('software') => 'MCJars does not offer this server software.']);
            }
            if ($version !== 'latest' && ! in_array($version, $this->versions($software), true)) {
                throw ValidationException::withMessages([$field('version') => 'MCJars has no such version of this software.']);
            }
            if ($build !== 'latest' && ! in_array($build, array_column($this->builds($software, $version), 'id'), true)) {
                throw ValidationException::withMessages([$field('build') => 'MCJars has no such build of this version.']);
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            // MCJars is unreachable: only "latest" is safe, since the installer resolves it.
            if ($version !== 'latest') {
                throw ValidationException::withMessages([$field('version') => 'The version list could not be checked with MCJars right now. Choose Latest or try again.']);
            }
        }
    }

    private function get(string $path): array
    {
        $data = Http::timeout(8)->acceptJson()->get(self::API.$path)->throw()->json();

        return is_array($data) ? $data : [];
    }
}
