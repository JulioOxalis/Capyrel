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

    // ── Public API ────────────────────────────────────────────────────────────

    public function generateIndex(string $model, array $columns): string
    {
        return $this->isTw()
            ? $this->twIndex($model, $columns)
            : $this->bsIndex($model, $columns);
    }

    public function generateShow(string $model, array $columns, array $relationships): string
    {
        return $this->isTw()
            ? $this->twShow($model, $columns, $relationships)
            : $this->bsShow($model, $columns, $relationships);
    }

    public function generateCreate(string $model, array $columns, array $relationships): string
    {
        return $this->isTw()
            ? $this->twForm($model, $columns, $relationships, 'create')
            : $this->bsForm($model, $columns, $relationships, 'create');
    }

    public function generateEdit(string $model, array $columns, array $relationships): string
    {
        return $this->isTw()
            ? $this->twForm($model, $columns, $relationships, 'edit')
            : $this->bsForm($model, $columns, $relationships, 'edit');
    }

    // ── Tailwind + Alpine.js Index ────────────────────────────────────────────

    private function twIndex(string $model, array $columns): string
    {
        $plural   = Str::camel(Str::plural($model));
        $singular = Str::camel($model);
        $route    = Str::kebab(Str::plural($model));
        $title    = Str::headline(Str::plural($model));
        $cols     = $this->displayCols($columns);
        $headers  = $this->twHeaders($cols);
        $cells    = $this->twCells($singular, $cols);
        $hasAlpine = $this->framework->hasAlpine();

        $search = $hasAlpine
            ? '<input x-model="search" type="search" placeholder="Search ' . Str::headline(Str::plural($model)) . '..." class="block w-64 rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">'
            : '<input type="search" name="search" value="{{ request(\'search\') }}" placeholder="Search..." class="block w-64 rounded-lg border-gray-300 shadow-sm text-sm">';

        $xData   = $hasAlpine ? 'x-data="{ search: \'\', deleteUrl: \'\', showDeleteModal: false }"' : '';
        $rowShow = $hasAlpine
            ? 'x-show="!search || JSON.stringify($el.textContent).toLowerCase().includes(search.toLowerCase())"'
            : '';
        $deleteBtn = $hasAlpine
            ? "@click=\"showDeleteModal = true; deleteUrl = '{{ route('{$route}.destroy', \${$singular}) }}'\""
            : "onclick=\"if(!confirm('Delete this {$model}? This cannot be undone.')) return false;\" form=\"delete-{$singular}-{{ \${$singular}->id }}\"";
        $deleteForm = $hasAlpine
            ? ''
            : "<form id=\"delete-{$singular}-{{ \${$singular}->id }}\" action=\"{{ route('{$route}.destroy', \${$singular}) }}\" method=\"POST\" class=\"hidden\">@csrf @method('DELETE')</form>";

        $modal = $hasAlpine ? $this->twDeleteModal($model) : '';

        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">{$title}</h2>
            <a href="{{ route('{$route}.create') }}"
               class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 active:scale-95 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New {$model}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8" {$xData}>

            {{-- Toast notification --}}
            @if(session('success'))
                <div x-data="{ show: true }" x-show="show" x-transition
                     x-init="setTimeout(() => show = false, 4000)"
                     class="mb-4 flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-xl text-green-800 text-sm">
                    <span class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        {{ session('success') }}
                    </span>
                    <button @click="show = false" class="text-green-600 hover:text-green-800">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            @endif

            {{-- Search + filters --}}
            <div class="mb-4 flex items-center gap-3">
                {$search}
            </div>

            {{-- Table --}}
            <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
{$headers}
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        @forelse(\${$plural} as \${$singular})
                        <tr {$rowShow} class="hover:bg-gray-50 transition-colors group">
{$cells}
{$deleteForm}
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <a href="{{ route('{$route}.show', \${$singular}) }}"
                                       class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition">
                                        View
                                    </a>
                                    <a href="{{ route('{$route}.edit', \${$singular}) }}"
                                       class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                                        Edit
                                    </a>
                                    <button type="button" {$deleteBtn}
                                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 rounded-lg hover:bg-red-100 transition">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="99" class="px-6 py-16 text-center">
                                <div class="text-gray-300 text-4xl mb-3">○</div>
                                <p class="text-gray-500 font-medium">No {$title} yet.</p>
                                <a href="{{ route('{$route}.create') }}" class="mt-2 inline-block text-indigo-500 text-sm hover:underline">
                                    Create the first one →
                                </a>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>

                @if(\${$plural}->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">
                    {{ \${$plural}->links() }}
                </div>
                @endif
            </div>

{$modal}

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

    // ── Bootstrap Index ───────────────────────────────────────────────────────

    private function bsIndex(string $model, array $columns): string
    {
        $plural    = Str::camel(Str::plural($model));
        $singular  = Str::camel($model);
        $route     = Str::kebab(Str::plural($model));
        $title     = Str::headline(Str::plural($model));
        $cols      = $this->displayCols($columns);
        $headers   = $this->bsHeaders($cols);
        $cells     = $this->bsCells($singular, $cols);
        $hasAlpine = $this->framework->hasAlpine();

        $xData     = $hasAlpine ? 'x-data="{ search: \'\', deleteUrl: \'\', showDeleteModal: false }"' : '';
        $rowShow   = $hasAlpine ? 'x-show="!search || $el.textContent.toLowerCase().includes(search.toLowerCase())"' : '';
        $searchInput = $hasAlpine
            ? '<input x-model="search" type="search" class="form-control w-auto" placeholder="Search...">'
            : '<form class="d-inline"><input type="search" name="search" value="{{ request(\'search\') }}" class="form-control w-auto" placeholder="Search..."> <button class="btn btn-outline-secondary">Go</button></form>';

        $modal = $this->bsDeleteModal($model);

        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container py-4" {$xData}>

    {{-- Toast --}}
    @if(session('success'))
    <div class="position-fixed top-0 end-0 p-3" style="z-index: 9999">
        <div id="capyrelToast" class="toast show align-items-center text-bg-success border-0 rounded-3 shadow" role="alert">
            <div class="d-flex">
                <div class="toast-body fw-medium">✓ &nbsp;{{ session('success') }}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0 fw-bold">{$title}</h1>
        <a href="{{ route('{$route}.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
            <svg class="bi" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/></svg>
            New {$model}
        </a>
    </div>

    <div class="mb-3 d-flex gap-2">
        {$searchInput}
    </div>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
{$headers}
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse(\${$plural} as \${$singular})
                        <tr {$rowShow}>
{$cells}
                            <td class="text-end pe-4">
                                <div class="btn-group btn-group-sm">
                                    <a href="{{ route('{$route}.show', \${$singular}) }}" class="btn btn-outline-secondary">View</a>
                                    <a href="{{ route('{$route}.edit', \${$singular}) }}" class="btn btn-outline-secondary">Edit</a>
                                    <button type="button" class="btn btn-outline-danger"
                                            data-bs-toggle="modal" data-bs-target="#deleteModal"
                                            onclick="document.getElementById('deleteForm').action='{{ route('{$route}.destroy', \${$singular}) }}'">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="99" class="text-center text-muted py-5">
                                <div class="fs-1 mb-2 opacity-25">○</div>
                                No {$title} found.
                                <a href="{{ route('{$route}.create') }}" class="d-block mt-2">Create the first one</a>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if(\${$plural}->hasPages())
        <div class="card-footer bg-white border-top-0">
            {{ \${$plural}->links() }}
        </div>
        @endif
    </div>

{$modal}

</div>
@endsection

@push('scripts')
<script>
// Auto-hide toast after 4 seconds
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('capyrelToast');
    if (el) setTimeout(function () {
        bootstrap.Toast.getOrCreateInstance(el).hide();
    }, 4000);
});
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
