<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\FrameworkDetector;

/**
 * Generates fully interactive blade pages.
 * Detects: CSS framework (Bootstrap/Tailwind), Alpine.js, Livewire
 * and generates the richest possible interactive code for each combination.
 */
class FullBladeGenerator
{
    public function __construct(private FrameworkDetector $framework) {}

    /**
     * Stamped at the top of every generated blade file.
     * CleanCommand searches for this to identify and remove generated views.
     */
    private string $stamp = "{{-- capyrel:generated — remove with: php artisan capyrel:clean --views --}}\n";

    // ── Public API ────────────────────────────────────────────────────────────

    public function generateIndex(string $model, array $columns): string
    {
        return $this->stamp . ($this->isTw()
            ? $this->twIndex($model, $columns)
            : $this->bsIndex($model, $columns));
    }

    public function generateShow(string $model, array $columns, array $relationships): string
    {
        return $this->stamp . ($this->isTw()
            ? $this->twShow($model, $columns, $relationships)
            : $this->bsShow($model, $columns, $relationships));
    }

    public function generateCreate(string $model, array $columns, array $relationships): string
    {
        return $this->stamp . ($this->isTw()
            ? $this->twForm($model, $columns, $relationships, 'create')
            : $this->bsForm($model, $columns, $relationships, 'create'));
    }

    public function generateEdit(string $model, array $columns, array $relationships): string
    {
        return $this->stamp . ($this->isTw()
            ? $this->twForm($model, $columns, $relationships, 'edit')
            : $this->bsForm($model, $columns, $relationships, 'edit'));
    }

    // ── Tailwind + Alpine.js Index (modal-first) ─────────────────────────────

    private function twIndex(string $model, array $columns): string
    {
        $plural    = Str::camel(Str::plural($model));
        $singular  = Str::camel($model);
        $route     = Str::kebab(Str::plural($model));
        $title     = Str::headline(Str::plural($model));
        $cols      = $this->displayCols($columns);
        $headers   = $this->twHeaders($cols);
        $cells     = $this->twCells($singular, $cols);
        $hasAlpine = $this->framework->hasAlpine();
        $createForm = $this->twInlineCreateForm($model, $columns, $route);
        $viewFields = $this->twViewFields($cols);

        // JSON-safe column list for quick-view
        $jsonCols = collect($cols)->pluck('name')->map(fn($n) => "'{$n}'")->implode(', ');

        if (!$hasAlpine) {
            // Non-Alpine fallback: separate pages
            return $this->twIndexNoAlpine($model, $plural, $singular, $route, $title, $headers, $cells);
        }

        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">{$title}</h2>
            <button @click="\$dispatch('open-create')"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 active:scale-95 transition shadow-sm shadow-indigo-200">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New {$model}
            </button>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- ═══ Toast Notification ═══ --}}
            @if(session('success'))
            <div x-data="{ show: true }" x-show="show" x-transition.opacity
                 x-init="setTimeout(() => show = false, 4000)"
                 class="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 bg-white border border-green-200 shadow-lg rounded-2xl text-sm text-green-800 max-w-sm">
                <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </div>
                <span>{{ session('success') }}</span>
                <button @click="show = false" class="ml-2 text-gray-400 hover:text-gray-600">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            @endif

            {{-- ═══ Search Bar ═══ --}}
            <div x-data="{ search: '' }" class="mb-5">
                <div class="relative max-w-xs">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                    <input x-model="search" type="search"
                           placeholder="Search {$title}..."
                           class="block w-full pl-9 pr-4 py-2.5 text-sm rounded-xl border border-gray-200 bg-white shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                           id="{$singular}Search">
                </div>

                {{-- ═══ Table ═══ --}}
                <div class="mt-3 bg-white shadow-sm rounded-2xl overflow-hidden border border-gray-100">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead>
                            <tr class="bg-gray-50/80">
{$headers}
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider w-32">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="{$singular}TableBody" class="divide-y divide-gray-50">
                            @forelse(\${$plural} as \${$singular})
                            <tr class="hover:bg-indigo-50/30 transition-colors group cursor-pointer"
                                data-search="{{ strtolower(implode(' ', array_filter([$singular ? array_map(fn(\$c) => \${$singular}->{\$c} ?? '', [{$jsonCols}]) : []])[0] ?? [])) }}"
                                x-show="!search || \$el.dataset.search.includes(search.toLowerCase())">
{$cells}
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-all duration-150">
                                        {{-- Quick View --}}
                                        <button type="button"
                                                @click="\$dispatch('open-view', {{ Js::from(\${$singular}->makeHidden(['password','remember_token','two_factor_secret'])->toArray()) }})"
                                                title="Quick View"
                                                class="p-1.5 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        </button>
                                        {{-- Edit --}}
                                        <a href="{{ route('{$route}.edit', \${$singular}) }}"
                                           title="Edit"
                                           class="p-1.5 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded-lg transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </a>
                                        {{-- Delete --}}
                                        <button type="button"
                                                @click="\$dispatch('open-delete', { url: '{{ route('{$route}.destroy', \${$singular}) }}', name: '{{ addslashes(\${$singular}->name ?? \${$singular}->title ?? '#'.\${$singular}->id) }}' })"
                                                title="Delete"
                                                class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr id="{$singular}Empty">
                                <td colspan="99" class="px-6 py-16 text-center">
                                    <div class="w-16 h-16 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                    </div>
                                    <p class="text-gray-500 font-medium">No {$title} yet</p>
                                    <p class="text-gray-400 text-sm mt-1">Get started by creating the first one.</p>
                                    <button @click="\$dispatch('open-create')"
                                            class="mt-4 inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm rounded-xl hover:bg-indigo-700 transition">
                                        + Create {$model}
                                    </button>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>

                    @if(\${$plural}->hasPages())
                    <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/50">
                        {{ \${$plural}->links() }}
                    </div>
                    @endif
                </div>
            </div>

        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════════
         CREATE MODAL — slides in from the right
    ═══════════════════════════════════════════════════════════════════════ --}}
    <div x-data="{ open: false }"
         @open-create.window="open = true"
         @keydown.escape.window="open = false">
        {{-- Overlay --}}
        <div x-show="open" x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             @click="open = false"
             class="fixed inset-0 bg-black/30 backdrop-blur-sm z-40"></div>

        {{-- Panel --}}
        <div x-show="open"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full opacity-0"
             x-transition:enter-end="translate-x-0 opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0 opacity-100"
             x-transition:leave-end="translate-x-full opacity-0"
             class="fixed inset-y-0 right-0 z-50 flex flex-col w-full max-w-lg bg-white shadow-2xl">

            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">New {$model}</h2>
                    <p class="text-sm text-gray-400 mt-0.5">Fill in the details below</p>
                </div>
                <button @click="open = false" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- Form --}}
            <div class="flex-1 overflow-y-auto px-6 py-5" x-data="{ loading: false }">
                <form action="{{ route('{$route}.store') }}" method="POST"
                      @submit="loading = true" novalidate>
                    @csrf

                    @if(\$errors->any())
                    <div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">
                        <ul class="list-disc list-inside space-y-0.5">
                            @foreach(\$errors->all() as \$err)
                                <li>{{ \$err }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    <div class="space-y-5">
{$createForm}
                    </div>

                    <div class="flex items-center justify-between mt-8 pt-5 border-t border-gray-100">
                        <button type="button" @click="open = false"
                                class="px-4 py-2.5 text-sm text-gray-500 hover:text-gray-700 rounded-xl hover:bg-gray-100 transition">
                            Cancel
                        </button>
                        <button type="submit" :disabled="loading"
                                class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 disabled:opacity-60 active:scale-95 transition shadow-sm shadow-indigo-200">
                            <svg x-show="loading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span x-text="loading ? 'Creating...' : 'Create {$model}'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════════
         QUICK VIEW SLIDE-OVER — shows model data without page load
    ═══════════════════════════════════════════════════════════════════════ --}}
    <div x-data="{ open: false, item: null }"
         @open-view.window="item = \$event.detail; open = true"
         @keydown.escape.window="open = false">

        <div x-show="open" x-transition.opacity @click="open = false"
             class="fixed inset-0 bg-black/20 backdrop-blur-sm z-40"></div>

        <div x-show="open"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 right-0 z-50 flex flex-col w-full max-w-md bg-white shadow-2xl">

            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{$model} Details</h2>
                    <p x-show="item" class="text-xs text-gray-400 mt-0.5">ID: <span x-text="item?.id"></span></p>
                </div>
                <div class="flex items-center gap-2">
                    <template x-if="item">
                        <a :href="'{{ url('{$route}') }}/' + item.id + '/edit'"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Edit
                        </a>
                    </template>
                    <button @click="open = false"
                            class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-5">
                <template x-if="item">
                    <dl class="space-y-4">
{$viewFields}
                    </dl>
                </template>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════════
         DELETE CONFIRMATION MODAL — centered, requires typing or clicking
    ═══════════════════════════════════════════════════════════════════════ --}}
    <div x-data="{ open: false, url: '', name: '' }"
         @open-delete.window="url = \$event.detail.url; name = \$event.detail.name; open = true"
         @keydown.escape.window="open = false">

        <div x-show="open" x-transition.opacity @click="open = false"
             class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">

            <div @click.stop x-show="open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">

                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 bg-red-100 rounded-2xl flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-gray-900 text-lg">Delete {$model}</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            You're about to delete <strong x-text="name"></strong>.
                            This action is permanent and cannot be reversed.
                        </p>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button @click="open = false"
                            class="flex-1 px-4 py-2.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 active:scale-95 transition">
                        Cancel
                    </button>
                    <form :action="url" method="POST" class="flex-1">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="w-full px-4 py-2.5 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 active:scale-95 transition shadow-sm shadow-red-200">
                            Yes, Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</x-app-layout>
