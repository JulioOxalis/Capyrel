<?php

namespace Julio\Capyrel\UI\Adapters;

use Illuminate\Support\Str;
use Julio\Capyrel\UI\Contracts\HasExtraFiles;
use Julio\Capyrel\UI\Contracts\UiAdapter;

class LivewireModernAdapter implements UiAdapter, HasExtraFiles
{
    public function name(): string
    {
        return 'livewire-modern';
    }

    public function capabilities(): array
    {
        return ['list', 'create', 'edit', 'show', 'realtime', 'search', 'pagination', 'bulk_delete'];
    }

    // ─────────────────────────────────────────────────────────────────
    // Thin Blade wrappers — all heavy lifting lives in the Livewire components
    // ─────────────────────────────────────────────────────────────────

    public function renderList(array $contract): string
    {
        $entity  = $contract['entity'];
        $kebab   = Str::kebab($entity);

        return <<<BLADE
        @extends('layouts.app')

        @section('content')
        <livewire:$kebab-table />
        @endsection
        BLADE;
    }

    public function renderCreate(array $contract): string
    {
        $entity  = $contract['entity'];
        $kebab   = Str::kebab($entity);

        return <<<BLADE
        @extends('layouts.app')

        @section('content')
        <livewire:$kebab-form />
        @endsection
        BLADE;
    }

    public function renderEdit(array $contract): string
    {
        $entity  = $contract['entity'];
        $kebab   = Str::kebab($entity);
        $camel   = Str::camel($entity);

        return <<<BLADE
        @extends('layouts.app')

        @section('content')
        <livewire:$kebab-edit :modelId="\$$camel->id" />
        @endsection
        BLADE;
    }

