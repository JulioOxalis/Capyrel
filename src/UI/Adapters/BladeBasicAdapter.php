<?php

namespace Julio\Capyrel\UI\Adapters;

use Illuminate\Support\Str;
use Julio\Capyrel\UI\Contracts\UiAdapter;

/**
 * blade-basic adapter — the minimal, always-available UI adapter.
 *
 * Renders a UI Contract into simple Blade views:
 *   list   → HTML table with action buttons
 *   create → Simple form
 *   show   → Stacked sections with relation panels
 *
 * This adapter makes NO design decisions beyond the minimum needed to produce
 * working Blade. CSS framework classes are limited to Tailwind utilities.
 */
class BladeBasicAdapter implements UiAdapter
{
    private string $stamp = "{{-- capyrel:ui:basic — remove with: php artisan capyrel:clean --views --}}\n";

    public function name(): string
    {
        return 'blade-basic';
    }

    public function capabilities(): array
    {
        return ['list', 'create', 'show'];
    }

    // ── List ─────────────────────────────────────────────────────────────────

    public function renderList(array $contract): string
    {
        $entity   = $contract['entity'];
        $screen   = $contract['screens']['list'] ?? [];
        $fields   = $screen['fields'] ?? [];
        $actions  = $screen['actions'] ?? ['create'];
        $relPrev  = $screen['relations_preview'] ?? [];
        $route    = Str::kebab(Str::plural($entity));
        $plural   = Str::camel(Str::plural($entity));
        $singular = Str::camel($entity);
        $title    = Str::headline(Str::plural($entity));

        $headers     = $this->listHeaders($fields, $relPrev);
        $cells       = $this->listCells($singular, $fields, $relPrev);
        $createBtn   = in_array('create', $actions)
            ? "<a href=\"{{ route('{$route}.create') }}\" class=\"inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700\">New {$entity}</a>"
            : '';

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">{$title}</h2>
            {$createBtn}
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
{$headers}
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse (\${$plural} as \${$singular})
                    <tr class="hover:bg-gray-50">
{$cells}
                        <td class="px-4 py-3 text-right whitespace-nowrap text-sm space-x-2">
                            <a href="{{ route('{$route}.show', \${$singular}) }}" class="text-indigo-600 hover:underline">View</a>
                            <a href="{{ route('{$route}.edit', \${$singular}) }}" class="text-gray-600 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('{$route}.destroy', \${$singular}) }}" class="inline" onsubmit="return confirm('Delete this {$entity}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-500 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="{{ {$this->colCount($fields, $relPrev)} + 1 }}" class="px-4 py-8 text-center text-gray-400">No {$title} found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ \${$plural}->links() }}
        </div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function renderCreate(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['create'] ?? [];
        $fields    = $screen['fields'] ?? [];
        $relations = $screen['relations'] ?? [];
        $route     = Str::kebab(Str::plural($entity));
        $title     = "Create {$entity}";

        $inputs   = $this->formInputs($fields);
        $relInputs = $this->formRelationInputs($relations);

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">{$title}</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('{$route}.store') }}" class="bg-white shadow rounded-lg p-6 space-y-5">
            @csrf
{$inputs}
{$relInputs}
            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="{{ route('{$route}.index') }}" class="text-sm text-gray-600 hover:underline">Cancel</a>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                    Create {$entity}
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function renderShow(array $contract): string
    {
        $entity    = $contract['entity'];
        $meta      = $contract['meta'] ?? [];
        $screen    = $contract['screens']['show'] ?? [];
        $sections  = $screen['sections'] ?? ['main'];
        $relations = $screen['relations'] ?? [];
        $actions   = $screen['actions'] ?? ['edit', 'delete'];
        $route     = Str::kebab(Str::plural($entity));
        $singular  = Str::camel($entity);
        $title     = Str::headline($entity);
        $titleField = $meta['title_field'] ?? 'id';

        $mainSection     = $this->showMainSection($singular, $titleField);
        $relSections     = in_array('relations', $sections) ? $this->showRelationSections($singular, $relations, $contract['relations'] ?? []) : '';
        $activitySection = in_array('activity', $sections) ? $this->showActivitySection($singular) : '';
        $actionButtons   = $this->showActionButtons($singular, $route, $entity, $actions);

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">{$title}: {{ \${$singular}->{$titleField} }}</h2>
            <div class="flex gap-2">
{$actionButtons}
            </div>
        </div>
    </x-slot>

    <div class="py-8 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
{$mainSection}
{$relSections}
{$activitySection}
    </div>
</x-app-layout>
BLADE;
    }

    // ── List helpers ──────────────────────────────────────────────────────────

    private function listHeaders(array $fields, array $relPrev): string
    {
        $rows = '';
        foreach ($fields as $field) {
            $label = Str::headline($field);
            $rows .= "                        <th class=\"px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase\">{$label}</th>\n";
        }
        foreach ($relPrev as $rel => $_) {
            $label = Str::headline($rel);
            $rows .= "                        <th class=\"px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase\">{$label}</th>\n";
        }
        return rtrim($rows);
    }

    private function listCells(string $singular, array $fields, array $relPrev): string
    {
        $rows = '';
        foreach ($fields as $field) {
            $rows .= "                        <td class=\"px-4 py-3 text-sm text-gray-900\">{{ \${$singular}->{$field} }}</td>\n";
        }
        foreach ($relPrev as $rel => $display) {
            $cell = match ($display) {
                'count'  => "{{ \${$singular}->{$rel}_count ?? \${$singular}->{$rel}()->count() }}",
                'avatar' => "<img src=\"{{ \${$singular}->{$rel}->avatar ?? '' }}\" class=\"w-7 h-7 rounded-full\" />",
                'badge'  => "<span class=\"inline-flex px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-700\">{{ \${$singular}->{$rel}?->name ?? '—' }}</span>",
                default  => "{{ \${$singular}->{$rel} }}",
            };
            $rows .= "                        <td class=\"px-4 py-3 text-sm text-gray-700\">{$cell}</td>\n";
        }
        return rtrim($rows);
    }

    private function colCount(array $fields, array $relPrev): int
    {
        return count($fields) + count($relPrev);
    }

    // ── Create helpers ────────────────────────────────────────────────────────

    private function formInputs(array $fields): string
    {
        $html = '';
        foreach ($fields as $field) {
            $label = Str::headline($field);
            $type  = $this->inferInputType($field);

            $input = match ($type) {
                'textarea' => "<textarea name=\"{$field}\" id=\"{$field}\" rows=\"4\" class=\"mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm\">{{ old('{$field}') }}</textarea>",
                'checkbox' => "<input type=\"checkbox\" name=\"{$field}\" id=\"{$field}\" value=\"1\" {{ old('{$field}') ? 'checked' : '' }} class=\"rounded border-gray-300 text-indigo-600 shadow-sm\">",
                default    => "<input type=\"{$type}\" name=\"{$field}\" id=\"{$field}\" value=\"{{ old('{$field}') }}\" class=\"mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm\">",
            };

            $html .= <<<BLADE

            <div>
                <label for="{$field}" class="block text-sm font-medium text-gray-700">{$label}</label>
                {$input}
                @error('{$field}') <p class="mt-1 text-xs text-red-600">{{ \$message }}</p> @enderror
            </div>
BLADE;
        }
        return $html;
    }

    private function formRelationInputs(array $relations): string
    {
        $html = '';
        foreach ($relations as $rel => $uiType) {
            $label = Str::headline($rel);

            $input = match ($uiType) {
                'select' => <<<BLADE
                <select name="{$rel}_id" id="{$rel}_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    <option value="">— Select {$label} —</option>
                    @foreach (\${$rel}Options as \$option)
                        <option value="{{ \$option->id }}" {{ old('{$rel}_id') == \$option->id ? 'selected' : '' }}>{{ \$option->name ?? \$option->id }}</option>
                    @endforeach
                </select>
BLADE,
                'multi_select' => <<<BLADE
                <select name="{$rel}[]" id="{$rel}" multiple class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm h-32">
                    @foreach (\${$rel}Options as \$option)
                        <option value="{{ \$option->id }}" {{ in_array(\$option->id, old('{$rel}', [])) ? 'selected' : '' }}>{{ \$option->name ?? \$option->id }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">Hold Ctrl / Cmd to select multiple.</p>
BLADE,
                default => '',
            };

            if ($input === '') continue;

            $html .= <<<BLADE

            <div>
                <label for="{$rel}" class="block text-sm font-medium text-gray-700">{$label}</label>
                {$input}
                @error('{$rel}') <p class="mt-1 text-xs text-red-600">{{ \$message }}</p> @enderror
            </div>
BLADE;
        }
        return $html;
    }

    private function inferInputType(string $field): string
    {
        $lower = strtolower($field);
        if (str_contains($lower, 'email'))                   return 'email';
        if (str_contains($lower, 'password'))                return 'password';
        if (str_contains($lower, 'url') || str_contains($lower, 'website')) return 'url';
        if (str_contains($lower, 'phone') || str_contains($lower, 'tel'))   return 'tel';
        if (str_contains($lower, 'date') || str_contains($lower, '_at'))     return 'date';
        if (str_contains($lower, 'time'))                    return 'time';
        if (str_contains($lower, 'color') || str_contains($lower, 'colour')) return 'color';
        if (in_array($lower, ['content', 'body', 'description', 'notes', 'bio', 'text', 'summary', 'excerpt'])) return 'textarea';
        if (in_array($lower, ['is_active', 'is_published', 'is_featured', 'active', 'published', 'enabled'])) return 'checkbox';
        if (str_contains($lower, 'amount') || str_contains($lower, 'price') || str_contains($lower, 'cost')) return 'number';
        return 'text';
    }

    // ── Show helpers ──────────────────────────────────────────────────────────

    private function showMainSection(string $singular, string $titleField): string
    {
        return <<<BLADE
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Details</h3>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                @foreach (\${$singular}->getAttributes() as \$key => \$value)
                    @if (!in_array(\$key, ['password', 'remember_token', 'two_factor_secret']))
                    <div>
                        <dt class="font-medium text-gray-500 capitalize">{{ str_replace('_', ' ', \$key) }}</dt>
                        <dd class="mt-1 text-gray-900">{{ \$value ?? '—' }}</dd>
                    </div>
                    @endif
                @endforeach
            </dl>
        </div>
BLADE;
    }

    private function showRelationSections(string $singular, array $relations, array $relMap): string
    {
        $html = '';
        foreach ($relations as $rel => $uiType) {
            $label    = Str::headline($rel);
            $relType  = $relMap[$rel]['type'] ?? 'hasMany';

            $html .= match ($uiType) {
                'list'  => $this->showRelationList($singular, $rel, $label),
                'chips' => $this->showRelationChips($singular, $rel, $label),
                'card'  => $this->showRelationCard($singular, $rel, $label),
                default => $this->showRelationList($singular, $rel, $label),
            };
        }
        return $html;
    }

    private function showRelationList(string $singular, string $rel, string $label): string
    {
        return <<<BLADE

        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">{$label}</h3>
            <ul class="divide-y divide-gray-100">
                @forelse (\${$singular}->{$rel} as \$item)
                <li class="py-2 text-sm text-gray-700">{{ \$item->name ?? \$item->id }}</li>
                @empty
                <li class="py-2 text-sm text-gray-400">No {$label}.</li>
                @endforelse
            </ul>
        </div>
BLADE;
    }

    private function showRelationChips(string $singular, string $rel, string $label): string
    {
        return <<<BLADE

        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-3">{$label}</h3>
            <div class="flex flex-wrap gap-2">
                @forelse (\${$singular}->{$rel} as \$item)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                    {{ \$item->name ?? \$item->id }}
                </span>
                @empty
                <span class="text-sm text-gray-400">None.</span>
                @endforelse
            </div>
        </div>
BLADE;
    }

    private function showRelationCard(string $singular, string $rel, string $label): string
    {
        return <<<BLADE

        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-3">{$label}</h3>
            @if (\${$singular}->{$rel})
            <p class="text-sm text-gray-700">{{ \${$singular}->{$rel}->name ?? \${$singular}->{$rel}->id }}</p>
            @else
            <p class="text-sm text-gray-400">Not set.</p>
            @endif
        </div>
BLADE;
    }

    private function showActivitySection(string $singular): string
    {
        return <<<BLADE

        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-3">Activity</h3>
            <dl class="text-sm space-y-1 text-gray-600">
                @isset(\${$singular}->created_at)
                <div><dt class="inline font-medium">Created:</dt> <dd class="inline">{{ \${$singular}->created_at?->diffForHumans() }}</dd></div>
                @endisset
                @isset(\${$singular}->updated_at)
                <div><dt class="inline font-medium">Updated:</dt> <dd class="inline">{{ \${$singular}->updated_at?->diffForHumans() }}</dd></div>
                @endisset
            </dl>
        </div>
BLADE;
    }

    private function showActionButtons(string $singular, string $route, string $entity, array $actions): string
    {
        $html = '';
        if (in_array('edit', $actions)) {
            $html .= "                <a href=\"{{ route('{$route}.edit', \${$singular}) }}\" class=\"px-3 py-1.5 bg-gray-100 text-gray-700 text-sm rounded hover:bg-gray-200\">Edit</a>\n";
        }
        if (in_array('delete', $actions)) {
            $html .= <<<BLADE
                <form method="POST" action="{{ route('{$route}.destroy', \${$singular}) }}" onsubmit="return confirm('Delete this {$entity}?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-3 py-1.5 bg-red-50 text-red-600 text-sm rounded hover:bg-red-100">Delete</button>
                </form>
BLADE;
        }
        return $html;
    }
}
