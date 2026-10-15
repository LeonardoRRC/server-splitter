<?php

namespace UK\TheQuantum\ServerSplitter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Knuckles\Scribe\Attributes\Endpoint;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Egg;
use UK\TheQuantum\ServerSplitter\Models\SplitLink;
use UK\TheQuantum\ServerSplitter\Services\MinecraftVersions;
use UK\TheQuantum\ServerSplitter\Support\SplitterConfig;

/** Admin API under /api/admin/extensions/server-splitter. Root administrators only. */
class AdminController
{
    public function __construct(private readonly SplitterConfig $config)
    {
    }

    #[Endpoint('Get the Server Splitter settings', 'The settings, every egg grouped by category, and usage counts.')]
    public function show(): JsonResponse
    {
        return new JsonResponse($this->payload());
    }

    #[Endpoint('Update the Server Splitter settings')]
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'max_splits' => ['required', 'integer', 'min:1', 'max:50'],
            'eggs' => ['required', Rule::in(['all', 'selected'])],
            'allowed_eggs' => ['present', 'array'],
            'allowed_eggs.*' => ['integer', 'exists:eggs,id'],
            'min_memory' => ['required', 'integer', 'min:64', 'max:1048576'],
            'min_disk' => ['required', 'integer', 'min:64', 'max:10485760'],
            'split_cpu' => ['required', 'boolean'],
            'min_cpu' => ['required', 'integer', 'min:1', 'max:10000'],
            'database_limit' => ['required', 'integer', 'min:0', 'max:100'],
            'backup_limit' => ['required', 'integer', 'min:0', 'max:100'],
            'allocation_limit' => ['required', 'integer', 'min:0', 'max:100'],
            'port_range' => ['present', 'nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && SplitterConfig::parseRange($value) === null) {
                    $fail('Enter a range such as 25566-25600, between 1024 and 65535 and at most 5,000 ports wide.');
                }
            }],
            'allow_subuser_sync' => ['required', 'boolean'],
            'allow_type_change' => ['required', 'boolean'],
            'on_parent_delete' => ['required', Rule::in(SplitterConfig::PARENT_DELETE)],
            'mirror_suspension' => ['required', 'boolean'],
            'version_egg' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! in_array($value, ['auto', 'off'], true) && ! (is_int($value) && Egg::query()->whereKey($value)->exists())) {
                    $fail('Choose Automatic, Off, or an egg.');
                }
            }],
        ]);
        $data['port_range'] = trim((string) ($data['port_range'] ?? ''));
        $data['allowed_eggs'] = array_values(array_unique(array_map('intval', $data['allowed_eggs'])));

        $this->config->save($data);
        Activity::event('admin:server-splitter.update')->log();

        return new JsonResponse($this->payload());
    }

    private function payload(): array
    {
        return [
            'config' => $this->config->all(),
            'eggs' => Egg::query()->with('tags')->orderBy('name')->get()->map(fn (Egg $egg): array => [
                'id' => $egg->id,
                'name' => $egg->name,
                'category' => $egg->tags->first()?->name ?? 'Other',
            ])->all(),
            'mcjars_egg' => ($id = Egg::query()->where('author', MinecraftVersions::EGG_AUTHOR)->orderBy('id')->value('id')) === null ? null : (int) $id,
            'stats' => [
                'sub_servers' => SplitLink::query()->count(),
                'main_servers' => SplitLink::query()->distinct()->count('parent_id'),
            ],
        ];
    }
}