BLADE;
    }

    private function twIndexNoAlpine(string $model, string $plural, string $singular, string $route, string $title, string $headers, string $cells): string
    {
        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">{$title}</h2>
            <a href="{{ route('{$route}.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition">
                + New {$model}
            </a>
        </div>
    </x-slot>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl text-green-800 text-sm">{{ session('success') }}</div>
            @endif
            <div class="bg-white shadow-sm rounded-2xl overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr>{$headers}<th class="px-6 py-3 text-right text-xs text-gray-400 uppercase">Actions</th></tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse(\${$plural} as \${$singular})
                        <tr class="hover:bg-gray-50 group">
{$cells}
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('{$route}.show', \${$singular}) }}" class="text-indigo-600 text-xs hover:underline mr-3">View</a>
                                <a href="{{ route('{$route}.edit', \${$singular}) }}" class="text-gray-600 text-xs hover:underline mr-3">Edit</a>
                                <form action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST" class="inline" onsubmit="return confirm('Delete this {$model}?')">@csrf @method('DELETE')
                                    <button class="text-red-500 text-xs hover:underline">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="99" class="px-6 py-12 text-center text-gray-400">No {$title} yet. <a href="{{ route('{$route}.create') }}" class="text-indigo-500">Create one.</a></td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if(\${$plural}->hasPages())
                <div class="px-6 py-4 border-t">{{ \${$plural}->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Tailwind Show ─────────────────────────────────────────────────────────

    private function twShow(string $model, array $columns, array $relationships): string
    {
        $singular  = Str::camel($model);
        $route     = Str::kebab(Str::plural($model));
        $title     = Str::headline($model);
        $fields    = $this->twShowFields($singular, $columns);
        $relSects  = $this->twRelSections($singular, $relationships);
        $hasAlpine = $this->framework->hasAlpine();
        $modal     = $hasAlpine ? $this->twDeleteModal($model) : '';
        $deleteTrigger = $hasAlpine
            ? "@click=\"showDeleteModal = true; deleteUrl = '{{ route('{$route}.destroy', \${$singular}) }}'\""
            : "onclick=\"document.getElementById('delete-{$singular}-form').submit()\"";

        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('{$route}.index') }}"
                   class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <h2 class="text-xl font-semibold text-gray-800">{$title} Details</h2>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('{$route}.edit', \${$singular}) }}"
                   class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Edit
                </a>
                <button type="button" {$deleteTrigger}
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete
                </button>
                <form id="delete-{$singular}-form" action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST" class="hidden">@csrf @method('DELETE')</form>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
                <div x-data="{ show: true }" x-show="show" x-transition
                     x-init="setTimeout(() => show = false, 4000)"
                     class="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-xl text-green-800 text-sm">
                    <svg class="w-5 h-5 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ session('success') }}
                </div>
            @endif

            {{-- Field values --}}
            <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">Details</h3>
                    <span class="text-xs text-gray-400"># {{ \${$singular}->id }}</span>
                </div>
                <div class="p-6">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
{$fields}
                    </dl>
                </div>
            </div>

