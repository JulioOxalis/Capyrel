<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\FrameworkDetector;

class FullBladeGenerator
{
    public function __construct(private FrameworkDetector $framework) {}

    // ── Public entry points ───────────────────────────────────────────────────

    public function generateIndex(string $model, array $columns): string
    {
        $plural   = Str::camel(Str::plural($model));
        $singular = Str::camel($model);
        $route    = Str::kebab(Str::plural($model));
        $title    = Str::headline(Str::plural($model));
        $cols     = $this->displayColumns($columns);

        return $this->fw() === 'tailwind'
            ? $this->twIndex($model, $plural, $singular, $route, $title, $cols)
            : $this->bsIndex($model, $plural, $singular, $route, $title, $cols);
    }

    public function generateShow(string $model, array $columns, array $relationships): string
    {
        $singular = Str::camel($model);
        $route    = Str::kebab(Str::plural($model));
        $title    = Str::headline($model);
        $fields   = $this->showFields($singular, $columns);
        $relSects = $this->relSections($singular, $relationships);

        return $this->fw() === 'tailwind'
            ? $this->twShow($model, $singular, $route, $title, $fields, $relSects)
            : $this->bsShow($model, $singular, $route, $title, $fields, $relSects);
    }

    public function generateCreate(string $model, array $columns, array $relationships): string
    {
        $singular = Str::camel($model);
        $route    = Str::kebab(Str::plural($model));
        $title    = 'Create ' . Str::headline($model);
        $inputs   = $this->formInputs($columns, $relationships, null);

        return $this->fw() === 'tailwind'
            ? $this->twForm($model, $singular, $route, $title, $inputs, 'create')
            : $this->bsForm($model, $singular, $route, $title, $inputs, 'create');
    }

    public function generateEdit(string $model, array $columns, array $relationships): string
    {
        $singular = Str::camel($model);
        $route    = Str::kebab(Str::plural($model));
        $title    = 'Edit ' . Str::headline($model);
        $inputs   = $this->formInputs($columns, $relationships, $singular);

        return $this->fw() === 'tailwind'
            ? $this->twForm($model, $singular, $route, $title, $inputs, 'edit')
            : $this->bsForm($model, $singular, $route, $title, $inputs, 'edit');
    }

    // ── Bootstrap templates ───────────────────────────────────────────────────

    private function bsIndex(string $model, string $plural, string $singular, string $route, string $title, array $cols): string
    {
        $headers = collect($cols)->map(fn($c) => "                        <th>" . Str::headline($c['name']) . "</th>")->implode("\n");
        $cells   = collect($cols)->map(fn($c) => "                        <td>{{ \${$singular}->{$c['name']} ?? '—' }}</td>")->implode("\n");

        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{$title}</h1>
        <a href="{{ route('{$route}.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> New {$model}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card shadow-sm border-0">
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
                        <tr>
{$cells}
                            <td class="text-end pe-4">
                                <a href="{{ route('{$route}.show', \${$singular}) }}"
                                   class="btn btn-sm btn-outline-primary">View</a>
                                <a href="{{ route('{$route}.edit', \${$singular}) }}"
                                   class="btn btn-sm btn-outline-secondary">Edit</a>
                                <form action="{{ route('{$route}.destroy', \${$singular}) }}"
                                      method="POST" class="d-inline"
                                      onsubmit="return confirm('Delete this {$model}? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="{{ count(\${$plural}->first() ? array_keys(\${$plural}->first()->toArray()) : [0]) + 1 }}"
                                class="text-center text-muted py-5">
                                No {$title} found.
                                <a href="{{ route('{$route}.create') }}">Create the first one.</a>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if(\${$plural}->hasPages())
        <div class="card-footer bg-white">
            {{ \${$plural}->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
BLADE;
    }

    private function bsShow(string $model, string $singular, string $route, string $title, string $fields, string $relSects): string
    {
        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="{{ route('{$route}.index') }}" class="text-muted text-decoration-none small">
                ← All {$title}s
            </a>
            <h1 class="h3 mb-0 mt-1">{$title} Details</h1>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('{$route}.edit', \${$singular}) }}" class="btn btn-warning">Edit</a>
            <form action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST"
                  onsubmit="return confirm('Delete this {$model}?')">
                @csrf @method('DELETE')
                <button class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <dl class="row mb-0">
{$fields}
            </dl>
        </div>
    </div>

{$relSects}
</div>
@endsection
BLADE;
    }

