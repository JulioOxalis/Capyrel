<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\FrameworkDetector;

class LivewireGenerator
{
    public function __construct(private FrameworkDetector $framework) {}

    // ── Table component ───────────────────────────────────────────────────────

    public function generateTableComponent(string $model, array $columns): string
    {
        $plural      = Str::camel(Str::plural($model));
        $variable    = Str::camel($model);
        $searchCols  = $this->searchableColumns($columns);
        $sortCols    = $this->sortableColumns($columns);
        $displayCols = $this->displayColumns($columns);
        $searchWhere = $this->searchWhereClauses($searchCols);

        return <<<PHP
<?php

namespace App\Livewire;

use App\Models\\{$model};
use Livewire\Component;
use Livewire\WithPagination;

class {$model}Table extends Component
{
    use WithPagination;

    public string \$search      = '';
    public string \$sortField   = 'created_at';
    public string \$sortDirection = 'desc';
    public int    \$perPage     = 15;

    public function updatedSearch(): void
    {
        \$this->resetPage();
    }

    public function sortBy(string \$field): void
    {
        \$this->sortDirection = \$this->sortField === \$field && \$this->sortDirection === 'asc'
            ? 'desc'
            : 'asc';

        \$this->sortField = \$field;
        \$this->resetPage();
    }

    public function delete(int \$id): void
    {
        {$model}::findOrFail(\$id)->delete();
        session()->flash('success', '{$model} deleted.');
    }

    public function render()
    {
        \${$plural} = {$model}::query()
{$searchWhere}
            ->orderBy(\$this->sortField, \$this->sortDirection)
            ->paginate(\$this->perPage);

        return view('livewire.{$variable}-table', compact('{$plural}'));
    }
}
PHP;
    }

    public function generateTableBlade(string $model, array $columns): string
    {
        $plural   = Str::camel(Str::plural($model));
        $singular = Str::camel($model);
        $route    = Str::kebab(Str::plural($model));
        $cols     = $this->displayColumns($columns);
        $headers  = $this->tableHeaders($model, $cols);
        $cells    = $this->tableCells($singular, $cols);
        $tw       = $this->framework->isTailwind();

        if ($tw) {
            return $this->twTableBlade($model, $plural, $singular, $route, $headers, $cells);
        }
        return $this->bsTableBlade($model, $plural, $singular, $route, $headers, $cells);
    }

    // ── Form component ────────────────────────────────────────────────────────

    public function generateFormComponent(string $model, array $columns): string
    {
        $variable  = Str::camel($model);
        $route     = Str::kebab(Str::plural($model));
        $props     = $this->componentProps($columns);
        $rules     = $this->componentRules($columns);
        $fillArray = $this->fillArray($columns);

        return <<<PHP
<?php

namespace App\Livewire;

use App\Models\\{$model};
use Livewire\Component;

class {$model}Form extends Component
{
    public ?{$model} \${$variable} = null;

{$props}

    protected function rules(): array
    {
        return [
{$rules}
        ];
    }

    public function mount(?{$model} \${$variable} = null): void
    {
        \$this->{$variable} = \${$variable};

        if (\${$variable}) {
{$fillArray}
        }
    }

    public function save(): void
    {
        \$validated = \$this->validate();

        if (\$this->{$variable}) {
            \$this->{$variable}->update(\$validated);
            session()->flash('success', '{$model} updated.');
        } else {
            {$model}::create(\$validated);
            session()->flash('success', '{$model} created.');
            \$this->reset();
        }

        \$this->dispatch('{$variable}-saved');
    }

    public function render()
    {
        return view('livewire.{$variable}-form');
    }
}
PHP;
    }

    public function generateFormBlade(string $model, array $columns): string
    {
        $variable = Str::camel($model);
        $inputs   = $this->livewireInputs($columns);
        $tw       = $this->framework->isTailwind();

        if ($tw) return $this->twFormBlade($variable, $model, $inputs);
        return $this->bsFormBlade($variable, $model, $inputs);
    }

