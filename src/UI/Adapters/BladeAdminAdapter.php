<?php

namespace Julio\Capyrel\UI\Adapters;

use Illuminate\Support\Str;
use Julio\Capyrel\UI\Contracts\UiAdapter;

/**
 * blade-admin adapter — dense, data-heavy admin panel UI.
 *
 * list   → compact table with bulk-select, filter bar, sortable headers, row-action dropdown
 * create → two-column grouped form with labeled sections
 * show   → sidebar layout: detail panel left, stats + relations right
 */
class BladeAdminAdapter implements UiAdapter
{
    private string $stamp = "{{-- capyrel:ui:admin — remove with: php artisan capyrel:clean --views --}}\n";

    public function name(): string
    {
        return 'blade-admin';
    }

    public function capabilities(): array
    {
        return ['list', 'create', 'show', 'grid', 'filters', 'bulk_actions'];
    }

    // ── List — admin data table ───────────────────────────────────────────────

    public function renderList(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['list'] ?? [];
        $fields    = $screen['fields'] ?? [];
        $relPrev   = $screen['relations_preview'] ?? [];
        $meta      = $contract['meta'] ?? [];
        $route     = Str::kebab(Str::plural($entity));
        $plural    = Str::camel(Str::plural($entity));
        $singular  = Str::camel($entity);
        $title     = Str::headline(Str::plural($entity));

        $headers = $this->adminHeaders($fields, $relPrev, $route);
        $cells   = $this->adminCells($singular, $fields, $relPrev);
        $colspan = count($fields) + count($relPrev) + 2; // +2 for checkbox + actions

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{$title}</h2>
                <p class="text-sm text-gray-500 mt-0.5">Manage all {$title} records</p>
            </div>
            <a href="{{ route('{$route}.create') }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add {$entity}
            </a>
        </div>
    </x-slot>

    <div class="py-6 max-w-full px-4 sm:px-6 lg:px-8">

        {{-- Filter bar --}}
        <form method="GET" class="mb-4 flex items-center gap-3">
            <div class="relative flex-1 max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search {$title}…"
                       class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <button type="submit" class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition">Filter</button>
            @if(request('search'))
            <a href="{{ route('{$route}.index') }}" class="text-sm text-gray-500 hover:underline">Clear</a>
            @endif
        </form>

        {{-- Bulk action bar (Alpine-driven) --}}
        <div x-data="{ selected: [], allChecked: false }" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

            {{-- Bulk toolbar --}}
            <div x-show="selected.length > 0" class="bg-indigo-50 border-b border-indigo-200 px-4 py-2 flex items-center gap-3">
                <span class="text-sm font-medium text-indigo-700" x-text="selected.length + ' selected'"></span>
                <form method="POST" action="{{ route('{$route}.bulk-destroy') }}" onsubmit="return confirm('Delete selected?')">
                    @csrf @method('DELETE')
                    <template x-for="id in selected"><input type="hidden" name="ids[]" :value="id"></template>
                    <button type="submit" class="px-3 py-1 bg-red-100 text-red-700 text-xs font-medium rounded hover:bg-red-200 transition">Delete selected</button>
                </form>
            </div>

            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-medium">
                    <tr>
                        <th class="w-10 px-4 py-3">
                            <input type="checkbox" x-model="allChecked"
                                   @change="allChecked ? selected = Array.from(document.querySelectorAll('[data-id]')).map(el => el.dataset.id) : selected = []"
                                   class="rounded border-gray-300 text-indigo-600">
                        </th>
{$headers}
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse (\${$plural} as \${$singular})
                    <tr class="hover:bg-gray-50 transition" x-bind:class="selected.includes(String(\${$singular}->id)) ? 'bg-indigo-50' : ''">
                        <td class="px-4 py-2.5">
                            <input type="checkbox" :value="\${$singular}->id" data-id="{{ \${$singular}->id }}"
                                   x-model="selected"
                                   class="rounded border-gray-300 text-indigo-600">
                        </td>
{$cells}
                        <td class="px-4 py-2.5 text-right">
                            <div x-data="{ open: false }" class="relative inline-block text-left">
                                <button @click="open = !open" class="p-1.5 rounded hover:bg-gray-100 text-gray-500">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z"/></svg>
                                </button>
                                <div x-show="open" @click.outside="open = false"
                                     class="absolute right-0 mt-1 w-36 bg-white rounded-lg shadow-lg border border-gray-100 z-10 py-1">
                                    <a href="{{ route('{$route}.show', \${$singular}) }}" class="block px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">View</a>
                                    <a href="{{ route('{$route}.edit', \${$singular}) }}" class="block px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">Edit</a>
                                    <form method="POST" action="{{ route('{$route}.destroy', \${$singular}) }}" onsubmit="return confirm('Delete?')">
                                        @csrf @method('DELETE')
                                        <button class="w-full text-left px-3 py-1.5 text-xs text-red-600 hover:bg-red-50">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="{$colspan}" class="px-4 py-12 text-center text-gray-400">No {$title} found.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
                <p class="text-xs text-gray-500">{{ \${$plural}->total() }} total records</p>
                <div>{{ \${$plural}->withQueryString()->links() }}</div>
            </div>

        </div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Create — two-column form ──────────────────────────────────────────────

    public function renderCreate(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['create'] ?? [];
        $fields    = $screen['fields'] ?? [];
        $relations = $screen['relations'] ?? [];
        $route     = Str::kebab(Str::plural($entity));

        // Split fields into two columns: halves
        $left  = array_slice($fields, 0, (int) ceil(count($fields) / 2));
        $right = array_slice($fields, (int) ceil(count($fields) / 2));

        $leftInputs  = $this->twoColInputs($left);
        $rightInputs = $this->twoColInputs($right);
        $relInputs   = $this->adminRelationInputs($relations);

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('{$route}.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h2 class="text-lg font-semibold text-gray-900">Create {$entity}</h2>
        </div>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('{$route}.store') }}" enctype="multipart/form-data">
            @csrf

            {{-- Main fields section --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-4">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Details</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-4">
{$leftInputs}
                    </div>
                    <div class="space-y-4">
{$rightInputs}
                    </div>
                </div>
            </div>

            @if(!empty(\$relations))
            {{-- Relations section --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-4">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Associations</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
{$relInputs}
                </div>
            </div>
            @endif

            {{-- Actions --}}
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('{$route}.index') }}" class="px-4 py-2 text-sm text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition">Cancel</a>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition">
                    Create {$entity}
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Edit — two-column (pre-filled) ───────────────────────────────────────

    public function renderEdit(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['create'] ?? [];
        $fields    = $screen['fields'] ?? [];
        $relations = $screen['relations'] ?? [];
        $meta      = $contract['meta'] ?? [];
        $route     = Str::kebab(Str::plural($entity));
        $singular  = Str::camel($entity);
        $titleField = $meta['title_field'] ?? 'id';

        $left  = array_slice($fields, 0, (int) ceil(count($fields) / 2));
        $right = array_slice($fields, (int) ceil(count($fields) / 2));

        $leftInputs  = $this->twoColInputsEdit($left, $singular);
        $rightInputs = $this->twoColInputsEdit($right, $singular);
        $relInputs   = $this->adminRelationInputsEdit($relations, $singular);

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('{$route}.show', \${$singular}) }}" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h2 class="text-lg font-semibold text-gray-900">Edit {$entity}: {{ \${$singular}->{$titleField} }}</h2>
        </div>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('{$route}.update', \${$singular}) }}" enctype="multipart/form-data">
            @csrf
            @method('PATCH')

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-4">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Details</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-4">
{$leftInputs}
                    </div>
                    <div class="space-y-4">
{$rightInputs}
                    </div>
                </div>
            </div>