    public function renderShow(array $contract): string
    {
        $entity      = $contract['entity'];
        $kebab       = Str::kebab($entity);
        $camel       = Str::camel($entity);
        $titleField  = $contract['meta']['title_field'] ?? 'id';
        $descField   = $contract['meta']['description_field'] ?? null;
        $fields      = $contract['screens']['show']['sections'][0]['fields'] ?? [];
        $relations   = $contract['relations'] ?? [];

        $rows = '';
        foreach ($fields as $f) {
            $label = Str::title(str_replace('_', ' ', $f['key']));
            $rows .= "    <dt class=\"text-sm font-medium text-gray-500\">{$label}</dt>\n";
            $rows .= "    <dd class=\"mt-1 text-sm text-gray-900\">{{ \${$camel}->{$f['key']} }}</dd>\n";
        }

        $relationBlocks = '';
        foreach ($relations as $rel => $meta) {
            $relLabel = Str::title(str_replace('_', ' ', $rel));
            if (in_array($meta['type'], ['hasMany', 'belongsToMany', 'morphMany'])) {
                $relationBlocks .= <<<BLADE

        <div class="mt-8">
            <h3 class="text-lg font-semibold mb-3">{$relLabel}</h3>
            <ul class="divide-y divide-gray-200 rounded-lg border">
                @foreach (\${$camel}->{$rel} as \$rel{$relLabel})
                <li class="px-4 py-3 text-sm">{{ \$rel{$relLabel} }}</li>
                @endforeach
            </ul>
        </div>

        BLADE;
            }
        }

        $descBlock = $descField
            ? "<p class=\"mt-2 text-gray-600\">{{ \${$camel}->{$descField} }}</p>"
            : '';

        return <<<BLADE
        @extends('layouts.app')

        @section('content')
        <div class="max-w-4xl mx-auto py-8 px-4">

            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold">{{ \${$camel}->{$titleField} }}</h1>
                    {$descBlock}
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('{$kebab}.edit', \${$camel}) }}"
                       class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">Edit</a>
                    <form method="POST" action="{{ route('{$kebab}.destroy', \${$camel}) }}"
                          onsubmit="return confirm('Delete this {$entity}?')">
                        @csrf @method('DELETE')
                        <button class="px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700">Delete</button>
                    </form>
                </div>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 bg-white rounded-xl border p-6 shadow-sm">
        {$rows}
            </dl>
        {$relationBlocks}
        </div>
        @endsection
        BLADE;
    }

    // ─────────────────────────────────────────────────────────────────
    // Extra files: Livewire component classes + their Blade views
    // ─────────────────────────────────────────────────────────────────

    public function extraFiles(string $screen, array $contract): array
    {
        return match ($screen) {
            'list'   => $this->tableFiles($contract),
            'create' => $this->formFiles($contract),
            'edit'   => $this->editFiles($contract),
            'show'   => [],
            default  => [],
        };
    }

    // ── List / Table ──────────────────────────────────────────────────

    private function tableFiles(array $contract): array
    {
        $entity     = $contract['entity'];
        $kebab      = Str::kebab($entity);
        $pascal     = Str::studly($entity);
        $camel      = Str::camel($entity);
        $plural     = Str::plural($camel);
        $model      = "\\App\\Models\\{$pascal}";
        $titleField = $contract['meta']['title_field'] ?? 'id';
        $fields     = $contract['screens']['list']['fields'] ?? [];

        $publicProps = "    public string \$search = '';\n    public string \$sortField = '{$titleField}';\n    public bool \$sortAsc = true;";

        $columns = implode(', ', array_map(fn($f) => "'{$f['key']}'", array_slice($fields, 0, 5)));

        $thCells = '';
        foreach (array_slice($fields, 0, 5) as $f) {
            $label   = Str::title(str_replace('_', ' ', $f['key']));
            $key     = $f['key'];
            $thCells .= <<<HTML

                        <th wire:click="sort('{$key}')" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 cursor-pointer select-none hover:bg-gray-100">
                            {$label}
                            @if (\$sortField === '{$key}')
                                <span class="ml-1">{{ \$sortAsc ? '↑' : '↓' }}</span>
                            @endif
                        </th>

            HTML;
        }

        $tdCells = '';
        foreach (array_slice($fields, 0, 5) as $f) {
            $key     = $f['key'];
            $tdCells .= "                        <td class=\"px-4 py-3 text-sm text-gray-900\">{{ \$item->{$key} }}</td>\n";
        }

        $phpClass = <<<PHP
        <?php

        namespace App\Livewire;

        use {$model};
        use Livewire\Attributes\Layout;
        use Livewire\Component;
        use Livewire\WithPagination;

        #[Layout('layouts.app')]
        class {$pascal}Table extends Component
        {
        {$publicProps}

            /** @var int[] */
            public array \$selected = [];
            public bool \$selectAll = false;

            use WithPagination;

            public function sort(string \$field): void
            {
                if (\$this->sortField === \$field) {
                    \$this->sortAsc = ! \$this->sortAsc;
                } else {
                    \$this->sortField = \$field;
                    \$this->sortAsc   = true;
                }
                \$this->resetPage();
            }

            public function updatedSearch(): void
            {
                \$this->resetPage();
            }

            public function toggleSelectAll(): void
            {
                \$this->selectAll = ! \$this->selectAll;
                \$this->selected  = \$this->selectAll
                    ? {$pascal}::pluck('id')->toArray()
                    : [];
            }

            public function deleteSelected(): void
            {
                {$pascal}::whereIn('id', \$this->selected)->delete();
                \$this->selected  = [];
                \$this->selectAll = false;
            }

            public function delete(int \$id): void
            {
                {$pascal}::findOrFail(\$id)->delete();
            }

            public function render(): \\Illuminate\\View\\View
            {
                \$items = {$pascal}::query()
                    ->when(\$this->search, fn(\$q) => \$q->where(fn(\$q) =>
                        \$q->where('{$titleField}', 'like', '%'.\$this->search.'%')
                    ))
                    ->orderBy(\$this->sortField, \$this->sortAsc ? 'asc' : 'desc')
                    ->paginate(15);

                return view('livewire.{$kebab}-table', compact('items'));
            }
        }
        PHP;

        $bladeView = <<<BLADE
        <div class="space-y-4">
            {{-- toolbar --}}
            <div class="flex items-center justify-between">
                <input wire:model.live="search"
                       type="search"
                       placeholder="Search {$plural}…"
                       class="w-64 rounded-lg border px-3 py-2 text-sm shadow-sm focus:ring-2 focus:ring-indigo-500" />

                <div class="flex gap-2">
                    @if (count(\$selected))
                    <button wire:click="deleteSelected"
                            wire:confirm="Delete {{ count(\$selected) }} selected item(s)?"
                            class="px-3 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700">
                        Delete ({{ count(\$selected) }})
                    </button>
                    @endif
                    <a href="{{ route('{$kebab}.create') }}"
                       class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
                        + New {$entity}
                    </a>
                </div>
            </div>

            {{-- table --}}
            <div class="overflow-hidden rounded-xl border shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3">
                                <input type="checkbox" wire:click="toggleSelectAll" :checked="\$selectAll" class="rounded" />
                            </th>
        {$thCells}
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @forelse (\$items as \$item)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <input type="checkbox" wire:model="selected" value="{{ \$item->id }}" class="rounded" />
                            </td>
        {$tdCells}
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('{$kebab}.show', \$item) }}" class="text-indigo-600 text-sm hover:underline mr-2">View</a>
                                <a href="{{ route('{$kebab}.edit', \$item) }}" class="text-gray-600 text-sm hover:underline mr-2">Edit</a>
                                <button wire:click="delete({{ \$item->id }})"
                                        wire:confirm="Delete this {$entity}?"
                                        class="text-red-600 text-sm hover:underline">Delete</button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="999" class="px-4 py-8 text-center text-gray-400 text-sm">No {$plural} found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- pagination --}}
            <div>{{ \$items->links() }}</div>
        </div>
        BLADE;

        return [
            app_path("Livewire/{$pascal}Table.php")                                          => $phpClass,
            resource_path("views/livewire/{$kebab}-table.blade.php")                         => $bladeView,
        ];
    }

    // ── Create / Form ─────────────────────────────────────────────────

    private function formFiles(array $contract): array
    {
        $entity     = $contract['entity'];
        $kebab      = Str::kebab($entity);
        $pascal     = Str::studly($entity);
        $camel      = Str::camel($entity);
        $model      = "\\App\\Models\\{$pascal}";
        $fields     = $contract['screens']['create']['fields'] ?? [];
        $relations  = $contract['screens']['create']['relations'] ?? [];

        [$publicProps, $rules, $wireInputs] = $this->formParts($fields, $relations);

        $phpClass = <<<PHP
        <?php

        namespace App\Livewire;

        use {$model};
        use Livewire\Attributes\Layout;
        use Livewire\Attributes\Rule;
        use Livewire\Component;

        #[Layout('layouts.app')]
        class {$pascal}Form extends Component
        {
        {$publicProps}

            public function save(): void
            {
                \$this->validate();

                {$pascal}::create(\$this->only([{$rules['keys']}]));

                session()->flash('message', '{$entity} created.');
                \$this->redirect(route('{$kebab}.index'));
            }

            public function render(): \\Illuminate\\View\\View
            {
                return view('livewire.{$kebab}-form');
            }
        }
        PHP;

        $bladeView = <<<BLADE
        <div class="max-w-2xl mx-auto py-8 px-4">
            <h1 class="text-2xl font-bold mb-6">Create {$entity}</h1>

            @if (session('message'))
            <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-green-800 text-sm">{{ session('message') }}</div>
            @endif

            <form wire:submit="save" class="space-y-5 bg-white rounded-xl border p-6 shadow-sm">
                @csrf
        {$wireInputs}
                <div class="flex justify-end gap-2 pt-2">
                    <a href="{{ route('{$kebab}.index') }}"
                       class="px-4 py-2 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancel</a>
                    <button type="submit"
                            class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
                        Create {$entity}
                    </button>
                </div>
            </form>
        </div>
        BLADE;

        return [
            app_path("Livewire/{$pascal}Form.php")                                           => $phpClass,
            resource_path("views/livewire/{$kebab}-form.blade.php")                          => $bladeView,
        ];
    }

    // ── Edit ──────────────────────────────────────────────────────────

    private function editFiles(array $contract): array
    {
        $entity    = $contract['entity'];
        $kebab     = Str::kebab($entity);
        $pascal    = Str::studly($entity);
        $camel     = Str::camel($entity);
        $model     = "\\App\\Models\\{$pascal}";
        $fields    = $contract['screens']['create']['fields'] ?? [];
        $relations = $contract['screens']['create']['relations'] ?? [];

        [$publicProps, $rules, $wireInputs] = $this->formParts($fields, $relations);

        $phpClass = <<<PHP
        <?php

        namespace App\Livewire;

        use {$model};
        use Livewire\Attributes\Layout;
        use Livewire\Component;

        #[Layout('layouts.app')]
        class {$pascal}Edit extends Component
        {
            public int \$modelId;
            public {$pascal} \$model;
        {$publicProps}

            public function mount(int \$modelId): void
            {
                \$this->model   = {$pascal}::findOrFail(\$modelId);
                \$this->modelId = \$modelId;
                \$this->fill(\$this->model->only([{$rules['keys']}]));
            }

            public function save(): void
            {
                \$this->validate();

                \$this->model->update(\$this->only([{$rules['keys']}]));

                session()->flash('message', '{$entity} updated.');
                \$this->redirect(route('{$kebab}.show', \$this->model));
            }

            public function render(): \\Illuminate\\View\\View
            {
                return view('livewire.{$kebab}-edit');
            }
        }
        PHP;

        $bladeView = <<<BLADE
        <div class="max-w-2xl mx-auto py-8 px-4">
            <h1 class="text-2xl font-bold mb-6">Edit {$entity}</h1>

            @if (session('message'))
            <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-green-800 text-sm">{{ session('message') }}</div>
            @endif

            <form wire:submit="save" class="space-y-5 bg-white rounded-xl border p-6 shadow-sm">
                @csrf
        {$wireInputs}
                <div class="flex justify-end gap-2 pt-2">
                    <a href="{{ route('{$kebab}.show', \$model) }}"
                       class="px-4 py-2 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancel</a>
                    <button type="submit"
                            class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
        BLADE;

        return [
            app_path("Livewire/{$pascal}Edit.php")                                           => $phpClass,
            resource_path("views/livewire/{$kebab}-edit.blade.php")                          => $bladeView,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Build public property declarations, rules array, and wire:model inputs
     * from contract fields.
     *
     * @return array{0: string, 1: array{keys: string}, 2: string}
     */
    private function formParts(array $fields, array $relations): array
    {
        $props      = '';
        $keys       = [];
        $wireInputs = '';

        foreach ($fields as $f) {
            $key       = $f['key'];
            $label     = Str::title(str_replace('_', ' ', $key));
            $required  = ($f['nullable'] ?? true) ? '' : ' required';
            $type      = $this->inferInputType($f);

            if ($type === 'textarea') {
                $props      .= "    #[Rule('nullable|string')]\n    public string \${$key} = '';\n";
                $wireInputs .= <<<HTML

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{$label}</label>
                    <textarea wire:model.live="{$key}" rows="4"{$required}
                              class="w-full rounded-lg border px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 @error('{$key}') border-red-500 @enderror"></textarea>
                    @error('{$key}') <p class="mt-1 text-xs text-red-600">{{ \$message }}</p> @enderror
                </div>

                HTML;
            } elseif ($type === 'checkbox') {
                $props      .= "    #[Rule('nullable|boolean')]\n    public bool \${$key} = false;\n";
                $wireInputs .= <<<HTML

                <div class="flex items-center gap-2">
                    <input wire:model="{$key}" type="checkbox" id="{$key}"
                           class="rounded border-gray-300 text-indigo-600" />
                    <label for="{$key}" class="text-sm font-medium text-gray-700">{$label}</label>
                </div>

                HTML;
            } else {
                $htmlType  = $type === 'email' ? 'email' : ($type === 'number' ? 'number' : 'text');
                $rule      = $type === 'email' ? 'nullable|email' : 'nullable|string';
                $props     .= "    #[Rule('{$rule}')]\n    public string \${$key} = '';\n";
                $wireInputs .= <<<HTML

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{$label}</label>
                    <input wire:model.live="{$key}" type="{$htmlType}"{$required}
                           class="w-full rounded-lg border px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 @error('{$key}') border-red-500 @enderror" />
                    @error('{$key}') <p class="mt-1 text-xs text-red-600">{{ \$message }}</p> @enderror
                </div>

                HTML;
            }

            $keys[] = "'{$key}'";
        }

        foreach ($relations as $rel) {
            $relName   = $rel['relation'];
            $fk        = $rel['foreign_key'];
            $relPascal = Str::studly($relName);
            $label     = Str::title(str_replace('_', ' ', $relName));

            $props .= "    #[Rule('nullable|integer|exists:{$relName}s,id')]\n    public ?int \${$fk} = null;\n";
            $wireInputs .= <<<HTML

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{$label}</label>
                <select wire:model="{$fk}"
                        class="w-full rounded-lg border px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 @error('{$fk}') border-red-500 @enderror">
                    <option value="">— Select {$label} —</option>
                    @foreach (\\App\\Models\\{$relPascal}::orderBy('id')->limit(200)->get() as \$opt)
                    <option value="{{ \$opt->id }}">{{ \$opt }}</option>
                    @endforeach
                </select>
                @error('{$fk}') <p class="mt-1 text-xs text-red-600">{{ \$message }}</p> @enderror
            </div>

            HTML;

            $keys[] = "'{$fk}'";
        }

        return [$props, ['keys' => implode(', ', $keys)], $wireInputs];
    }

    private function inferInputType(array $field): string
    {
        $key  = strtolower($field['key']);
        $type = strtolower($field['type'] ?? '');

        if (str_contains($key, 'email'))                     return 'email';
        if (str_contains($key, 'password'))                  return 'password';
        if (in_array($type, ['text', 'longtext', 'mediumtext', 'json'])) return 'textarea';
        if (str_contains($key, 'content') || str_contains($key, 'body') || str_contains($key, 'description'))
                                                             return 'textarea';
        if (in_array($type, ['tinyint', 'boolean', 'bool'])) return 'checkbox';
        if (in_array($type, ['int', 'integer', 'bigint', 'float', 'double', 'decimal'])) return 'number';

        return 'text';
    }
}
