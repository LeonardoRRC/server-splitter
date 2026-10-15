<?php

namespace UK\TheQuantum\ServerSplitter\Support;

use Pterodactyl\Services\Extensions\ExtensionRepository;

/** The settings saved from Admin → Server Splitter, merged over the defaults. */
class SplitterConfig
{
    public const ID = 'server-splitter';

    public const DEFAULTS = [
        // Sub-servers each main server can have.
        'max_splits' => 3,
        // 'all' eggs, or only the ones in allowed_eggs.
        'eggs' => 'all',
        'allowed_eggs' => [],
        // MiB, for each sub-server and for what the main server keeps.
        'min_memory' => 512,
        'min_disk' => 1024,
        // Split the CPU limit too; otherwise sub-servers get the main server's limit.
        'split_cpu' => true,
        'min_cpu' => 25,
        // Feature limits every sub-server gets.
        'database_limit' => 0,
        'backup_limit' => 0,
        'allocation_limit' => 0,
        // Ports users may claim besides the node's free ones, such as "25566-25600". Empty for none.
        'port_range' => '',
        'allow_subuser_sync' => true,
        // Let users change the server type of their main server and sub-servers.
        'allow_type_change' => true,
        // When a main server is deleted: delete its sub-servers, or keep them as ordinary servers.
        'on_parent_delete' => 'delete',
        // Suspend and unsuspend sub-servers with their main server.
        'mirror_suspension' => true,
        // The egg that gets MCJars software, version, and build lists: 'auto' finds the
        // official MCJars egg, 'off' turns the lists off, or an egg ID.
        'version_egg' => 'auto',
    ];

    public const PARENT_DELETE = ['delete', 'detach'];

    public function __construct(private readonly ExtensionRepository $extensions)
    {
    }

    public function all(): array
    {
        $saved = $this->extensions->settings(self::ID)->get('config');
        $saved = is_string($saved) ? json_decode($saved, true) : $saved;
        $saved = is_array($saved) ? $saved : [];
        // Settings saved before 1.0 turned the lists off with version_picker.
        if (! array_key_exists('version_egg', $saved) && ($saved['version_picker'] ?? true) === false) {
            $saved['version_egg'] = 'off';
        }

        return array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key];
    }

    public function save(array $config): array
    {
        $config = array_merge($this->all(), array_intersect_key($config, self::DEFAULTS));
        $this->extensions->settings(self::ID)->set('config', json_encode($config, JSON_THROW_ON_ERROR));

        return $this->all();
    }

    public function eggAllowed(int $eggId): bool
    {
        $config = $this->all();

        return $config['eggs'] === 'all' || in_array($eggId, array_map('intval', $config['allowed_eggs']), true);
    }

    /**
     * The configured port range, or null when there is none.
     *
     * @return array{0: int, 1: int}|null
     */
    public function portRange(): ?array
    {
        return self::parseRange((string) $this->get('port_range'));
    }

    /** @return array{0: int, 1: int}|null */
    public static function parseRange(string $range): ?array
    {
        if (preg_match('/^\s*(\d{1,5})\s*-\s*(\d{1,5})\s*$/', $range, $match) !== 1) {
            return null;
        }
        [$start, $end] = [(int) $match[1], (int) $match[2]];

        return $start >= 1024 && $end <= 65535 && $start <= $end && $end - $start <= 5000 ? [$start, $end] : null;
    }
}
