<?php

namespace Julio\Capyrel\UI\Adapters;

use Illuminate\Support\Str;
use Julio\Capyrel\UI\Contracts\UiAdapter;

/**
 * blade-marketplace adapter — commerce-first UI.
 *
 * list   → product grid with image placeholder, price badge, quick-buy
 * create → product form: pricing section, inventory section, category picker
 * show   → product detail: image hero, price box, specs table, related items
 */
class BladeMarketplaceAdapter implements UiAdapter
{
    private string $stamp = "{{-- capyrel:ui:marketplace — remove with: php artisan capyrel:clean --views --}}\n";

    public function name(): string
    {
        return 'blade-marketplace';
    }

    public function capabilities(): array
    {
        return ['list', 'create', 'show', 'grid', 'pricing', 'inventory'];
    }

    // ── List — product grid ───────────────────────────────────────────────────

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
        $titleField = $meta['title_field'] ?? ($fields[1] ?? 'name');
        $priceField = $this->detectField($fields, ['price', 'amount', 'cost', 'rate']);
        $imageField = $this->detectField($fields, ['image', 'photo', 'avatar', 'thumbnail', 'cover']);

        $priceCell = $priceField
            ? "<p class=\"text-lg font-bold text-gray-900\">\${{ number_format(\${$singular}->{$priceField}, 2) }}</p>"
            : '';
        $imageCell = $imageField
            ? "@if(\${$singular}->{$imageField})\n                <img src=\"{{ asset('storage/' . \${$singular}->{$imageField}) }}\" class=\"w-full h-full object-cover\" />\n                @else\n                <div class=\"w-full h-full flex items-center justify-center\">\n                    <svg class=\"w-12 h-12 text-gray-300\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"1\" d=\"M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\"/></svg>\n                </div>\n                @endif"
            : "<div class=\"w-full h-full flex items-center justify-center\">\n                <svg class=\"w-12 h-12 text-gray-300\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"1\" d=\"M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\"/></svg>\n            </div>";

        $stockBadge = $this->detectField($fields, ['stock', 'quantity', 'inventory', 'qty'])
            ? "<span class=\"absolute top-2 right-2 inline-flex px-1.5 py-0.5 rounded text-xs font-medium {{ \${$singular}->stock > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}\">{{ \${$singular}->stock > 0 ? 'In stock' : 'Out of stock' }}</span>"
            : '';

        $catChip = isset($relPrev['category']) || isset($relPrev['categories'])
            ? "<p class=\"text-xs text-indigo-500 mb-1\">{{ \${$singular}->category?->name ?? '' }}</p>"
            : '';

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900">{$title}</h2>
                <p class="text-sm text-gray-500">{{ \${$plural}->total() }} items</p>
            </div>
            <div class="flex items-center gap-3">
                {{-- View toggle --}}
                <div class="flex border border-gray-200 rounded-lg overflow-hidden">
                    <button class="p-2 bg-indigo-50 text-indigo-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    </button>
                </div>
                <a href="{{ route('{$route}.create') }}"
                   class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add {$entity}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        {{-- Search + filter bar --}}
        <form method="GET" class="mb-6 flex gap-3 items-center">
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Search {$title}…"
                   class="flex-1 max-w-xs border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
            <select name="sort" class="border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                <option value="">Sort by</option>
                <option value="price_asc" {{ request('sort') === 'price_asc' ? 'selected' : '' }}>Price: Low → High</option>
                <option value="price_desc" {{ request('sort') === 'price_desc' ? 'selected' : '' }}>Price: High → Low</option>
                <option value="newest" {{ request('sort') === 'newest' ? 'selected' : '' }}>Newest</option>
            </select>
            <button type="submit" class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">Apply</button>
        </form>