    // ── Templates ─────────────────────────────────────────────────────────────

    private function twTableBlade(string $model, string $plural, string $singular, string $route, string $headers, string $cells): string
    {
        return <<<BLADE
<div>
    <div class="flex items-center justify-between mb-4">
        <input wire:model.live.debounce.300ms="search"
               type="search"
               placeholder="Search {$model}s..."
               class="block w-64 rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
        <a href="{{ route('{$route}.create') }}"
           class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
            + New {$model}
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white shadow-sm rounded-xl overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
{$headers}
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                @forelse(\${$plural} as \${$singular})
                <tr class="hover:bg-gray-50">
{$cells}
                    <td class="px-6 py-4 text-right text-sm space-x-3">
                        <a href="{{ route('{$route}.edit', \${$singular}) }}" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                        <button wire:click="delete({{ \${$singular}->id }})"
                                wire:confirm="Delete this {$model}?"
                                class="text-red-500 hover:text-red-700">Delete</button>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="99" class="px-6 py-8 text-center text-gray-400">
                        No {$model}s found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-6 py-4 border-t">
            {{ \${$plural}->links() }}
        </div>
    </div>
</div>
BLADE;
    }

    private function bsTableBlade(string $model, string $plural, string $singular, string $route, string $headers, string $cells): string
    {
        return <<<BLADE
<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <input wire:model.live.debounce.300ms="search"
               type="search" class="form-control w-25"
               placeholder="Search...">
        <a href="{{ route('{$route}.create') }}" class="btn btn-primary">+ New {$model}</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
{$headers}
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse(\${$plural} as \${$singular})
                    <tr>
{$cells}
                        <td class="text-end">
                            <a href="{{ route('{$route}.edit', \${$singular}) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <button wire:click="delete({{ \${$singular}->id }})"
                                    wire:confirm="Delete this {$model}?"
                                    class="btn btn-sm btn-outline-danger">Delete</button>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="99" class="text-center text-muted py-4">No {$model}s found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white">{{ \${$plural}->links() }}</div>
    </div>
</div>
BLADE;
    }