    private function bsForm(string $model, string $singular, string $route, string $title, string $inputs, string $mode): string
    {
        $action = $mode === 'create'
            ? "{{ route('{$route}.store') }}"
            : "{{ route('{$route}.update', \${$singular}) }}";
        $method = $mode === 'edit' ? "@method('PUT')" : '';

        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <div class="d-flex align-items-center mb-4">
                <a href="{{ route('{$route}.index') }}" class="text-muted me-3">←</a>
                <h1 class="h3 mb-0">{$title}</h1>
            </div>

            @if(\$errors->any())
                <div class="alert alert-danger">
                    <strong>Please fix the following errors:</strong>
                    <ul class="mb-0 mt-2">
                        @foreach(\$errors->all() as \$error)
                            <li>{{ \$error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <form action="{$action}" method="POST" novalidate>
                        @csrf
                        {$method}

{$inputs}

                        <div class="d-flex justify-content-between align-items-center pt-3 mt-3 border-top">
                            <a href="{{ route('{$route}.index') }}" class="btn btn-light">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4">
                                Save {$model}
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

    // ── Tailwind templates ────────────────────────────────────────────────────

    private function twIndex(string $model, string $plural, string $singular, string $route, string $title, array $cols): string
    {
        $headers = collect($cols)->map(fn($c) => "                            <th scope=\"col\" class=\"px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider\">" . Str::headline($c['name']) . "</th>")->implode("\n");
        $cells   = collect($cols)->map(fn($c) => "                            <td class=\"px-6 py-4 whitespace-nowrap text-sm text-gray-900\">{{ \${$singular}->{$c['name']} ?? '—' }}</td>")->implode("\n");

        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">{$title}</h2>
            <a href="{{ route('{$route}.create') }}"
               class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                + New {$model}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="mb-4 p-4 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm">
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
                        <tr class="hover:bg-gray-50 transition">
{$cells}
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                <a href="{{ route('{$route}.show', \${$singular}) }}"
                                   class="text-indigo-600 hover:text-indigo-900">View</a>
                                <a href="{{ route('{$route}.edit', \${$singular}) }}"
                                   class="text-yellow-600 hover:text-yellow-900">Edit</a>
                                <form action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST"
                                      class="inline" onsubmit="return confirm('Delete this {$model}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="99" class="px-6 py-10 text-center text-gray-400">
                                No {$title} yet.
                                <a href="{{ route('{$route}.create') }}" class="text-indigo-500 hover:underline ml-1">Create one.</a>
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
        </div>
    </div>
</x-app-layout>
BLADE;
    }

    private function twShow(string $model, string $singular, string $route, string $title, string $fields, string $relSects): string
    {
        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <a href="{{ route('{$route}.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← All {$title}s</a>
                <h2 class="text-xl font-semibold text-gray-800 mt-1">{$title} Details</h2>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('{$route}.edit', \${$singular}) }}"
                   class="px-4 py-2 bg-yellow-500 text-white text-sm rounded-lg hover:bg-yellow-600">Edit</a>
                <form action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST"
                      onsubmit="return confirm('Delete this {$model}?')">
                    @csrf @method('DELETE')
                    <button class="px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700">Delete</button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
                <div class="p-4 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="font-medium text-gray-900">Information</h3>
                </div>
                <div class="p-6">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
{$fields}
                    </dl>
                </div>
            </div>

{$relSects}

        </div>
    </div>
</x-app-layout>
BLADE;
    }

    private function twForm(string $model, string $singular, string $route, string $title, string $inputs, string $mode): string
    {
        $action = $mode === 'create'
            ? "{{ route('{$route}.store') }}"
            : "{{ route('{$route}.update', \${$singular}) }}";
        $method = $mode === 'edit' ? "@method('PUT')" : '';

        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('{$route}.index') }}" class="text-gray-400 hover:text-gray-600">←</a>
            <h2 class="text-xl font-semibold text-gray-800">{$title}</h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">

