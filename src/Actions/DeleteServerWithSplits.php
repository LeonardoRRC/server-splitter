<?php

namespace UK\TheQuantum\ServerSplitter\Actions;

use Pterodactyl\Contracts\Servers\DeletesServers;
use Pterodactyl\Models\Server;
use UK\TheQuantum\ServerSplitter\Services\SplitManager;

/**
 * Wraps the Panel's server deletion, wherever it starts (the client area, the admin area, or
 * the API): a deleted sub-server's resources go back to its main server, and a deleted main
 * server takes its sub-servers with it when configured to.
 */
final class DeleteServerWithSplits implements DeletesServers
{
    private bool $force = false;

    /**
     * The split manager is resolved on first use: it needs the Panel's server creation,
     * which itself depends on server deletion, so building it here would loop.
     *
     * @param  \Closure(): SplitManager  $splits
     */
    public function __construct(private readonly DeletesServers $inner, private readonly \Closure $splits)
    {
    }

    public function withForce(bool $bool = true): self
    {
        $this->force = $bool;

        return $this;
    }

    public function delete(Server $server): void
    {
        $force = $this->force;
        $this->force = false;

        $splits = ($this->splits)();
        $splits->beforeDelete($server, $this, $force);
        // Read before deleting: the link row goes with the server.
        $link = $splits->linkFor($server);

        $this->inner->withForce($force)->delete($server);

        if ($link !== null) {
            $splits->release($link);
        }
    }
}
