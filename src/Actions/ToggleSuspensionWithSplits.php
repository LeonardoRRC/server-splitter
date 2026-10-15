<?php

namespace UK\TheQuantum\ServerSplitter\Actions;

use Pterodactyl\Contracts\Servers\TogglesServerSuspension;
use Pterodactyl\Models\Server;
use UK\TheQuantum\ServerSplitter\Models\SplitLink;
use UK\TheQuantum\ServerSplitter\Support\SplitterConfig;

/** Suspends and unsuspends a main server's sub-servers with it, when configured to. */
final class ToggleSuspensionWithSplits implements TogglesServerSuspension
{
    public function __construct(private readonly TogglesServerSuspension $inner, private readonly SplitterConfig $config)
    {
    }

    public function toggle(Server $server, string $action = self::ACTION_SUSPEND): void
    {
        $this->inner->toggle($server, $action);

        if (! $this->config->get('mirror_suspension')) {
            return;
        }

        $suspending = $action === self::ACTION_SUSPEND;
        foreach (SplitLink::query()->where('parent_id', $server->id)->with('server')->get() as $link) {
            $child = $link->server;
            if ($child instanceof Server && $child->isSuspended() !== $suspending) {
                rescue(fn () => $this->inner->toggle($child, $action));
            }
        }
    }
}