    private function twFormBlade(string $variable, string $model, string $inputs): string
    {
        return <<<BLADE
<div>
    @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
             class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm">
            {{ session('success') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-5">

{$inputs}

        <div class="flex justify-end">
            <button type="submit"
                    class="px-6 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                <span wire:loading.remove>Save {$model}</span>
                <span wire:loading>Saving...</span>
            </button>
        </div>
    </form>
</div>
BLADE;
    }

    private function bsFormBlade(string $variable, string $model, string $inputs): string
    {
        return <<<BLADE
<div>
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form wire:submit="save">

{$inputs}

        <button type="submit" class="btn btn-primary">
            <span wire:loading.remove>Save {$model}</span>
            <span wire:loading>Saving...</span>
        </button>
    </form>
</div>
BLADE;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function searchableColumns(array $columns): array
    {
        $searchable = ['name', 'title', 'email', 'body', 'content', 'description', 'slug', 'username'];
        return array_filter($columns, fn($c) => in_array($c['name'], $searchable) ||
            in_array($c['type_name'] ?? '', ['varchar', 'text', 'string', 'character varying']));
    }

    private function sortableColumns(array $columns): array
    {
        $skip = ['password', 'remember_token', 'body', 'content', '_id'];
        return array_filter($columns, fn($c) => !in_array($c['name'], $skip));
    }

    private function displayColumns(array $columns): array
    {
        $skip = ['password', 'remember_token', 'two_factor_secret', '_id', 'deleted_at'];
        return array_values(array_filter(array_slice($columns, 0, 5), fn($c) => !in_array($c['name'], $skip)));
    }

    private function searchWhereClauses(array $cols): string
    {
        if (empty($cols)) return "            ->when(\$this->search, fn(\$q) => \$q->where('name', 'like', '%'.\$this->search.'%'))";

        $lines = [];
        foreach (array_slice($cols, 0, 3) as $col) {
            $lines[] = "                ->orWhere('{$col['name']}', 'like', '%'.\$this->search.'%')";
        }

        return "            ->when(\$this->search, function(\$q) {\n                \$q->where(function(\$q) {\n" . implode("\n", $lines) . "\n                });\n            })";
    }

    private function tableHeaders(string $model, array $cols): string
    {
        $tw = $this->framework->isTailwind();
        return collect($cols)->map(function ($col) use ($tw, $model) {
            $label = Str::headline($col['name']);
            $field = $col['name'];
            if ($tw) {
                return "                    <th scope=\"col\" wire:click=\"sortBy('{$field}')\" class=\"px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:text-gray-700\">{$label} <span wire:loading.remove>↕</span></th>";
            }
            return "                    <th wire:click=\"sortBy('{$field}')\" style=\"cursor:pointer\">{$label}</th>";
        })->implode("\n");
    }

    private function tableCells(string $singular, array $cols): string
    {
        $tw = $this->framework->isTailwind();
        return collect($cols)->map(function ($col) use ($singular, $tw) {
            $name = $col['name'];
            if ($tw) {
                return "                    <td class=\"px-6 py-4 whitespace-nowrap text-sm text-gray-900\">{{ \${$singular}->{$name} ?? '—' }}</td>";
            }
            return "                    <td>{{ \${$singular}->{$name} ?? '—' }}</td>";
        })->implode("\n");
    }

    private function componentProps(array $columns): string
    {
        $skip = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        return collect($columns)
            ->filter(fn($c) => !in_array($c['name'], $skip))
            ->map(fn($c) => "    public string \${$c['name']} = '';")
            ->implode("\n");
    }

    private function componentRules(array $columns): string
    {
        $skip = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        return collect($columns)
            ->filter(fn($c) => !in_array($c['name'], $skip))
            ->map(function ($c) {
                $nullable = ($c['nullable'] ?? false) ? "'nullable'" : "'required'";
                return "            '{$c['name']}' => [{$nullable}, 'string'],";
            })
            ->implode("\n");
    }

    private function fillArray(array $columns): string
    {
        $skip = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        $variable = '$this->' ;
        return collect($columns)
            ->filter(fn($c) => !in_array($c['name'], $skip))
            ->map(fn($c) => "            \$this->{$c['name']} = \${$this->lcFirst('model')}->{$c['name']} ?? '';")
            ->implode("\n");
    }

    private function livewireInputs(array $columns): string
    {
        $skip  = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        $lines = [];
        $tw    = $this->framework->isTailwind();

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;

            $label = Str::headline($name);
            $type  = in_array($col['type_name'] ?? '', ['text', 'longtext', 'mediumtext']) ? 'textarea' : 'input';

            if ($tw) {
                if ($type === 'textarea') {
                    $lines[] = <<<BLADE
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{$label}</label>
                            <textarea wire:model="{$name}" rows="4"
                                      class="block w-full rounded-lg border-gray-300 shadow-sm text-sm @error('{$name}') border-red-400 @enderror"></textarea>
                            @error('{$name}') <p class="mt-1 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
                } else {
                    $lines[] = <<<BLADE
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{$label}</label>
                            <input type="text" wire:model="{$name}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm text-sm @error('{$name}') border-red-400 @enderror">
                            @error('{$name}') <p class="mt-1 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
                }
            } else {
                $tag = $type === 'textarea'
                    ? "<textarea wire:model=\"{$name}\" class=\"form-control @error('{$name}') is-invalid @enderror\" rows=\"4\"></textarea>"
                    : "<input type=\"text\" wire:model=\"{$name}\" class=\"form-control @error('{$name}') is-invalid @enderror\">";
                $lines[] = <<<BLADE
                        <div class="mb-3">
                            <label class="form-label fw-semibold">{$label}</label>
                            {$tag}
                            @error('{$name}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                        </div>
BLADE;
            }
        }

        return implode("\n\n", $lines);
    }

    private function lcFirst(string $s): string
    {
        return lcfirst($s);
    }
}