            @if(\$errors->any())
                <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200">
                    <p class="font-medium text-red-800 text-sm mb-2">Please fix the following errors:</p>
                    <ul class="list-disc list-inside text-red-700 text-sm space-y-1">
                        @foreach(\$errors->all() as \$error)
                            <li>{{ \$error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                <form action="{$action}" method="POST" novalidate>
                    @csrf
                    {$method}
                    <div class="p-6 space-y-5">

{$inputs}

                    </div>
                    <div class="px-6 py-4 bg-gray-50 border-t flex items-center justify-between">
                        <a href="{{ route('{$route}.index') }}"
                           class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                        <button type="submit"
                                class="px-6 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                            Save {$model}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Field & input builders ────────────────────────────────────────────────

    private function displayColumns(array $columns): array
    {
        $skip = ['password', 'remember_token', 'two_factor_secret', '_id', 'deleted_at'];
        return array_values(array_filter(
            array_slice($columns, 0, 6),
            fn($c) => !in_array($c['name'], $skip)
        ));
    }

    private function showFields(string $singular, array $columns): string
    {
        $skip  = ['password', 'remember_token', 'two_factor_secret', '_id'];
        $lines = [];

        foreach ($columns as $col) {
            $name  = $col['name'];
            if (in_array($name, $skip)) continue;
            $label = Str::headline($name);

            // Format timestamps and booleans nicely
            $value = in_array($col['type_name'] ?? '', ['datetime', 'timestamp', 'date'])
                ? "{{ \${$singular}->{$name}?->format('d M Y H:i') ?? '—' }}"
                : "{{ \${$singular}->{$name} ?? '—' }}";

            if ($this->fw() === 'tailwind') {
                $lines[] = <<<BLADE
                        <div>
                            <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">{$label}</dt>
                            <dd class="mt-1 text-sm text-gray-900">{$value}</dd>
                        </div>
BLADE;
            } else {
                $lines[] = <<<BLADE
                <dt class="col-sm-3 text-muted fw-semibold">{$label}</dt>
                <dd class="col-sm-9">{$value}</dd>
BLADE;
            }
        }

        return implode("\n", $lines);
    }

    private function formInputs(array $columns, array $relationships, ?string $singular): string
    {
        $skip  = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];
        $lines = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;

            // FK column → <select> from relationship
            if (str_ends_with($name, '_id')) {
                $rel = $this->findRelationshipForFk($name, $relationships);
                $lines[] = $rel
                    ? $this->selectInput($name, $rel, $singular)
                    : $this->buildInput($name, $col, $singular);
                continue;
            }

            $lines[] = $this->buildInput($name, $col, $singular);
        }

        // belongsToMany checkboxes
        foreach ($relationships as $rel) {
            if ($rel['type'] !== 'belongsToMany') continue;
            $lines[] = $this->checkboxGroup($rel, $singular);
        }

        return implode("\n\n", $lines);
    }

