<?php

namespace UK\TheQuantum\ServerSplitter;

use Pterodactyl\Contracts\Servers\DeletesServers;
use Pterodactyl\Contracts\Servers\TogglesServerSuspension;
use Pterodactyl\Extensions\ExtensionProvider;
use UK\TheQuantum\ServerSplitter\Actions\DeleteServerWithSplits;
use UK\TheQuantum\ServerSplitter\Actions\ToggleSuspensionWithSplits;
use UK\TheQuantum\ServerSplitter\Services\SplitManager;
use UK\TheQuantum\ServerSplitter\Support\SplitterConfig;

class ServerSplitterProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // /api/client/servers/{server}/extensions/server-splitter/... and
        // /api/admin/extensions/server-splitter/...
        $this->registerApiRoutes();

        $this->registerPermissions('Split the server into sub-servers.', [
            'read' => 'Allows a user to view the server\'s sub-servers.',
            'manage' => 'Allows a user to create, resize, change the type of, and delete sub-servers.',
        ]);

        // Deleting a sub-server anywhere gives its resources back to its main server.
        $this->wrapAction(DeletesServers::class, fn (DeletesServers $inner): DeletesServers => new DeleteServerWithSplits($inner, fn (): SplitManager => $this->app->make(SplitManager::class)));
        $this->wrapAction(TogglesServerSuspension::class, fn (TogglesServerSuspension $inner): TogglesServerSuspension => new ToggleSuspensionWithSplits($inner, $this->app->make(SplitterConfig::class)));
    }
}