            @if(!empty(\$relations))
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-4">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Associations</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
{$relInputs}
                </div>
            </div>
            @endif

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('{$route}.show', \${$singular}) }}" class="px-4 py-2 text-sm text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition">Cancel</a>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition">
                    Update {$entity}
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Show — sidebar layout ─────────────────────────────────────────────────

    public function renderShow(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['show'] ?? [];
        $relations = $screen['relations'] ?? [];
        $actions   = $screen['actions'] ?? ['edit', 'delete'];
        $sections  = $screen['sections'] ?? ['main'];
        $meta      = $contract['meta'] ?? [];
        $route     = Str::kebab(Str::plural($entity));
        $singular  = Str::camel($entity);
        $titleField = $meta['title_field'] ?? 'id';

        $relPanels   = $this->adminRelationPanels($singular, $relations);
        $activityPanel = in_array('activity', $sections) ? $this->adminActivityPanel($singular) : '';
        $actionButtons = $this->adminActionButtons($singular, $route, $entity, $actions);

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('{$route}.index') }}" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <h2 class="text-lg font-semibold text-gray-900">{$entity} #{{ \${$singular}->id }}</h2>
            </div>
            <div class="flex gap-2">
                {$actionButtons}
            </div>
        </div>
    </x-slot>

    <div class="py-6 px-4 sm:px-6 lg:px-8">
        <div class="max-w-6xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Left: main detail --}}
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Record Details</h3>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                        @foreach (\${$singular}->getAttributes() as \$key => \$value)
                            @if (!in_array(\$key, ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes']))
                            <div class="border-b border-gray-50 pb-3">
                                <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-0.5">{{ str_replace('_', ' ', \$key) }}</dt>
                                <dd class="text-gray-900 font-medium truncate">{{ \$value ?? '—' }}</dd>
                            </div>
                            @endif
                        @endforeach
                    </dl>
                </div>

{$relPanels}
            </div>

            {{-- Right: sidebar --}}
            <div class="space-y-4">
                {{-- Quick stats --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Overview</h3>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">ID</span><span class="font-mono font-semibold text-gray-900">{{ \${$singular}->id }}</span></div>
                        @isset(\${$singular}->created_at)
                        <div class="flex justify-between"><span class="text-gray-500">Created</span><span class="text-gray-700">{{ \${$singular}->created_at?->format('M j, Y') }}</span></div>
                        @endisset
                        @isset(\${$singular}->updated_at)
                        <div class="flex justify-between"><span class="text-gray-500">Updated</span><span class="text-gray-700">{{ \${$singular}->updated_at?->diffForHumans() }}</span></div>
                        @endisset
                    </div>
                </div>

{$activityPanel}
            </div>
        </div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── List helpers ──────────────────────────────────────────────────────────

    private function adminHeaders(array $fields, array $relPrev, string $route): string
    {
        $html = '';
        foreach ($fields as $field) {
            $label = Str::headline($field);
            $html .= "                        <th class=\"px-4 py-3 text-left cursor-pointer hover:text-gray-700\">\n";
            $html .= "                            <a href=\"{{ request()->fullUrlWithQuery(['sort' => '{$field}', 'dir' => request('dir') === 'asc' ? 'desc' : 'asc']) }}\" class=\"flex items-center gap-1\">{$label}</a>\n";
            $html .= "                        </th>\n";
        }
        foreach ($relPrev as $rel => $_) {
            $label = Str::headline($rel);
            $html .= "                        <th class=\"px-4 py-3 text-left\">{$label}</th>\n";
        }
        return rtrim($html);
    }

    private function adminCells(string $singular, array $fields, array $relPrev): string
    {
        $html = '';
        $first = true;
        foreach ($fields as $field) {
            if ($first) {
                $html .= "                        <td class=\"px-4 py-2.5 font-medium text-gray-900\">{{ \${$singular}->{$field} }}</td>\n";
                $first = false;
            } else {
                $html .= "                        <td class=\"px-4 py-2.5 text-gray-600\">{{ \${$singular}->{$field} ?? '—' }}</td>\n";
            }
        }
        foreach ($relPrev as $rel => $display) {
            $cell = match ($display) {
                'count'  => "<span class=\"inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600\">{{ \${$singular}->{$rel}_count ?? 0 }}</span>",
                'avatar' => "<div class=\"flex items-center gap-2\"><div class=\"w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-semibold text-indigo-600\">{{ strtoupper(substr(\${$singular}->{$rel}?->name ?? '?', 0, 1)) }}</div><span class=\"text-xs text-gray-600\">{{ \${$singular}->{$rel}?->name ?? '—' }}</span></div>",
                default  => "{{ \${$singular}->{$rel} ?? '—' }}",
            };
            $html .= "                        <td class=\"px-4 py-2.5 text-gray-600\">{$cell}</td>\n";
        }
        return rtrim($html);
    }

    // ── Create helpers ────────────────────────────────────────────────────────

    private function twoColInputs(array $fields): string
    {
        $html = '';
        foreach ($fields as $field) {
            $label = Str::headline($field);
            $type  = $this->inferType($field);
            $input = $type === 'textarea'
                ? "<textarea name=\"{$field}\" id=\"{$field}\" rows=\"3\" class=\"mt-1 block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500\">{{ old('{$field}') }}</textarea>"
                : "<input type=\"{$type}\" name=\"{$field}\" id=\"{$field}\" value=\"{{ old('{$field}') }}\" class=\"mt-1 block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500\">";

            $html .= <<<BLADE
                        <div>
                            <label for="{$field}" class="block text-xs font-medium text-gray-500 uppercase tracking-wide">{$label}</label>
                            {$input}
                            @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
                        </div>

BLADE;
        }
        return $html;
    }

    private function twoColInputsEdit(array $fields, string $modelVar): string
    {
        $html = '';
        foreach ($fields as $field) {
            $label = Str::headline($field);
            $type  = $this->inferType($field);
            $input = $type === 'textarea'
                ? "<textarea name=\"{$field}\" id=\"{$field}\" rows=\"3\" class=\"mt-1 block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500\">{{ old('{$field}', \${$modelVar}->{$field}) }}</textarea>"
                : "<input type=\"{$type}\" name=\"{$field}\" id=\"{$field}\" value=\"{{ old('{$field}', \${$modelVar}->{$field}) }}\" class=\"mt-1 block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500\">";

            $html .= <<<BLADE
                        <div>
                            <label for="{$field}" class="block text-xs font-medium text-gray-500 uppercase tracking-wide">{$label}</label>
                            {$input}
                            @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
                        </div>

BLADE;
        }
        return $html;
    }

    private function adminRelationInputsEdit(array $relations, string $modelVar): string
    {
        $html = '';
        foreach ($relations as $rel => $uiType) {
            $label = Str::headline($rel);
            $input = match ($uiType) {
                'select' => <<<BLADE
                        <select name="{$rel}_id" class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">— {$label} —</option>
                            @foreach (\${$rel}Options as \$option)
                            <option value="{{ \$option->id }}" {{ old('{$rel}_id', \${$modelVar}->{$rel}_id) == \$option->id ? 'selected' : '' }}>{{ \$option->name ?? \$option->id }}</option>
                            @endforeach
                        </select>
BLADE,
                'multi_select' => <<<BLADE
                        <select name="{$rel}[]" multiple class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500 h-28">
                            @foreach (\${$rel}Options as \$option)
                            <option value="{{ \$option->id }}" {{ in_array(\$option->id, old('{$rel}', \${$modelVar}->{$rel}->pluck('id')->toArray())) ? 'selected' : '' }}>{{ \$option->name ?? \$option->id }}</option>
                            @endforeach
                        </select>
BLADE,
                default => '',
            };
            if (!$input) continue;
            $html .= <<<BLADE
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide">{$label}</label>
                        {$input}
                        @error('{$rel}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
                    </div>

BLADE;
        }
        return $html;
    }

    private function adminRelationInputs(array $relations): string
    {
        $html = '';
        foreach ($relations as $rel => $uiType) {
            $label = Str::headline($rel);
            $input = match ($uiType) {
                'select' => <<<BLADE
                        <select name="{$rel}_id" class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">— {$label} —</option>
                            @foreach (\${$rel}Options as \$option)
                            <option value="{{ \$option->id }}" {{ old('{$rel}_id') == \$option->id ? 'selected' : '' }}>{{ \$option->name ?? \$option->id }}</option>
                            @endforeach
                        </select>
BLADE,
                'multi_select' => <<<BLADE
                        <select name="{$rel}[]" multiple class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500 h-28">
                            @foreach (\${$rel}Options as \$option)
                            <option value="{{ \$option->id }}" {{ in_array(\$option->id, old('{$rel}', [])) ? 'selected' : '' }}>{{ \$option->name ?? \$option->id }}</option>
                            @endforeach
                        </select>
BLADE,
                default => '',
            };
            if (!$input) continue;
            $html .= <<<BLADE
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide">{$label}</label>
                        {$input}
                        @error('{$rel}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
                    </div>

BLADE;
        }
        return $html;
    }

    // ── Show helpers ──────────────────────────────────────────────────────────

    private function adminRelationPanels(string $singular, array $relations): string
    {
        $html = '';
        foreach ($relations as $rel => $uiType) {
            $label = Str::headline($rel);
            $html .= match ($uiType) {
                'list'  => $this->adminListPanel($singular, $rel, $label),
                'chips' => $this->adminChipsPanel($singular, $rel, $label),
                'card'  => $this->adminCardPanel($singular, $rel, $label),
                default => $this->adminListPanel($singular, $rel, $label),
            };
        }
        return $html;
    }

    private function adminListPanel(string $singular, string $rel, string $label): string
    {
        return <<<BLADE

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-700">{$label}</h3>
                        <span class="text-xs text-gray-400">{{ \${$singular}->{$rel}->count() }} total</span>
                    </div>
                    <ul class="divide-y divide-gray-50">
                        @forelse (\${$singular}->{$rel}->take(10) as \$item)
                        <li class="flex items-center justify-between px-5 py-2.5 text-sm">
                            <span class="text-gray-800">{{ \$item->name ?? \$item->title ?? \$item->id }}</span>
                            <span class="text-xs text-gray-400">{{ \$item->created_at?->format('M j') }}</span>
                        </li>
                        @empty
                        <li class="px-5 py-4 text-sm text-gray-400">No {$label}.</li>
                        @endforelse
                    </ul>
                </div>
BLADE;
    }

    private function adminChipsPanel(string $singular, string $rel, string $label): string
    {
        return <<<BLADE

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">{$label}</h3>
                    <div class="flex flex-wrap gap-2">
                        @forelse (\${$singular}->{$rel} as \$item)
                        <span class="inline-flex px-2.5 py-0.5 rounded-md text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100">
                            {{ \$item->name ?? \$item->id }}
                        </span>
                        @empty
                        <span class="text-xs text-gray-400">None assigned.</span>
                        @endforelse
                    </div>
                </div>
BLADE;
    }

    private function adminCardPanel(string $singular, string $rel, string $label): string
    {
        return <<<BLADE

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">{$label}</h3>
                    @if (\${$singular}->{$rel})
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-semibold text-sm">
                            {{ strtoupper(substr(\${$singular}->{$rel}->name ?? '?', 0, 1)) }}
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ \${$singular}->{$rel}->name ?? \${$singular}->{$rel}->id }}</p>
                            <p class="text-xs text-gray-400">{{ \${$singular}->{$rel}->email ?? '' }}</p>
                        </div>
                    </div>
                    @else
                    <p class="text-sm text-gray-400">Not assigned.</p>
                    @endif
                </div>
BLADE;
    }

    private function adminActivityPanel(string $singular): string
    {
        return <<<BLADE
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Activity</h3>
                    <ul class="space-y-2 text-xs text-gray-500">
                        <li class="flex justify-between"><span>Created</span><span class="text-gray-700">{{ \${$singular}->created_at?->format('M j, Y H:i') }}</span></li>
                        <li class="flex justify-between"><span>Updated</span><span class="text-gray-700">{{ \${$singular}->updated_at?->diffForHumans() }}</span></li>
                    </ul>
                </div>
BLADE;
    }

    private function adminActionButtons(string $singular, string $route, string $entity, array $actions): string
    {
        $html = '';
        if (in_array('edit', $actions)) {
            $html .= "<a href=\"{{ route('{$route}.edit', \${$singular}) }}\" class=\"inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition\">Edit</a>\n";
        }
        if (in_array('delete', $actions)) {
            $html .= <<<BLADE
                <form method="POST" action="{{ route('{$route}.destroy', \${$singular}) }}" onsubmit="return confirm('Delete this {$entity}?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-red-200 rounded-lg text-sm text-red-600 hover:bg-red-50 transition">Delete</button>
                </form>
BLADE;
        }
        return $html;
    }

    private function inferType(string $field): string
    {
        $lower = strtolower($field);
        if (str_contains($lower, 'email')) return 'email';
        if (str_contains($lower, 'password')) return 'password';
        if (str_contains($lower, 'url') || str_contains($lower, 'website')) return 'url';
        if (str_contains($lower, 'date') || str_ends_with($lower, '_at')) return 'date';
        if (in_array($lower, ['content', 'body', 'description', 'notes', 'bio', 'text', 'summary', 'excerpt'])) return 'textarea';
        if (str_contains($lower, 'amount') || str_contains($lower, 'price')) return 'number';
        return 'text';
    }
}