    private function buildInput(string $name, array $col, ?string $singular): string
    {
        $label    = Str::headline($name);
        $type     = $this->inputType($name, $col['type_name'] ?? 'string');
        $rawType  = strtolower($col['type_name'] ?? 'string');
        $oldVal   = $singular
            ? "{{ old('{$name}', \${$singular}->{$name}) }}"
            : "{{ old('{$name}') }}";

        if ($type === 'textarea' || in_array($rawType, ['text', 'longtext', 'mediumtext'])) {
            return $this->textareaField($name, $label, $oldVal, $singular);
        }

        if ($type === 'checkbox') {
            return $this->checkboxField($name, $label, $singular);
        }

        return $this->inputField($name, $label, $type, $oldVal);
    }

    private function inputField(string $name, string $label, string $type, string $oldVal): string
    {
        if ($this->fw() === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1">{$label}</label>
                            <input type="{$type}" id="{$name}" name="{$name}" value="{$oldVal}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 @error('{$name}') border-red-400 bg-red-50 @enderror">
                            @error('{$name}')
                                <p class="mt-1 text-xs text-red-600">{{ \$message }}</p>
                            @enderror
                        </div>
BLADE;
        }

        return <<<BLADE
                        <div class="mb-4">
                            <label for="{$name}" class="form-label fw-semibold">{$label}</label>
                            <input type="{$type}" id="{$name}" name="{$name}" value="{$oldVal}"
                                   class="form-control @error('{$name}') is-invalid @enderror">
                            @error('{$name}')
                                <div class="invalid-feedback">{{ \$message }}</div>
                            @enderror
                        </div>
BLADE;
    }

    private function textareaField(string $name, string $label, string $oldVal, ?string $singular): string
    {
        $content = $singular
            ? "{{ old('{$name}', \${$singular}->{$name}) }}"
            : "{{ old('{$name}') }}";

        if ($this->fw() === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1">{$label}</label>
                            <textarea id="{$name}" name="{$name}" rows="4"
                                      class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 @error('{$name}') border-red-400 bg-red-50 @enderror">{$content}</textarea>
                            @error('{$name}')
                                <p class="mt-1 text-xs text-red-600">{{ \$message }}</p>
                            @enderror
                        </div>
BLADE;
        }

        return <<<BLADE
                        <div class="mb-4">
                            <label for="{$name}" class="form-label fw-semibold">{$label}</label>
                            <textarea id="{$name}" name="{$name}" rows="4"
                                      class="form-control @error('{$name}') is-invalid @enderror">{$content}</textarea>
                            @error('{$name}')
                                <div class="invalid-feedback">{{ \$message }}</div>
                            @enderror
                        </div>
BLADE;
    }

    private function checkboxField(string $name, string $label, ?string $singular): string
    {
        $checked = $singular ? "{{ old('{$name}', \${$singular}->{$name}) ? 'checked' : '' }}" : "{{ old('{$name}') ? 'checked' : '' }}";

        if ($this->fw() === 'tailwind') {
            return <<<BLADE
                        <div class="flex items-center gap-3">
                            <input type="hidden" name="{$name}" value="0">
                            <input type="checkbox" id="{$name}" name="{$name}" value="1" {$checked}
                                   class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <label for="{$name}" class="text-sm font-medium text-gray-700">{$label}</label>
                        </div>
BLADE;
        }

        return <<<BLADE
                        <div class="mb-4 form-check">
                            <input type="hidden" name="{$name}" value="0">
                            <input type="checkbox" id="{$name}" name="{$name}" value="1" {$checked}
                                   class="form-check-input @error('{$name}') is-invalid @enderror">
                            <label for="{$name}" class="form-check-label fw-semibold">{$label}</label>
                        </div>
BLADE;
    }