{$relSects}

{$modal}

        </div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Tailwind Form (create / edit) ─────────────────────────────────────────

    private function twForm(string $model, array $columns, array $relationships, string $mode): string
    {
        $singular  = Str::camel($model);
        $route     = Str::kebab(Str::plural($model));
        $title     = ($mode === 'create' ? 'Create ' : 'Edit ') . Str::headline($model);
        $action    = $mode === 'create'
            ? "{{ route('{$route}.store') }}"
            : "{{ route('{$route}.update', \${$singular}) }}";
        $method    = $mode === 'edit' ? '@method(\'PUT\')' : '';
        $inputs    = $this->twInputs($columns, $relationships, $mode === 'edit' ? $singular : null);

        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('{$route}.index') }}" class="text-gray-400 hover:text-gray-600 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            </a>
            <h2 class="text-xl font-semibold text-gray-800">{$title}</h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">

            @if(\$errors->any())
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                    <p class="text-sm font-semibold text-red-800 mb-2 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.07 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        Please fix the following errors
                    </p>
                    <ul class="list-disc list-inside text-sm text-red-700 space-y-0.5">
                        @foreach(\$errors->all() as \$error)
                            <li>{{ \$error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white shadow-sm rounded-xl overflow-hidden" x-data="{ loading: false }">
                <form action="{$action}" method="POST" @submit="loading = true" novalidate>
                    @csrf
                    {$method}

                    <div class="p-6 space-y-6">
{$inputs}
                    </div>

                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
                        <a href="{{ route('{$route}.index') }}"
                           class="text-sm text-gray-500 hover:text-gray-700 transition">Cancel</a>

                        <button type="submit" :disabled="loading"
                                class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed active:scale-95 transition">
                            <svg x-show="loading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span x-show="!loading">Save {$model}</span>
                            <span x-show="loading">Saving...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Bootstrap Index (modal-first) ─────────────────────────────────────────

    private function bsIndex(string $model, array $columns): string
    {
        $plural      = Str::camel(Str::plural($model));
        $singular    = Str::camel($model);
        $route       = Str::kebab(Str::plural($model));
        $title       = Str::headline(Str::plural($model));
        $cols        = $this->displayCols($columns);
        $headers     = $this->bsHeaders($cols);
        $cells       = $this->bsCells($singular, $cols);
        $hasAlpine   = $this->framework->hasAlpine();
        $createForm  = $this->bsInlineCreateForm($model, $columns, $route);


        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container-xl py-4">

    {{-- ═══ Toast ═══ --}}
    @if(session('success'))
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
        <div id="capyrelToast" class="toast show align-items-center text-bg-success border-0 rounded-4 shadow-lg">
            <div class="d-flex align-items-center px-3 py-2 gap-2">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                <div class="toast-body fw-medium px-0">{{ session('success') }}</div>
                <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
    @endif

    {{-- ═══ Header ═══ --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-0 fw-bold">{$title}</h1>
            <p class="text-muted small mb-0 mt-1">Manage your {$title}</p>
        </div>
        <button type="button" class="btn btn-primary rounded-3 d-inline-flex align-items-center gap-2"
                data-bs-toggle="offcanvas" data-bs-target="#createOffcanvas">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New {$model}
        </button>
    </div>

    {{-- ═══ Search ═══ --}}
    <div class="mb-3">
        <div class="input-group input-group-sm" style="max-width: 280px;">
            <span class="input-group-text bg-white border-end-0">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
            </span>
            <input type="search" id="{$singular}Search" class="form-control border-start-0 rounded-end-3"
                   placeholder="Search {$title}..." oninput="capyrelSearch(this.value)">
        </div>
    </div>

    {{-- ═══ Table ═══ --}}
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="{$singular}Table">
                    <thead class="table-light border-bottom">
                        <tr>
{$headers}
                            <th class="text-end pe-4 text-muted small fw-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse(\${$plural} as \${$singular})
                        <tr class="{$singular}-row">
{$cells}
                            <td class="text-end pe-3">
                                <div class="d-flex align-items-center justify-content-end gap-1">
                                    <button type="button" class="btn btn-sm btn-icon text-secondary p-1"
                                            onclick="capyrelOpenView({{ Js::from(\${$singular}->makeHidden(['password','remember_token','two_factor_secret'])->toArray()) }})"
                                            title="Quick View">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    </button>
                                    <a href="{{ route('{$route}.edit', \${$singular}) }}"
                                       class="btn btn-sm btn-icon text-secondary p-1" title="Edit">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-icon text-danger p-1"
                                            onclick="capyrelOpenDelete('{{ route('{$route}.destroy', \${$singular}) }}', '{{ addslashes(\${$singular}->name ?? \${$singular}->title ?? '#'.\${$singular}->id) }}')"
                                            title="Delete">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr id="{$singular}EmptyRow">
                            <td colspan="99" class="text-center py-5">
                                <div class="bg-light rounded-4 d-inline-flex p-4 mb-3">
                                    <svg class="text-muted" width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                </div>
                                <p class="fw-semibold text-dark mb-1">No {$title} yet</p>
                                <p class="text-muted small mb-3">Create your first {$model} to get started.</p>
                                <button type="button" class="btn btn-primary btn-sm rounded-3"
                                        data-bs-toggle="offcanvas" data-bs-target="#createOffcanvas">
                                    + Create {$model}
                                </button>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if(\${$plural}->hasPages())
        <div class="card-footer bg-white border-top">{{ \${$plural}->links() }}</div>
        @endif
    </div>

    {{-- ═══ CREATE OFFCANVAS ═══ --}}
    <div class="offcanvas offcanvas-end" tabindex="-1" id="createOffcanvas" style="width:480px;">
        <div class="offcanvas-header border-bottom py-4">
            <div>
                <h5 class="offcanvas-title fw-bold mb-0">New {$model}</h5>
                <p class="text-muted small mb-0 mt-1">Fill in the details below</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            @if(\$errors->any())
            <div class="alert alert-danger rounded-3 small">
                <ul class="mb-0 ps-3">
                    @foreach(\$errors->all() as \$err)<li>{{ \$err }}</li>@endforeach
                </ul>
            </div>
            @endif
            <form action="{{ route('{$route}.store') }}" method="POST" id="{$singular}CreateForm" novalidate>
                @csrf
                <div class="row g-3">
{$createForm}
                </div>
                <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="offcanvas">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4" id="{$singular}CreateBtn">
                        <span class="btn-text">Create {$model}</span>
                        <span class="spinner-border spinner-border-sm ms-1 d-none" id="{$singular}CreateSpinner"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ═══ QUICK VIEW OFFCANVAS ═══ --}}
    <div class="offcanvas offcanvas-end" tabindex="-1" id="viewOffcanvas" style="width:400px;">
        <div class="offcanvas-header border-bottom py-4">
            <h5 class="offcanvas-title fw-bold">{$model} Details</h5>
            <div class="d-flex gap-2 align-items-center">
                <a id="viewEditLink" href="#" class="btn btn-sm btn-outline-secondary rounded-3">Edit</a>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
            </div>
        </div>
        <div class="offcanvas-body">
            <dl class="row small" id="viewOffcanvasDl"></dl>
        </div>
    </div>

    {{-- ═══ DELETE MODAL ═══ --}}
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-body p-4">
                    <div class="d-flex gap-3 align-items-start mb-3">
                        <div class="bg-danger bg-opacity-10 rounded-3 p-2 flex-shrink-0">
                            <svg width="22" height="22" fill="none" stroke="#dc3545" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1">Delete {$model}?</h6>
                            <p class="text-muted small mb-0" id="deleteModalMsg">This cannot be undone.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="button" class="btn btn-light flex-fill rounded-3" data-bs-dismiss="modal">Cancel</button>
                        <form id="deleteForm" method="POST" class="flex-fill">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger w-100 rounded-3">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Auto-hide toast
    var t = document.getElementById('capyrelToast');
    if (t) setTimeout(() => bootstrap.Toast.getOrCreateInstance(t).hide(), 4000);

    // Re-open offcanvas if validation failed
    @if(\$errors->any())
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('createOffcanvas')).show();
    @endif

    // Submit loading state
    var form = document.getElementById('{$singular}CreateForm');
    if (form) form.addEventListener('submit', function () {
        var btn = document.getElementById('{$singular}CreateBtn');
        btn.disabled = true;
        btn.querySelector('.btn-text').textContent = 'Creating...';
        btn.querySelector('#\${$singular}CreateSpinner').classList.remove('d-none');
    });
});