        {{-- Product grid --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
            @forelse (\${$plural} as \${$singular})
            <div class="group bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition overflow-hidden">

                {{-- Image --}}
                <div class="relative aspect-square bg-gray-50 overflow-hidden">
                    {$imageCell}
                    {$stockBadge}
                </div>

                {{-- Info --}}
                <div class="p-3">
                    {$catChip}
                    <a href="{{ route('{$route}.show', \${$singular}) }}"
                       class="block text-sm font-medium text-gray-900 hover:text-indigo-600 line-clamp-2 leading-snug mb-2">
                        {{ \${$singular}->{$titleField} }}
                    </a>
                    {$priceCell}

                    <div class="mt-3 flex items-center gap-1.5">
                        <a href="{{ route('{$route}.show', \${$singular}) }}"
                           class="flex-1 text-center py-1.5 text-xs font-medium text-indigo-600 border border-indigo-200 rounded-lg hover:bg-indigo-50 transition">
                            View
                        </a>
                        <a href="{{ route('{$route}.edit', \${$singular}) }}"
                           class="flex-1 text-center py-1.5 text-xs font-medium text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50 transition">
                            Edit
                        </a>
                    </div>
                </div>
            </div>
            @empty
            <div class="col-span-full py-20 text-center text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                <p>No {$title} yet.</p>
                <a href="{{ route('{$route}.create') }}" class="mt-2 inline-block text-indigo-500 hover:underline text-sm">Add your first {$entity}</a>
            </div>
            @endforelse
        </div>

        <div class="mt-6">{{ \${$plural}->withQueryString()->links() }}</div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Create — product form ─────────────────────────────────────────────────

    public function renderCreate(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['create'] ?? [];
        $fields    = $screen['fields'] ?? [];
        $relations = $screen['relations'] ?? [];
        $route     = Str::kebab(Str::plural($entity));
        $meta      = $contract['meta'] ?? [];
        $titleField = $meta['title_field'] ?? 'name';
        $descField  = $meta['description_field'];

        // Bucket fields into groups
        $pricing   = array_values(array_filter($fields, fn($f) => $this->isPricingField($f)));
        $inventory = array_values(array_filter($fields, fn($f) => $this->isInventoryField($f)));
        $media     = array_values(array_filter($fields, fn($f) => $this->isMediaField($f)));
        $other     = array_values(array_filter($fields, fn($f) =>
            !$this->isPricingField($f) && !$this->isInventoryField($f) && !$this->isMediaField($f)
            && $f !== $descField
        ));

        $mainInputs    = $this->productInputs($other, $titleField, $descField);
        $pricingInputs = $this->productInputs($pricing);
        $stockInputs   = $this->productInputs($inventory);
        $mediaInputs   = $this->mediaInputs($media);
        $relInputs     = $this->productRelationInputs($relations);

        $pricingSection = $pricing ? <<<BLADE

            {{-- Pricing --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Pricing</h3>
                <div class="grid grid-cols-2 gap-4">
{$pricingInputs}
                </div>
            </div>
BLADE : '';

        $stockSection = $inventory ? <<<BLADE

            {{-- Inventory --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Inventory</h3>
                <div class="grid grid-cols-2 gap-4">
{$stockInputs}
                </div>
            </div>
BLADE : '';

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('{$route}.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h2 class="text-lg font-semibold text-gray-900">Add {$entity}</h2>
        </div>
    </x-slot>

    <div class="py-6 px-4 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('{$route}.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-5">

                {{-- Left column: main content --}}
                <div class="lg:col-span-2 space-y-5">
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Product Info</h3>
                        <div class="space-y-4">
{$mainInputs}
                        </div>
                    </div>
{$pricingSection}
{$stockSection}
                </div>

                {{-- Right column: media + relations --}}
                <div class="space-y-5">
                    @if(!empty(\$media))
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Media</h3>
{$mediaInputs}
                    </div>
                    @endif

                    @if(!empty(\$relations))
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Categorise</h3>
                        <div class="space-y-4">
{$relInputs}
                        </div>
                    </div>
                    @endif

                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-2">
                        <button type="submit"
                                class="w-full px-4 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition">
                            Publish {$entity}
                        </button>
                        <a href="{{ route('{$route}.index') }}"
                           class="block w-full text-center px-4 py-2.5 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Show — product detail ─────────────────────────────────────────────────

    public function renderShow(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['show'] ?? [];
        $relations = $screen['relations'] ?? [];
        $actions   = $screen['actions'] ?? ['edit', 'delete'];
        $meta      = $contract['meta'] ?? [];
        $fields    = $contract['screens']['list']['fields'] ?? [];
        $route     = Str::kebab(Str::plural($entity));
        $singular  = Str::camel($entity);
        $titleField = $meta['title_field'] ?? 'name';
        $descField  = $meta['description_field'];
        $priceField = $this->detectField($fields, ['price', 'amount', 'cost', 'rate']);
        $imageField = $this->detectField($fields, ['image', 'photo', 'avatar', 'thumbnail', 'cover']);
        $stockField = $this->detectField($fields, ['stock', 'quantity', 'inventory', 'qty']);

        $imageSrc = $imageField
            ? "@if(\${$singular}->{$imageField})\n                <img src=\"{{ asset('storage/' . \${$singular}->{$imageField}) }}\" class=\"w-full h-full object-cover\" />\n                @endif"
            : '';
        $descBlock = $descField
            ? "<p class=\"text-gray-600 text-sm mt-2\">{{ \${$singular}->{$descField} }}</p>"
            : '';
        $priceBox = $priceField
            ? "<div class=\"text-3xl font-bold text-gray-900\">\${{ number_format(\${$singular}->{$priceField}, 2) }}</div>"
            : '';
        $stockBadge = $stockField
            ? "<span class=\"inline-flex px-2.5 py-1 rounded-full text-sm font-medium {{ \${$singular}->{$stockField} > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}\">{{ \${$singular}->{$stockField} > 0 ? \${$singular}->{$stockField} . ' in stock' : 'Out of stock' }}</span>"
            : '';

        $relPanels     = $this->showRelationPanels($singular, $relations);
        $actionButtons = $this->marketplaceActionButtons($singular, $route, $entity, $actions);

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('{$route}.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h2 class="text-lg font-semibold text-gray-900">{{ \${$singular}->{$titleField} }}</h2>
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">

            {{-- Image panel --}}
            <div class="aspect-square bg-gray-100 rounded-2xl overflow-hidden">
                {$imageSrc}
            </div>

            {{-- Detail + price box --}}
            <div class="flex flex-col justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ \${$singular}->{$titleField} }}</h1>
                    {$descBlock}

                    <div class="mt-6 space-y-4">
                        {$priceBox}
                        {$stockBadge}
                    </div>

                    {{-- Specs table --}}
                    <dl class="mt-6 divide-y divide-gray-100 text-sm">
                        @foreach (\${$singular}->getAttributes() as \$key => \$value)
                            @if (!in_array(\$key, ['password', 'remember_token', '{$descField}', '{$imageField}']) && !\$value instanceof \Illuminate\Database\Eloquent\Collection)
                            <div class="flex py-2">
                                <dt class="w-1/3 font-medium text-gray-500 capitalize">{{ str_replace('_', ' ', \$key) }}</dt>
                                <dd class="w-2/3 text-gray-900">{{ \$value ?? '—' }}</dd>
                            </div>
                            @endif
                        @endforeach
                    </dl>
                </div>

                <div class="mt-6 flex gap-3">
                    {$actionButtons}
                </div>
            </div>
        </div>

        {{-- Relation panels below --}}
        @if(!empty(\$relations))
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            {$relPanels}
        </div>
        @endif
    </div>
</x-app-layout>
BLADE;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function productInputs(array $fields, ?string $titleField = null, ?string $descField = null): string
    {
        $html = '';
        foreach ($fields as $field) {
            $label = Str::headline($field);
            $type  = $this->inferType($field);
            $isPrimary = $field === $titleField;

            if ($field === $descField) {
                $html .= <<<BLADE
                        <div>
                            <label for="{$field}" class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{$label}</label>
                            <textarea name="{$field}" id="{$field}" rows="4" class="block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500">{{ old('{$field}') }}</textarea>
                            @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
                        </div>

BLADE;
                continue;
            }

            $prefix = str_contains(strtolower($field), 'price') || str_contains(strtolower($field), 'amount')
                ? '<span class="inline-flex items-center px-3 border border-r-0 border-gray-300 bg-gray-50 text-gray-500 text-sm rounded-l-lg">$</span>'
                : '';
            $inputClass = $prefix
                ? 'flex-1 block min-w-0 border-gray-300 rounded-r-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500'
                : 'block w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500';
            $wrapper = $prefix ? '<div class="flex">' . $prefix : '';
            $wrapperClose = $prefix ? '</div>' : '';

            $html .= <<<BLADE
                        <div>
                            <label for="{$field}" class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{$label}</label>
                            {$wrapper}<input type="{$type}" name="{$field}" id="{$field}" value="{{ old('{$field}') }}" class="{$inputClass}">{$wrapperClose}
                            @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
                        </div>

BLADE;
        }
        return $html;
    }

    private function mediaInputs(array $fields): string
    {
        $html = '';
        foreach ($fields as $field) {
            $label = Str::headline($field);
            $html .= <<<BLADE
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{$label}</label>
                            <div class="border-2 border-dashed border-gray-200 rounded-xl p-6 text-center hover:border-indigo-300 transition">
                                <svg class="w-8 h-8 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <label for="{$field}" class="cursor-pointer text-sm text-indigo-600 hover:underline">Upload image</label>
                                <input type="file" name="{$field}" id="{$field}" accept="image/*" class="sr-only">
                                <p class="text-xs text-gray-400 mt-1">PNG, JPG up to 5MB</p>
                            </div>
                            @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
                        </div>

BLADE;
        }
        return $html;
    }

    private function productRelationInputs(array $relations): string
    {
        $html = '';
        foreach ($relations as $rel => $uiType) {
            $label = Str::headline($rel);
            $input = match ($uiType) {
                'select' => <<<BLADE
                            <select name="{$rel}_id" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="">— {$label} —</option>
                                @foreach (\${$rel}Options as \$option)
                                <option value="{{ \$option->id }}" {{ old('{$rel}_id') == \$option->id ? 'selected' : '' }}>{{ \$option->name ?? \$option->id }}</option>
                                @endforeach
                            </select>
BLADE,
                'multi_select' => <<<BLADE
                            <div class="space-y-1 max-h-40 overflow-y-auto border border-gray-200 rounded-lg p-2">
                                @foreach (\${$rel}Options as \$option)
                                <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 px-1 py-0.5 rounded">
                                    <input type="checkbox" name="{$rel}[]" value="{{ \$option->id }}"
                                           {{ in_array(\$option->id, old('{$rel}', [])) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-indigo-600">
                                    <span class="text-sm text-gray-700">{{ \$option->name ?? \$option->id }}</span>
                                </label>
                                @endforeach
                            </div>
BLADE,
                default => '',
            };
            if (!$input) continue;
            $html .= <<<BLADE
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{$label}</label>
                            {$input}
                            @error('{$rel}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
                        </div>

BLADE;
        }
        return $html;
    }

    private function showRelationPanels(string $singular, array $relations): string
    {
        $html = '';
        foreach ($relations as $rel => $uiType) {
            $label = Str::headline($rel);
            $html .= match ($uiType) {
                'chips' => <<<BLADE
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">{$label}</h3>
                <div class="flex flex-wrap gap-2">
                    @forelse (\${$singular}->{$rel} as \$item)
                    <a href="#" class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition">{{ \$item->name ?? \$item->id }}</a>
                    @empty
                    <span class="text-xs text-gray-400">None.</span>
                    @endforelse
                </div>
            </div>
BLADE,
                default => <<<BLADE
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">{$label}</h3>
                <ul class="divide-y divide-gray-50 text-sm">
                    @forelse (\${$singular}->{$rel} as \$item)
                    <li class="py-2 text-gray-700">{{ \$item->name ?? \$item->title ?? \$item->id }}</li>
                    @empty
                    <li class="py-2 text-gray-400">No {$label}.</li>
                    @endforelse
                </ul>
            </div>
BLADE,
            };
        }
        return $html;
    }

    private function marketplaceActionButtons(string $singular, string $route, string $entity, array $actions): string
    {
        $html = '';
        if (in_array('edit', $actions)) {
            $html .= "<a href=\"{{ route('{$route}.edit', \${$singular}) }}\" class=\"flex-1 text-center px-4 py-2.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition\">Edit {$entity}</a>\n";
        }
        if (in_array('delete', $actions)) {
            $html .= <<<BLADE
                <form method="POST" action="{{ route('{$route}.destroy', \${$singular}) }}" onsubmit="return confirm('Delete this {$entity}?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-4 py-2.5 border border-red-200 rounded-lg text-sm font-medium text-red-600 hover:bg-red-50 transition">Delete</button>
                </form>
BLADE;
        }
        return $html;
    }

    // ── Field classifiers ─────────────────────────────────────────────────────

    private function detectField(array $fields, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $fields, true)) return $candidate;
            foreach ($fields as $f) {
                if (str_contains(strtolower($f), $candidate)) return $f;
            }
        }
        return null;
    }

    private function isPricingField(string $field): bool
    {
        return (bool) preg_match('/price|amount|cost|rate|fee|discount|tax|total/i', $field);
    }

    private function isInventoryField(string $field): bool
    {
        return (bool) preg_match('/stock|quantity|qty|inventory|sku|barcode|weight/i', $field);
    }

    private function isMediaField(string $field): bool
    {
        return (bool) preg_match('/image|photo|avatar|thumbnail|cover|banner|picture/i', $field);
    }

    private function inferType(string $field): string
    {
        $lower = strtolower($field);
        if (str_contains($lower, 'email')) return 'email';
        if (str_contains($lower, 'url') || str_contains($lower, 'website')) return 'url';
        if (str_contains($lower, 'date')) return 'date';
        if ($this->isPricingField($lower) || $this->isInventoryField($lower)) return 'number';
        return 'text';
    }
}