    private function selectInput(string $fkColumn, array $rel, ?string $singular): string
    {
        $label       = Str::headline(Str::beforeLast($fkColumn, '_id'));
        $related     = $rel['related'];
        $relatedVar  = Str::camel(Str::plural($related));
        $relatedItem = Str::camel(Str::singular($related));
        $selected    = $singular
            ? "{{ old('{$fkColumn}', \${$singular}->{$fkColumn}) == \${$relatedItem}->id ? 'selected' : '' }}"
            : "{{ old('{$fkColumn}') == \${$relatedItem}->id ? 'selected' : '' }}";

        if ($this->fw() === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label for="{$fkColumn}" class="block text-sm font-medium text-gray-700 mb-1">{$label}</label>
                            <select id="{$fkColumn}" name="{$fkColumn}"
                                    class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 @error('{$fkColumn}') border-red-400 @enderror">
                                <option value="">-- Select {$label} --</option>
                                @foreach(\${$relatedVar} as \${$relatedItem})
                                    <option value="{{ \${$relatedItem}->id }}" {$selected}>
                                        {{ \${$relatedItem}->name ?? \${$relatedItem}->title ?? \${$relatedItem}->id }}
                                    </option>
                                @endforeach
                            </select>
                            @error('{$fkColumn}')
                                <p class="mt-1 text-xs text-red-600">{{ \$message }}</p>
                            @enderror
                        </div>
BLADE;
        }

        return <<<BLADE
                        <div class="mb-4">
                            <label for="{$fkColumn}" class="form-label fw-semibold">{$label}</label>
                            <select id="{$fkColumn}" name="{$fkColumn}"
                                    class="form-select @error('{$fkColumn}') is-invalid @enderror">
                                <option value="">-- Select {$label} --</option>
                                @foreach(\${$relatedVar} as \${$relatedItem})
                                    <option value="{{ \${$relatedItem}->id }}" {$selected}>
                                        {{ \${$relatedItem}->name ?? \${$relatedItem}->title ?? \${$relatedItem}->id }}
                                    </option>
                                @endforeach
                            </select>
                            @error('{$fkColumn}')
                                <div class="invalid-feedback">{{ \$message }}</div>
                            @enderror
                        </div>
BLADE;
    }

