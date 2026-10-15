<?php

namespace UK\TheQuantum\ServerSplitter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;

/**
 * A sub-server and the main server it was split from.
 *
 * @property int $id
 * @property int $parent_id
 * @property int $server_id
 * @property int $memory MiB taken from the main server
 * @property int $disk MiB taken from the main server
 * @property int $cpu percent taken from the main server, 0 when CPU is not split
 */
class SplitLink extends Model
{
    protected $table = 'server_splitter_links';

    protected $fillable = ['parent_id', 'server_id', 'memory', 'disk', 'cpu'];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'server_id' => 'integer', 'memory' => 'integer', 'disk' => 'integer', 'cpu' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'parent_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
