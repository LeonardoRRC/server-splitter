# Server Splitter

By **The Quantum Development** · `uk.thequantum.server-splitter`

Lets users split a server's memory, disk, and CPU into sub-servers, from a **Server Split** page on the server. An administrator decides which eggs are allowed, how many sub-servers each server can have, and the minimum each one gets.

## For users

The **Server Split** page lists the main server and its sub-servers, and has a form to create a new one: a name, a server type, memory, disk, and CPU when CPU is split. Each sub-server gets a random free port on the main server's IP, and every egg setting takes its default value.

For the official MCJars egg ("Java Minecraft", by rjansengd@gmail.com), users also choose the server software (Paper, Purpur, Fabric, Velocity, and the rest), the Minecraft version, and the build, from MCJars' lists.

Each sub-server's menu can:

- **Edit memory and disk.** Lowering a value gives the difference back to the main server; raising it takes it from there.
- **Set as main server.** The two swap roles; no resources move and nothing restarts.
- **Change server type,** with an optional reinstall. The main server's menu has it too.
- **Delete** the sub-server, which gives its resources back to the main server.

Users need the Server Splitter subuser permissions to view (`read`) or manage (`manage`) sub-servers. Server owners have both.

## For administrators

**Admin → Server Splitter** sets:

- **Limits:** sub-servers per server, and the minimum memory, disk, and CPU for each sub-server and for what the main server keeps.
- **Split the CPU limit:** sub-servers take part of the main server's CPU limit, or share the same limit.
- **Every sub-server gets:** database, backup, and extra port limits.
- **Ports:** an optional range used when the node has no free allocations on the main server's IP.
- **Server types:** every egg, or a selection grouped by category.
- **Behavior:** whether deleting a main server deletes its sub-servers or keeps them as ordinary servers; suspending sub-servers with their main server; copying subusers; changing server types; and the egg that gets MCJars software lists.

## How it works

Sub-servers are created, resized, and deleted through the Panel's own server actions, so Wings, validation, events, and the activity log work as usual. A sub-server's share always comes out of its main server, and the two never hold more than the main server had, even while a change is in progress or when several requests arrive at once. Deleting a sub-server from anywhere, including the admin area and the API, gives its resources back.

Changes to memory, disk, and CPU apply when each server restarts.

## Installing

Upload `server-splitter-1.0.0.pteroext` in **Admin → Extensions**, or from the Panel root:

```bash
php artisan p:extension:install server-splitter-1.0.0.pteroext --enable
```

Enabling it creates the `server_splitter_links` table.

## Development

```bash
npm install
npm run typecheck
npm test
npm run build
```