    private function checkboxGroup(array $rel, ?string $singular): string
    {
        $method      = $rel['method'];
        $related     = $rel['related'];
        $relatedVar  = Str::camel(Str::plural($related));
        $relatedItem = Str::camel(Str::singular($related));
        $label       = Str::headline($method);
        $checked     = $singular
            ? "{{ in_array(\${$relatedItem}->id, old('{$method}_ids', \${$singular}->{$method}->pluck('id')->toArray())) ? 'checked' : '' }}"
            : "{{ in_array(\${$relatedItem}->id, old('{$method}_ids', [])) ? 'checked' : '' }}";

        if ($this->fw() === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{$label}</label>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach(\${$relatedVar} as \${$relatedItem})
                                    <label class="flex items-center gap-2 p-2 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer">
                                        <input type="checkbox" name="{$method}_ids[]" value="{{ \${$relatedItem}->id }}" {$checked}
                                               class="h-4 w-4 rounded border-gray-300 text-indigo-600">
                                        <span class="text-sm text-gray-700">
                                            {{ \${$relatedItem}->name ?? \${$relatedItem}->title ?? \${$relatedItem}->id }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
BLADE;
        }

        return <<<BLADE
                        <div class="mb-4">
                            <label class="form-label fw-semibold d-block">{$label}</label>
                            <div class="row g-2">
                                @foreach(\${$relatedVar} as \${$relatedItem})
                                    <div class="col-6 col-md-4">
                                        <div class="form-check border rounded p-2">
                                            <input type="checkbox" class="form-check-input"
                                                   name="{$method}_ids[]" value="{{ \${$relatedItem}->id }}" {$checked}
                                                   id="{$method}_{{ \${$relatedItem}->id }}">
                                            <label class="form-check-label" for="{$method}_{{ \${$relatedItem}->id }}">
                                                {{ \${$relatedItem}->name ?? \${$relatedItem}->title ?? \${$relatedItem}->id }}
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
BLADE;
    }

    private function relSections(string $singular, array $relationships): string
    {
        $sections = [];

        foreach ($relationships as $rel) {
            if (empty($rel['related']) || $rel['type'] === 'morphTo') continue;

            $method  = $rel['method'];
            $related = $rel['related'];
            $title   = Str::headline($method);
            $item    = Str::camel(Str::singular($related));
            $relRoute = Str::kebab(Str::plural($related));

            if (in_array($rel['type'], ['hasMany', 'belongsToMany', 'hasManyThrough'])) {
                if ($this->fw() === 'tailwind') {
                    $sections[] = <<<BLADE
            <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="font-medium text-gray-900">{$title}</h3>
                    <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-full">
                        {{ \${$singular}->{$method}->count() }}
                    </span>
                </div>
                <div class="divide-y divide-gray-50">
                    @forelse(\${$singular}->{$method} as \${$item})
                        <div class="px-6 py-3 flex justify-between items-center hover:bg-gray-50">
                            <span class="text-sm text-gray-900">
                                {{ \${$item}->name ?? \${$item}->title ?? '#' . \${$item}->id }}
                            </span>
                        </div>
                    @empty
                        <div class="px-6 py-4 text-sm text-gray-400">No {$title} yet.</div>
                    @endforelse
                </div>
            </div>
BLADE;
                } else {
                    $sections[] = <<<BLADE
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>{$title}</strong>
            <span class="badge bg-secondary">{{ \${$singular}->{$method}->count() }}</span>
        </div>
        <ul class="list-group list-group-flush">
            @forelse(\${$singular}->{$method} as \${$item})
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    {{ \${$item}->name ?? \${$item}->title ?? '#' . \${$item}->id }}
                </li>
            @empty
                <li class="list-group-item text-muted">No {$title} yet.</li>
            @endforelse
        </ul>
    </div>
BLADE;
                }
            } else {
                if ($this->fw() === 'tailwind') {
                    $sections[] = <<<BLADE
            <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="font-medium text-gray-900">{$title}</h3>
                </div>
                <div class="px-6 py-4">
                    @if(\${$singular}->{$method})
                        <span class="text-sm text-gray-900">
                            {{ \${$singular}->{$method}->name ?? \${$singular}->{$method}->title ?? '#' . \${$singular}->{$method}->id }}
                        </span>
                    @else
                        <span class="text-sm text-gray-400">None.</span>
                    @endif
                </div>
            </div>
BLADE;
                } else {
                    $sections[] = <<<BLADE
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white"><strong>{$title}</strong></div>
        <div class="card-body">
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
        }

        return implode("\n\n", $sections);
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private function fw(): string
    {
        return $this->framework->detect();
    }

    private function inputType(string $name, string $type): string
    {
        if (str_contains($name, 'email'))                               return 'email';
        if ($name === 'password' || str_ends_with($name, '_password'))  return 'password';
        if (str_contains($name, 'url') || $name === 'website')          return 'url';
        if (str_ends_with($name, '_at') || $type === 'date')            return 'date';
        if (in_array($type, ['datetime', 'timestamp']))                  return 'datetime-local';
        if (in_array($type, ['int', 'integer', 'bigint', 'smallint']))   return 'number';
        if (in_array($type, ['decimal', 'float', 'double', 'numeric'])) return 'number';
        if (in_array($type, ['boolean', 'tinyint', 'bool']))             return 'checkbox';
        if (in_array($type, ['text', 'longtext', 'mediumtext']))         return 'textarea';
        return 'text';
    }

    private function findRelationshipForFk(string $fkColumn, array $relationships): ?array
    {
        foreach ($relationships as $rel) {
            if ($rel['type'] === 'belongsTo' && ($rel['foreign_key'] ?? '') === $fkColumn) {
                return $rel;
            }
            // fallback: guess from column name
            $guessedModel = Str::studly(Str::beforeLast($fkColumn, '_id'));
            if ($rel['type'] === 'belongsTo' && $rel['related'] === $guessedModel) {
                return $rel;
            }
        }
        return null;
    }
}
