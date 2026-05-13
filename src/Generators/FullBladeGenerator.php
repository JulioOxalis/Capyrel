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
        $plural    = Str::plural(Str::camel($model));
        $singular  = Str::camel($model);
        $route     = Str::kebab(Str::plural($model));
        $title     = Str::headline(Str::plural($model));
        $headers   = $this->tableHeaders($columns);
        $cells     = $this->tableCells($singular, $columns);

        return $this->framework->isBootstrap()
            ? $this->bootstrapIndex($model, $plural, $singular, $route, $title, $headers, $cells)
            : ($this->framework->isTailwind()
                ? $this->tailwindIndex($model, $plural, $singular, $route, $title, $headers, $cells)
                : $this->plainIndex($model, $plural, $singular, $route, $title, $headers, $cells));
    }

    public function generateShow(string $model, array $columns, array $relationships): string
    {
        $singular  = Str::camel($model);
        $route     = Str::kebab(Str::plural($model));
        $title     = Str::headline($model);
        $fields    = $this->showFields($singular, $columns);
        $relBlocks = $this->relationshipBlocks($singular, $relationships);

        return $this->framework->isBootstrap()
            ? $this->bootstrapShow($model, $singular, $route, $title, $fields, $relBlocks)
            : ($this->framework->isTailwind()
                ? $this->tailwindShow($model, $singular, $route, $title, $fields, $relBlocks)
                : $this->plainShow($model, $singular, $route, $title, $fields, $relBlocks));
    }

    public function generateCreate(string $model, array $columns, array $relationships): string
    {
        $singular = Str::camel($model);
        $route    = Str::kebab(Str::plural($model));
        $title    = 'Create ' . Str::headline($model);
        $inputs   = $this->formInputs($columns, $relationships, null);

        return $this->framework->isBootstrap()
            ? $this->bootstrapForm($model, $singular, $route, $title, $inputs, 'create')
            : ($this->framework->isTailwind()
                ? $this->tailwindForm($model, $singular, $route, $title, $inputs, 'create')
                : $this->plainForm($model, $singular, $route, $title, $inputs, 'create'));
    }

    public function generateEdit(string $model, array $columns, array $relationships): string
    {
        $singular = Str::camel($model);
        $route    = Str::kebab(Str::plural($model));
        $title    = 'Edit ' . Str::headline($model);
        $inputs   = $this->formInputs($columns, $relationships, $singular);

        return $this->framework->isBootstrap()
            ? $this->bootstrapForm($model, $singular, $route, $title, $inputs, 'edit')
            : ($this->framework->isTailwind()
                ? $this->tailwindForm($model, $singular, $route, $title, $inputs, 'edit')
                : $this->plainForm($model, $singular, $route, $title, $inputs, 'edit'));
    }

    // ── Bootstrap templates ───────────────────────────────────────────────────

    private function bootstrapIndex(string $model, string $plural, string $singular, string $route, string $title, string $headers, string $cells): string
    {
        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">{$title}</h1>
        <a href="{{ route('{$route}.create') }}" class="btn btn-primary">
            + Create {$model}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
{$headers}
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (\${$plural} as \${$singular})
                    <tr>
{$cells}
                        <td class="text-end">
                            <a href="{{ route('{$route}.show', \${$singular}) }}" class="btn btn-sm btn-outline-info">View</a>
                            <a href="{{ route('{$route}.edit', \${$singular}) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                            <form action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete this {$model}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="99" class="text-center text-muted py-4">No {$title} found.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ \${$plural}->links() }}
    </div>
</div>
@endsection
BLADE;
    }

    private function bootstrapShow(string $model, string $singular, string $route, string $title, string $fields, string $relBlocks): string
    {
        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">{$title} Details</h1>
        <div>
            <a href="{{ route('{$route}.edit', \${$singular}) }}" class="btn btn-warning">Edit</a>
            <form action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST" class="d-inline"
                  onsubmit="return confirm('Delete this {$model}?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
            <a href="{{ route('{$route}.index') }}" class="btn btn-secondary">Back</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <dl class="row mb-0">
{$fields}
            </dl>
        </div>
    </div>

{$relBlocks}
</div>
@endsection
BLADE;
    }

    private function bootstrapForm(string $model, string $singular, string $route, string $title, string $inputs, string $mode): string
    {
        $action  = $mode === 'create'
            ? "{{ route('{$route}.store') }}"
            : "{{ route('{$route}.update', \${$singular}) }}";
        $method  = $mode === 'edit' ? "@method('PUT')" : '';
        $back    = "{{ route('{$route}.index') }}";

        return <<<BLADE
@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h4 class="mb-0">{$title}</h4>
                </div>
                <div class="card-body">
                    <form action="{$action}" method="POST">
                        @csrf
                        {$method}

{$inputs}

                        <div class="d-flex justify-content-between mt-4">
                            <a href="{$back}" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save {$model}</button>
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

    private function tailwindIndex(string $model, string $plural, string $singular, string $route, string $title, string $headers, string $cells): string
    {
        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{$title}</h2>
            <a href="{{ route('{$route}.create') }}"
               class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                + Create {$model}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
{$headers}
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse (\${$plural} as \${$singular})
                        <tr class="hover:bg-gray-50">
{$cells}
                            <td class="px-6 py-4 text-right text-sm space-x-2">
                                <a href="{{ route('{$route}.show', \${$singular}) }}" class="text-blue-600 hover:text-blue-900">View</a>
                                <a href="{{ route('{$route}.edit', \${$singular}) }}" class="text-yellow-600 hover:text-yellow-900">Edit</a>
                                <form action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST" class="inline"
                                      onsubmit="return confirm('Delete this {$model}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="99" class="px-6 py-8 text-center text-gray-400">No {$title} found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-6 py-4 border-t">
                    {{ \${$plural}->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
BLADE;
    }

    private function tailwindShow(string $model, string $singular, string $route, string $title, string $fields, string $relBlocks): string
    {
        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{$title} Details</h2>
            <div class="space-x-2">
                <a href="{{ route('{$route}.edit', \${$singular}) }}"
                   class="inline-flex items-center px-4 py-2 bg-yellow-500 text-white text-sm rounded-md hover:bg-yellow-600">Edit</a>
                <form action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST" class="inline"
                      onsubmit="return confirm('Delete this {$model}?')">
                    @csrf @method('DELETE')
                    <button class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm rounded-md hover:bg-red-700">Delete</button>
                </form>
                <a href="{{ route('{$route}.index') }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded-md hover:bg-gray-300">Back</a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
{$fields}
                    </dl>
                </div>
            </div>

{$relBlocks}
        </div>
    </div>
</x-app-layout>
BLADE;
    }

    private function tailwindForm(string $model, string $singular, string $route, string $title, string $inputs, string $mode): string
    {
        $action = $mode === 'create'
            ? "{{ route('{$route}.store') }}"
            : "{{ route('{$route}.update', \${$singular}) }}";
        $method = $mode === 'edit' ? "@method('PUT')" : '';
        $back   = "{{ route('{$route}.index') }}";

        return <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{$title}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form action="{$action}" method="POST" class="space-y-6">
                        @csrf
                        {$method}

{$inputs}

                        <div class="flex items-center justify-between pt-4 border-t">
                            <a href="{$back}" class="text-gray-600 hover:text-gray-800">Cancel</a>
                            <button type="submit"
                                    class="inline-flex items-center px-6 py-2 bg-indigo-600 text-white font-medium rounded-md hover:bg-indigo-700">
                                Save {$model}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Plain HTML fallback ───────────────────────────────────────────────────

    private function plainIndex(string $model, string $plural, string $singular, string $route, string $title, string $headers, string $cells): string
    {
        return <<<BLADE
@extends('layouts.app')

@section('content')
<h1>{$title}</h1>
<a href="{{ route('{$route}.create') }}">Create {$model}</a>

@if(session('success'))
    <p style="color:green">{{ session('success') }}</p>
@endif

<table border="1" cellpadding="8" cellspacing="0" width="100%">
    <thead>
        <tr>
{$headers}
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse (\${$plural} as \${$singular})
        <tr>
{$cells}
            <td>
                <a href="{{ route('{$route}.show', \${$singular}) }}">View</a> |
                <a href="{{ route('{$route}.edit', \${$singular}) }}">Edit</a> |
                <form action="{{ route('{$route}.destroy', \${$singular}) }}" method="POST" style="display:inline">
                    @csrf @method('DELETE')
                    <button onclick="return confirm('Delete?')">Delete</button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="99">No records.</td></tr>
        @endforelse
    </tbody>
</table>
{{ \${$plural}->links() }}
@endsection
BLADE;
    }

    private function plainShow(string $model, string $singular, string $route, string $title, string $fields, string $relBlocks): string
    {
        return <<<BLADE
@extends('layouts.app')

@section('content')
<h1>{$title}</h1>
<a href="{{ route('{$route}.edit', \${$singular}) }}">Edit</a> |
<a href="{{ route('{$route}.index') }}">Back</a>
<hr>
{$fields}
{$relBlocks}
@endsection
BLADE;
    }

    private function plainForm(string $model, string $singular, string $route, string $title, string $inputs, string $mode): string
    {
        $action = $mode === 'create'
            ? "{{ route('{$route}.store') }}"
            : "{{ route('{$route}.update', \${$singular}) }}";
        $method = $mode === 'edit' ? "@method('PUT')" : '';

        return <<<BLADE
@extends('layouts.app')

@section('content')
<h1>{$title}</h1>
<form action="{$action}" method="POST">
    @csrf
    {$method}

{$inputs}

    <button type="submit">Save {$model}</button>
    <a href="{{ route('{$route}.index') }}">Cancel</a>
</form>
@endsection
BLADE;
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function tableHeaders(array $columns): string
    {
        $skip  = ['password', 'remember_token', 'two_factor_secret', '_id'];
        $lines = [];

        foreach (array_slice($columns, 0, 5) as $col) {
            if (in_array($col['name'], $skip)) continue;
            $label  = Str::headline($col['name']);
            $lines[] = $this->framework->isTailwind()
                ? "                            <th class=\"px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase\">{$label}</th>"
                : "                        <th>{$label}</th>";
        }

        return implode("\n", $lines);
    }

    private function tableCells(string $singular, array $columns): string
    {
        $skip  = ['password', 'remember_token', 'two_factor_secret', '_id'];
        $lines = [];

        foreach (array_slice($columns, 0, 5) as $col) {
            if (in_array($col['name'], $skip)) continue;
            $field   = $col['name'];
            $lines[] = $this->framework->isTailwind()
                ? "                            <td class=\"px-6 py-4 text-sm text-gray-900\">{{ \${$singular}->{$field} }}</td>"
                : "                        <td>{{ \${$singular}->{$field} }}</td>";
        }

        return implode("\n", $lines);
    }

    private function showFields(string $singular, array $columns): string
    {
        $skip  = ['password', 'remember_token', 'two_factor_secret', '_id'];
        $lines = [];

        foreach ($columns as $col) {
            if (in_array($col['name'], $skip)) continue;
            $field = $col['name'];
            $label = Str::headline($field);

            if ($this->framework->isTailwind()) {
                $lines[] = <<<BLADE
                        <div>
                            <dt class="text-sm font-medium text-gray-500">{$label}</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ \${$singular}->{$field} ?? '—' }}</dd>
                        </div>
BLADE;
            } elseif ($this->framework->isBootstrap()) {
                $lines[] = <<<BLADE
                <dt class="col-sm-3">{$label}</dt>
                <dd class="col-sm-9">{{ \${$singular}->{$field} ?? '—' }}</dd>
BLADE;
            } else {
                $lines[] = "<p><strong>{$label}:</strong> {{ \${$singular}->{$field} ?? '—' }}</p>";
            }
        }

        return implode("\n", $lines);
    }

    private function formInputs(array $columns, array $relationships, ?string $singular): string
    {
        $skip  = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at', 'two_factor_secret'];
        $lines = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;

            $input = $this->buildInput($name, $col['type_name'] ?? 'string', $singular);
            $lines[] = $input;
        }

        return implode("\n\n", $lines);
    }

    private function buildInput(string $name, string $type, ?string $singular): string
    {
        $label    = Str::headline($name);
        $old      = $singular ? "{{ old('{$name}', \${$singular}->{$name}) }}" : "{{ old('{$name}') }}";
        $inputType = $this->mapInputType($name, $type);

        if ($this->framework->isTailwind()) {
            return $this->tailwindInput($name, $label, $inputType, $old, $type);
        }

        if ($this->framework->isBootstrap()) {
            return $this->bootstrapInput($name, $label, $inputType, $old, $type);
        }

        return $this->plainInput($name, $label, $inputType, $old, $type);
    }

    private function tailwindInput(string $name, string $label, string $type, string $old, string $rawType): string
    {
        $base    = "class=\"mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm @error('{$name}') border-red-500 @enderror\"";
        $error   = "@error('{$name}')\n                    <p class=\"mt-1 text-sm text-red-600\">{{ \$message }}</p>\n                @enderror";

        if ($rawType === 'text' || $rawType === 'longtext' || $rawType === 'mediumtext') {
            return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700">{$label}</label>
                            <textarea id="{$name}" name="{$name}" rows="4" {$base}>{{ old('{$name}', isset(\$model) ? \$model->{$name} : '') }}</textarea>
                            {$error}
                        </div>
BLADE;
        }

        if ($name === 'password' || str_ends_with($name, '_password')) {
            return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700">{$label}</label>
                            <input type="password" id="{$name}" name="{$name}" {$base}>
                            {$error}
                        </div>
BLADE;
        }

        return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700">{$label}</label>
                            <input type="{$type}" id="{$name}" name="{$name}" value="{$old}" {$base}>
                            {$error}
                        </div>
BLADE;
    }

    private function bootstrapInput(string $name, string $label, string $type, string $old, string $rawType): string
    {
        $error = "@error('{$name}')\n                        <div class=\"invalid-feedback\">{{ \$message }}</div>\n                    @enderror";

        if ($rawType === 'text' || $rawType === 'longtext') {
            return <<<BLADE
                    <div class="mb-3">
                        <label for="{$name}" class="form-label">{$label}</label>
                        <textarea id="{$name}" name="{$name}" rows="4"
                                  class="form-control @error('{$name}') is-invalid @enderror">{{ old('{$name}', isset(\$model) ? \$model->{$name} : '') }}</textarea>
                        {$error}
                    </div>
BLADE;
        }

        if ($name === 'password' || str_ends_with($name, '_password')) {
            return <<<BLADE
                    <div class="mb-3">
                        <label for="{$name}" class="form-label">{$label}</label>
                        <input type="password" id="{$name}" name="{$name}"
                               class="form-control @error('{$name}') is-invalid @enderror">
                        {$error}
                    </div>
BLADE;
        }

        return <<<BLADE
                    <div class="mb-3">
                        <label for="{$name}" class="form-label">{$label}</label>
                        <input type="{$type}" id="{$name}" name="{$name}" value="{$old}"
                               class="form-control @error('{$name}') is-invalid @enderror">
                        {$error}
                    </div>
BLADE;
    }

    private function plainInput(string $name, string $label, string $type, string $old, string $rawType): string
    {
        if ($rawType === 'text' || $rawType === 'longtext') {
            return "<p>\n    <label>{$label}</label><br>\n    <textarea name=\"{$name}\" rows=\"4\">{{ old('{$name}') }}</textarea>\n</p>";
        }

        return "<p>\n    <label>{$label}</label><br>\n    <input type=\"{$type}\" name=\"{$name}\" value=\"{$old}\">\n    @error('{$name}') <span style=\"color:red\">{{ \$message }}</span> @enderror\n</p>";
    }

    private function mapInputType(string $name, string $type): string
    {
        if (str_contains($name, 'email'))                         return 'email';
        if (str_contains($name, 'url') || $name === 'website')   return 'url';
        if ($name === 'password' || str_ends_with($name, '_password')) return 'password';
        if (str_ends_with($name, '_at') || $type === 'date')     return 'date';
        if (in_array($type, ['datetime', 'timestamp']))           return 'datetime-local';
        if (in_array($type, ['integer', 'bigint', 'smallint', 'int'])) return 'number';
        if (in_array($type, ['decimal', 'float', 'double', 'numeric'])) return 'number';
        if (in_array($type, ['boolean', 'tinyint']))              return 'checkbox';
        return 'text';
    }

    private function relationshipBlocks(string $singular, array $relationships): string
    {
        $blocks = [];

        foreach ($relationships as $rel) {
            if (empty($rel['related']) || $rel['type'] === 'morphTo') continue;

            $method  = $rel['method'];
            $related = $rel['related'];
            $type    = $rel['type'];
            $title   = Str::headline($method);

            if (in_array($type, ['hasMany', 'belongsToMany', 'hasManyThrough'])) {
                $blocks[] = $this->framework->isTailwind()
                    ? $this->twRelMany($title, $singular, $method, $related)
                    : $this->bsRelMany($title, $singular, $method, $related);
            } else {
                $blocks[] = $this->framework->isTailwind()
                    ? $this->twRelOne($title, $singular, $method, $related)
                    : $this->bsRelOne($title, $singular, $method, $related);
            }
        }

        return implode("\n\n", $blocks);
    }

    private function twRelMany(string $title, string $singular, string $method, string $related): string
    {
        $item = Str::camel(Str::singular($related));
        return <<<BLADE
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b">
                    <h3 class="font-semibold text-gray-700">{$title}</h3>
                </div>
                <div class="p-6">
                    @forelse (\${$singular}->{$method} as \${$item})
                        <div class="py-2 border-b last:border-0">{{ \${$item}->name ?? \${$item}->id }}</div>
                    @empty
                        <p class="text-gray-400">No {$title} yet.</p>
                    @endforelse
                </div>
            </div>
BLADE;
    }

    private function twRelOne(string $title, string $singular, string $method, string $related): string
    {
        return <<<BLADE
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b">
                    <h3 class="font-semibold text-gray-700">{$title}</h3>
                </div>
                <div class="p-6">
                    @if(\${$singular}->{$method})
                        <p>{{ \${$singular}->{$method}->name ?? \${$singular}->{$method}->id }}</p>
                    @else
                        <p class="text-gray-400">No {$title}.</p>
                    @endif
                </div>
            </div>
BLADE;
    }

    private function bsRelMany(string $title, string $singular, string $method, string $related): string
    {
        $item = Str::camel(Str::singular($related));
        return <<<BLADE
<div class="card shadow-sm mb-4">
    <div class="card-header"><strong>{$title}</strong></div>
    <ul class="list-group list-group-flush">
        @forelse (\${$singular}->{$method} as \${$item})
            <li class="list-group-item">{{ \${$item}->name ?? \${$item}->id }}</li>
        @empty
            <li class="list-group-item text-muted">No {$title} yet.</li>
        @endforelse
    </ul>
</div>
BLADE;
    }

    private function bsRelOne(string $title, string $singular, string $method, string $related): string
    {
        return <<<BLADE
<div class="card shadow-sm mb-4">
    <div class="card-header"><strong>{$title}</strong></div>
    <div class="card-body">
        @if(\${$singular}->{$method})
            {{ \${$singular}->{$method}->name ?? \${$singular}->{$method}->id }}
        @else
            <span class="text-muted">None.</span>
        @endif
    </div>
</div>
BLADE;
    }
}