function capyrelSearch(q) {
    document.querySelectorAll('.{$singular}-row').forEach(function (r) {
        r.style.display = r.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
    });
}

function capyrelOpenDelete(url, name) {
    document.getElementById('deleteForm').action = url;
    document.getElementById('deleteModalMsg').textContent =
        'You\'re about to permanently delete "' + name + '".';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteModal')).show();
}

function capyrelOpenView(data) {
    var hidden = ['password', 'remember_token', 'two_factor_secret'];
    var dl = document.getElementById('viewOffcanvasDl');
    dl.innerHTML = Object.entries(data)
        .filter(([k]) => !hidden.includes(k))
        .map(([k, v]) =>
            '<dt class="col-5 text-muted text-capitalize small">' + k.replace(/_/g,' ') + '</dt>' +
            '<dd class="col-7">' + (v !== null && v !== undefined && v !== '' ? String(v) : '<span class="text-muted">—</span>') + '</dd>'
        ).join('');
    var editLink = document.getElementById('viewEditLink');
    if (editLink && data.id) editLink.href = '{{ url('{$route}') }}/' + data.id + '/edit';
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('viewOffcanvas')).show();
}
</script>
@endpush
BLADE;
    }

    // ── Bootstrap Show ────────────────────────────────────────────────────────

    private function bsShow(string $model, array $columns, array $relationships): string
    {
        $singular  = Str::camel($model);
        $route     = Str::kebab(Str::plural($model));
        $title     = Str::headline($model);
        $fields    = $this->bsShowFields($singular, $columns);
        $relSects  = $this->bsRelSections($singular, $relationships);
        $modal     = $this->bsDeleteModal($model);

        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container py-4">

    @if(session('success'))
    <div class="position-fixed top-0 end-0 p-3" style="z-index: 9999">
        <div id="capyrelToast" class="toast show align-items-center text-bg-success border-0 rounded-3 shadow">
            <div class="d-flex">
                <div class="toast-body fw-medium">✓ &nbsp;{{ session('success') }}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <a href="{{ route('{$route}.index') }}" class="text-muted text-decoration-none">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            </a>
            <h1 class="h4 mb-0 fw-bold">{$title}</h1>
            <span class="text-muted small">#{{ \${$singular}->id }}</span>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('{$route}.edit', \${$singular}) }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Edit
            </a>
            <button type="button" class="btn btn-danger d-inline-flex align-items-center gap-2"
                    data-bs-toggle="modal" data-bs-target="#deleteModal"
                    onclick="document.getElementById('deleteForm').action='{{ route('{$route}.destroy', \${$singular}) }}'">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Delete
            </button>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-header bg-white border-bottom d-flex justify-content-between">
            <span class="fw-semibold">Details</span>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
{$fields}
            </dl>
        </div>
    </div>

{$relSects}

{$modal}

</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('capyrelToast');
    if (el) setTimeout(function () { bootstrap.Toast.getOrCreateInstance(el).hide(); }, 4000);
});
</script>
@endpush
BLADE;
    }

    // ── Bootstrap Form ────────────────────────────────────────────────────────

    private function bsForm(string $model, array $columns, array $relationships, string $mode): string
    {
        $singular = Str::camel($model);
        $route    = Str::kebab(Str::plural($model));
        $title    = ($mode === 'create' ? 'Create ' : 'Edit ') . Str::headline($model);
        $action   = $mode === 'create'
            ? "{{ route('{$route}.store') }}"
            : "{{ route('{$route}.update', \${$singular}) }}";
        $method   = $mode === 'edit' ? '@method(\'PUT\')' : '';
        $inputs   = $this->bsInputs($columns, $relationships, $mode === 'edit' ? $singular : null);

        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex align-items-center gap-3 mb-4">
                <a href="{{ route('{$route}.index') }}" class="text-muted text-decoration-none">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <h1 class="h4 mb-0 fw-bold">{$title}</h1>
            </div>

            @if(\$errors->any())
                <div class="alert alert-danger rounded-3 d-flex gap-3">
                    <svg class="flex-shrink-0" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <div>
                        <strong>Please fix these errors:</strong>
                        <ul class="mb-0 mt-1 ps-3">
                            @foreach(\$errors->all() as \$error)
                                <li class="small">{{ \$error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-4">
                    <form action="{$action}" method="POST" novalidate
                          onsubmit="this.querySelector('[type=submit]').disabled=true; this.querySelector('.btn-spinner').classList.remove('d-none'); this.querySelector('.btn-text').classList.add('d-none');">
                        @csrf
                        {$method}

{$inputs}

                        <hr class="my-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="{{ route('{$route}.index') }}" class="btn btn-light">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4 d-inline-flex align-items-center gap-2">
                                <span class="btn-spinner spinner-border spinner-border-sm d-none"></span>
                                <span class="btn-text">Save {$model}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
BLADE;
    }

    // ── Modals ────────────────────────────────────────────────────────────────

    private function twDeleteModal(string $model): string
    {
        return <<<BLADE
            {{-- Delete Confirmation Modal --}}
            <div x-show="showDeleteModal" x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
                 @keydown.escape.window="showDeleteModal = false">
                <div x-show="showDeleteModal"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     @click.stop
                     class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="flex-shrink-0 w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.07 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900">Delete {$model}</h3>
                            <p class="text-sm text-gray-500 mt-0.5">This action cannot be undone.</p>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button @click="showDeleteModal = false"
                                class="flex-1 px-4 py-2.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition">
                            Cancel
                        </button>
                        <form :action="deleteUrl" method="POST" class="flex-1">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="w-full px-4 py-2.5 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 transition">
                                Yes, Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
BLADE;
    }

    private function bsDeleteModal(string $model): string
    {
        return <<<BLADE
{{-- Delete Confirmation Modal --}}
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="flex-shrink-0 bg-danger bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width:40px;height:40px">
                        <svg width="20" height="20" fill="none" stroke="#dc3545" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.07 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold">Delete {$model}?</h6>
                        <p class="text-muted small mb-0">This cannot be undone.</p>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-4">
                    <button type="button" class="btn btn-light flex-fill" data-bs-dismiss="modal">Cancel</button>
                    <form id="deleteForm" method="POST" class="flex-fill">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger w-100">Yes, Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
BLADE;
    }

    // ── Field renderers ───────────────────────────────────────────────────────

    private function twShowFields(string $singular, array $columns): string
    {
        $skip  = ['password', 'remember_token', 'two_factor_secret', '_id'];
        $lines = [];

        foreach ($columns as $col) {
            $name  = $col['name'];
            if (in_array($name, $skip)) continue;
            $label = Str::headline($name);
            $type  = $col['type_name'] ?? 'string';

            if (in_array($type, ['datetime', 'timestamp'])) {
                $value = "{{ \${$singular}->{$name}?->format('d M Y, H:i') ?? '—' }}";
            } elseif ($type === 'date') {
                $value = "{{ \${$singular}->{$name}?->format('d M Y') ?? '—' }}";
            } elseif (in_array($type, ['boolean', 'bool', 'tinyint'])) {
                $value = "\${$singular}->{$name} ? '<span class=\"inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800\">Yes</span>' : '<span class=\"inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500\">No</span>'";
                $lines[] = <<<BLADE
                        <div>
                            <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">{$label}</dt>
                            <dd class="mt-1.5 text-sm text-gray-900">{!! {$value} !!}</dd>
                        </div>
BLADE;
                continue;
            } else {
                $value = "{{ \${$singular}->{$name} ?? '—' }}";
            }

            $lines[] = <<<BLADE
                        <div>
                            <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">{$label}</dt>
                            <dd class="mt-1.5 text-sm text-gray-900 break-words">{$value}</dd>
                        </div>
BLADE;
        }

        return implode("\n", $lines);
    }

    private function bsShowFields(string $singular, array $columns): string
    {
        $skip  = ['password', 'remember_token', 'two_factor_secret', '_id'];
        $lines = [];

        foreach ($columns as $col) {
            $name  = $col['name'];
            if (in_array($name, $skip)) continue;
            $label = Str::headline($name);
            $type  = $col['type_name'] ?? 'string';

            if (in_array($type, ['datetime', 'timestamp'])) {
                $value = "{{ \${$singular}->{$name}?->format('d M Y, H:i') ?? '—' }}";
            } elseif ($type === 'date') {
                $value = "{{ \${$singular}->{$name}?->format('d M Y') ?? '—' }}";
            } elseif (in_array($type, ['boolean', 'bool', 'tinyint'])) {
                $value = "\${$singular}->{$name} ? '<span class=\"badge bg-success-subtle text-success\">Yes</span>' : '<span class=\"badge bg-secondary-subtle text-secondary\">No</span>'";
                $lines[] = <<<BLADE
                <dt class="col-sm-3 text-muted small fw-semibold text-uppercase">{$label}</dt>
                <dd class="col-sm-9">{!! {$value} !!}</dd>
BLADE;
                continue;
            } else {
                $value = "{{ \${$singular}->{$name} ?? '—' }}";
            }

            $lines[] = <<<BLADE
                <dt class="col-sm-3 text-muted small fw-semibold text-uppercase">{$label}</dt>
                <dd class="col-sm-9">{$value}</dd>
BLADE;
        }

        return implode("\n", $lines);
    }

    private function twHeaders(array $cols): string
    {
        return collect($cols)->map(fn($c) =>
            "                            <th class=\"px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider\">" . Str::headline($c['name']) . "</th>"
        )->implode("\n");
    }

    private function twCells(string $singular, array $cols): string
    {
        return collect($cols)->map(fn($c) =>
            "                            <td class=\"px-6 py-4 whitespace-nowrap text-sm text-gray-900\">{{ \${$singular}->{$c['name']} ?? '—' }}</td>"
        )->implode("\n");
    }

    private function bsHeaders(array $cols): string
    {
        return collect($cols)->map(fn($c) =>
            "                            <th>" . Str::headline($c['name']) . "</th>"
        )->implode("\n");
    }

    private function bsCells(string $singular, array $cols): string
    {
        return collect($cols)->map(fn($c) =>
            "                            <td>{{ \${$singular}->{$c['name']} ?? '—' }}</td>"
        )->implode("\n");
    }

    private function twInputs(array $columns, array $relationships, ?string $singular): string
    {
        return $this->buildInputs($columns, $relationships, $singular, 'tailwind');
    }

    private function bsInputs(array $columns, array $relationships, ?string $singular): string
    {
        return $this->buildInputs($columns, $relationships, $singular, 'bootstrap');
    }

    private function buildInputs(array $columns, array $relationships, ?string $singular, string $fw): string
    {
        $skip  = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];
        $lines = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;

            if (str_ends_with($name, '_id')) {
                $rel = $this->findBelongsToRel($name, $relationships);
                if ($rel) {
                    $lines[] = $this->selectField($name, $rel, $singular, $fw);
                    continue;
                }
            }

            $lines[] = $this->inputField($name, $col, $singular, $fw);
        }

        foreach ($relationships as $rel) {
            if ($rel['type'] !== 'belongsToMany') continue;
            $lines[] = $this->checkboxGroupField($rel, $singular, $fw);
        }

        return implode("\n\n", $lines);
    }

    private function inputField(string $name, array $col, ?string $singular, string $fw): string
    {
        $label    = Str::headline($name);
        $type     = $this->inputType($name, $col['type_name'] ?? 'string');
        $rawType  = strtolower($col['type_name'] ?? 'string');
        $oldVal   = $singular ? "{{ old('{$name}', \${$singular}->{$name}) }}" : "{{ old('{$name}') }}";
        $nullable = $col['nullable'] ?? false;
        $required = $nullable ? '' : 'required';

        if ($type === 'checkbox') {
            return $fw === 'tailwind'
                ? $this->twCheckbox($name, $label, $singular)
                : $this->bsCheckbox($name, $label, $singular);
        }

        if (in_array($rawType, ['text', 'longtext', 'mediumtext'])) {
            $content = $singular ? "{{ old('{$name}', \${$singular}->{$name}) }}" : "{{ old('{$name}') }}";
            return $fw === 'tailwind'
                ? $this->twTextarea($name, $label, $content, $required)
                : $this->bsTextarea($name, $label, $content, $required);
        }

        return $fw === 'tailwind'
            ? $this->twInput($name, $label, $type, $oldVal, $required)
            : $this->bsInput($name, $label, $type, $oldVal, $required);
    }

    private function twInput(string $name, string $label, string $type, string $val, string $req): string
    {
        return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1.5">
                                {$label} @if('{$req}' === 'required') <span class="text-red-500">*</span> @endif
                            </label>
                            <input type="{$type}" id="{$name}" name="{$name}" value="{$val}" {$req}
                                   class="block w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 transition @error('{$name}') border-red-400 bg-red-50 ring-2 ring-red-200 @enderror">
                            @error('{$name}')
                                <p class="mt-1.5 text-xs text-red-600 flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                    {{ \$message }}
                                </p>
                            @enderror
                        </div>
BLADE;
    }

    private function bsInput(string $name, string $label, string $type, string $val, string $req): string
    {
        return <<<BLADE
                        <div class="mb-4">
                            <label for="{$name}" class="form-label fw-semibold small">
                                {$label} @if('{$req}' === 'required') <span class="text-danger">*</span> @endif
                            </label>
                            <input type="{$type}" id="{$name}" name="{$name}" value="{$val}" {$req}
                                   class="form-control rounded-3 @error('{$name}') is-invalid @enderror">
                            @error('{$name}')
                                <div class="invalid-feedback d-flex align-items-center gap-1">
                                    <svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                    {{ \$message }}
                                </div>
                            @enderror
                        </div>
BLADE;
    }

    private function twTextarea(string $name, string $label, string $content, string $req): string
    {
        return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1.5">
                                {$label} @if('{$req}' === 'required') <span class="text-red-500">*</span> @endif
                            </label>
                            <textarea id="{$name}" name="{$name}" rows="5" {$req}
                                      class="block w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 transition @error('{$name}') border-red-400 bg-red-50 @enderror">{$content}</textarea>
                            @error('{$name}') <p class="mt-1.5 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
    }

    private function bsTextarea(string $name, string $label, string $content, string $req): string
    {
        return <<<BLADE
                        <div class="mb-4">
                            <label for="{$name}" class="form-label fw-semibold small">{$label}</label>
                            <textarea id="{$name}" name="{$name}" rows="5" {$req}
                                      class="form-control rounded-3 @error('{$name}') is-invalid @enderror">{$content}</textarea>
                            @error('{$name}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                        </div>
BLADE;
    }

    private function twCheckbox(string $name, string $label, ?string $singular): string
    {
        $checked = $singular ? "{{ old('{$name}', \${$singular}->{$name}) ? 'checked' : '' }}" : "{{ old('{$name}') ? 'checked' : '' }}";
        return <<<BLADE
                        <div class="flex items-start gap-3">
                            <div class="flex items-center h-5 mt-0.5">
                                <input type="hidden" name="{$name}" value="0">
                                <input type="checkbox" id="{$name}" name="{$name}" value="1" {$checked}
                                       class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            </div>
                            <label for="{$name}" class="text-sm font-medium text-gray-700">{$label}</label>
                        </div>
BLADE;
    }

    private function bsCheckbox(string $name, string $label, ?string $singular): string
    {
        $checked = $singular ? "{{ old('{$name}', \${$singular}->{$name}) ? 'checked' : '' }}" : "{{ old('{$name}') ? 'checked' : '' }}";
        return <<<BLADE
                        <div class="mb-4 form-check">
                            <input type="hidden" name="{$name}" value="0">
                            <input type="checkbox" id="{$name}" name="{$name}" value="1" {$checked}
                                   class="form-check-input">
                            <label for="{$name}" class="form-check-label fw-semibold small">{$label}</label>
                        </div>
BLADE;
    }

    private function selectField(string $fkCol, array $rel, ?string $singular, string $fw): string
    {
        $label      = Str::headline(Str::beforeLast($fkCol, '_id'));
        $related    = $rel['related'];
        $relatedVar = Str::camel(Str::plural($related));
        $relItem    = Str::camel(Str::singular($related));
        $selected   = $singular
            ? "{{ old('{$fkCol}', \${$singular}->{$fkCol}) == \${$relItem}->id ? 'selected' : '' }}"
            : "{{ old('{$fkCol}') == \${$relItem}->id ? 'selected' : '' }}";

        if ($fw === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label for="{$fkCol}" class="block text-sm font-medium text-gray-700 mb-1.5">{$label}</label>
                            <select id="{$fkCol}" name="{$fkCol}"
                                    class="block w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 @error('{$fkCol}') border-red-400 @enderror">
                                <option value="">— Select {$label} —</option>
                                @foreach(\${$relatedVar} as \${$relItem})
                                    <option value="{{ \${$relItem}->id }}" {$selected}>
                                        {{ \${$relItem}->name ?? \${$relItem}->title ?? \${$relItem}->id }}
                                    </option>
                                @endforeach
                            </select>
                            @error('{$fkCol}') <p class="mt-1.5 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
        }

        return <<<BLADE
                        <div class="mb-4">
                            <label for="{$fkCol}" class="form-label fw-semibold small">{$label}</label>
                            <select id="{$fkCol}" name="{$fkCol}"
                                    class="form-select rounded-3 @error('{$fkCol}') is-invalid @enderror">
                                <option value="">— Select {$label} —</option>
                                @foreach(\${$relatedVar} as \${$relItem})
                                    <option value="{{ \${$relItem}->id }}" {$selected}>
                                        {{ \${$relItem}->name ?? \${$relItem}->title ?? \${$relItem}->id }}
                                    </option>
                                @endforeach
                            </select>
                            @error('{$fkCol}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                        </div>
BLADE;
    }

    private function checkboxGroupField(array $rel, ?string $singular, string $fw): string
    {
        $method     = $rel['method'];
        $related    = $rel['related'];
        $relatedVar = Str::camel(Str::plural($related));
        $relItem    = Str::camel(Str::singular($related));
        $label      = Str::headline($method);
        $checked    = $singular
            ? "{{ in_array(\${$relItem}->id, old('{$method}_ids', \${$singular}->{$method}->pluck('id')->toArray())) ? 'checked' : '' }}"
            : "{{ in_array(\${$relItem}->id, old('{$method}_ids', [])) ? 'checked' : '' }}";

        if ($fw === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{$label}</label>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach(\${$relatedVar} as \${$relItem})
                                    <label class="flex items-center gap-2.5 p-3 rounded-xl border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50/50 cursor-pointer transition has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                                        <input type="checkbox" name="{$method}_ids[]" value="{{ \${$relItem}->id }}" {$checked}
                                               class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-sm text-gray-700">
                                            {{ \${$relItem}->name ?? \${$relItem}->title ?? \${$relItem}->id }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
BLADE;
        }

        return <<<BLADE
                        <div class="mb-4">
                            <label class="form-label fw-semibold small d-block">{$label}</label>
                            <div class="row g-2">
                                @foreach(\${$relatedVar} as \${$relItem})
                                    <div class="col-6 col-md-4">
                                        <label class="d-flex align-items-center gap-2 p-2 border rounded-3 cursor-pointer hover-bg-light">
                                            <input type="checkbox" class="form-check-input mt-0"
                                                   name="{$method}_ids[]" value="{{ \${$relItem}->id }}" {$checked}>
                                            <span class="small">{{ \${$relItem}->name ?? \${$relItem}->title ?? \${$relItem}->id }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
BLADE;
    }

    // ── Relationship sections ─────────────────────────────────────────────────

    private function twRelSections(string $singular, array $relationships): string
    {
        $sections = [];

        foreach ($relationships as $rel) {
            if (empty($rel['related']) || $rel['type'] === 'morphTo') continue;

            $method  = $rel['method'];
            $related = $rel['related'];
            $title   = Str::headline($method);
            $item    = Str::camel(Str::singular($related));

            if (in_array($rel['type'], ['hasMany', 'belongsToMany', 'hasManyThrough'])) {
                $sections[] = <<<BLADE
            <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">{$title}</h3>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                        {{ \${$singular}->{$method}->count() }}
                    </span>
                </div>
                <div class="divide-y divide-gray-50">
                    @forelse(\${$singular}->{$method} as \${$item})
                        <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50 transition">
                            <span class="text-sm text-gray-900">
                                {{ \${$item}->name ?? \${$item}->title ?? '#' . \${$item}->id }}
                            </span>
                        </div>
                    @empty
                        <div class="px-6 py-6 text-center text-sm text-gray-400">
                            No {$title} yet.
                        </div>
                    @endforelse
                </div>
            </div>
BLADE;
            } else {
                $sections[] = <<<BLADE
            <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-900">{$title}</h3>
                </div>
                <div class="px-6 py-4 text-sm text-gray-900">
                    @if(\${$singular}->{$method})
                        {{ \${$singular}->{$method}->name ?? \${$singular}->{$method}->title ?? '#' . \${$singular}->{$method}->id }}
                    @else
                        <span class="text-gray-400">None.</span>
                    @endif
                </div>
            </div>
BLADE;
            }
        }

        return implode("\n\n", $sections);
    }

    private function bsRelSections(string $singular, array $relationships): string
    {
        $sections = [];

        foreach ($relationships as $rel) {
            if (empty($rel['related']) || $rel['type'] === 'morphTo') continue;

            $method  = $rel['method'];
            $related = $rel['related'];
            $title   = Str::headline($method);
            $item    = Str::camel(Str::singular($related));

            if (in_array($rel['type'], ['hasMany', 'belongsToMany', 'hasManyThrough'])) {
                $sections[] = <<<BLADE
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">{$title}</span>
        <span class="badge bg-secondary-subtle text-secondary rounded-pill">{{ \${$singular}->{$method}->count() }}</span>
    </div>
    <ul class="list-group list-group-flush">
        @forelse(\${$singular}->{$method} as \${$item})
            <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                <span class="small">{{ \${$item}->name ?? \${$item}->title ?? '#' . \${$item}->id }}</span>
            </li>
        @empty
            <li class="list-group-item text-muted text-center py-4 small">No {$title} yet.</li>
        @endforelse
    </ul>
</div>
BLADE;
            } else {
                $sections[] = <<<BLADE
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white fw-semibold">{$title}</div>
    <div class="card-body small">
        @if(\${$singular}->{$method})
            {{ \${$singular}->{$method}->name ?? \${$singular}->{$method}->title ?? '#' . \${$singular}->{$method}->id }}
        @else
            <span class="text-muted">None.</span>
        @endif
    </div>
</div>
BLADE;
            }
        }

        return implode("\n", $sections);
    }


    // ── Inline forms for modals / offcanvas ──────────────────────────────────

    private function twInlineCreateForm(string $model, array $columns, string $route): string
    {
        return $this->twInputs($columns, [], null);
    }

    private function bsInlineCreateForm(string $model, array $columns, string $route): string
    {
        $skip  = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];
        $lines = [];
        foreach ($columns as $col) {
            $name    = $col['name'];
            if (in_array($name, $skip)) continue;
            $label   = \Illuminate\Support\Str::headline($name);
            $type    = $this->inputType($name, $col['type_name'] ?? 'string');
            $oldVal  = "{{ old('{$name}') }}";
            $req     = ($col['nullable'] ?? false) ? '' : 'required';
            $rawType = strtolower($col['type_name'] ?? 'string');
            if (in_array($rawType, ['text', 'longtext', 'mediumtext'])) {
                $lines[] = "                    <div class=\"col-12\">\n                        <label class=\"form-label fw-semibold small\">{$label}</label>\n                        <textarea name=\"{$name}\" rows=\"3\" {$req} class=\"form-control rounded-3 @error('{$name}') is-invalid @enderror\">{{ old('{$name}') }}</textarea>\n                        @error('{$name}') <div class=\"invalid-feedback\">{{ \$message }}</div> @enderror\n                    </div>";
            } elseif ($type === 'checkbox') {
                $lines[] = "                    <div class=\"col-12\">\n                        <div class=\"form-check\">\n                            <input type=\"hidden\" name=\"{$name}\" value=\"0\">\n                            <input type=\"checkbox\" name=\"{$name}\" value=\"1\" class=\"form-check-input\">\n                            <label class=\"form-check-label small\">{$label}</label>\n                        </div>\n                    </div>";
            } else {
                $span   = strlen($label) > 10 ? '12' : '6';
                $lines[] = "                    <div class=\"col-{$span}\">\n                        <label class=\"form-label fw-semibold small\">{$label}</label>\n                        <input type=\"{$type}\" name=\"{$name}\" value=\"{$oldVal}\" {$req} class=\"form-control rounded-3 @error('{$name}') is-invalid @enderror\">\n                        @error('{$name}') <div class=\"invalid-feedback small\">{{ \$message }}</div> @enderror\n                    </div>";
            }
        }
        return implode("\n", $lines);
    }

    /** Quick-view fields for Alpine.js slide-over — uses x-text, zero page loads. */
    private function twViewFields(array $cols): string
    {
        $skip  = ['password', 'remember_token', 'two_factor_secret', '_id'];
        $lines = [];
        foreach ($cols as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;
            $label = \Illuminate\Support\Str::headline($name);
            $lines[] = "                        <div>\n                            <dt class=\"text-xs font-medium text-gray-400 uppercase tracking-wide mb-0.5\">{$label}</dt>\n                            <dd class=\"text-sm text-gray-900\" x-text=\"item?.{$name} ?? '—'\"></dd>\n                        </div>";
        }
        return implode("\n", $lines);
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private function isTw(): bool { return $this->framework->isTailwind(); }

    private function displayCols(array $columns): array
    {
        $skip = ['password', 'remember_token', 'two_factor_secret', '_id', 'deleted_at'];
        return array_values(array_filter(array_slice($columns, 0, 5), fn($c) => !in_array($c['name'], $skip)));
    }

    private function inputType(string $name, string $type): string
    {
        if (str_contains($name, 'email'))                                return 'email';
        if ($name === 'password' || str_ends_with($name, '_password'))   return 'password';
        if (str_contains($name, 'url') || $name === 'website')           return 'url';
        if (str_ends_with($name, '_at') || $type === 'date')             return 'date';
        if (in_array($type, ['datetime', 'timestamp']))                   return 'datetime-local';
        if (in_array($type, ['int', 'integer', 'bigint', 'smallint']))    return 'number';
        if (in_array($type, ['decimal', 'float', 'double', 'numeric']))  return 'number';
        if (in_array($type, ['boolean', 'tinyint', 'bool']))              return 'checkbox';
        return 'text';
    }

    private function findBelongsToRel(string $fkCol, array $relationships): ?array
    {
        foreach ($relationships as $rel) {
            if ($rel['type'] !== 'belongsTo') continue;
            if (($rel['foreign_key'] ?? '') === $fkCol) return $rel;
            $guessed = Str::studly(Str::beforeLast($fkCol, '_id'));
            if ($rel['related'] === $guessed) return $rel;
        }
        return null;
    }
}
