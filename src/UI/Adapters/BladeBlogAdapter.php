<?php

namespace Julio\Capyrel\UI\Adapters;

use Illuminate\Support\Str;
use Julio\Capyrel\UI\Contracts\UiAdapter;

/**
 * blade-blog adapter — editorial-first publishing UI.
 *
 * list   → hero featured post + article card grid with reading time, author, category
 * create → editorial composer: title, slug, excerpt, body, image upload, series + tags sidebar
 * edit   → same as create, pre-filled from model
 * show   → article layout: hero image, breadcrumb series nav, body, prev/next, author card
 */
class BladeBlogAdapter implements UiAdapter
{
    private string $stamp = "{{-- capyrel:ui:blog — remove with: php artisan capyrel:clean --views --}}\n";

    public function name(): string
    {
        return 'blade-blog';
    }

    public function capabilities(): array
    {
        return ['list', 'create', 'edit', 'show', 'series', 'reading_time', 'editorial'];
    }

    // ── List — hero grid ──────────────────────────────────────────────────────

    public function renderList(array $contract): string
    {
        $entity     = $contract['entity'];
        $screen     = $contract['screens']['list'] ?? [];
        $fields     = $screen['fields'] ?? [];
        $relPrev    = $screen['relations_preview'] ?? [];
        $meta       = $contract['meta'] ?? [];
        $route      = Str::kebab(Str::plural($entity));
        $plural     = Str::camel(Str::plural($entity));
        $singular   = Str::camel($entity);
        $title      = Str::headline(Str::plural($entity));
        $titleField = $meta['title_field'] ?? ($fields[1] ?? 'title');
        $descField  = $meta['description_field'];
        $imageField = $this->detectField($fields, ['image', 'cover', 'thumbnail', 'photo', 'banner']);

        $heroImage = $imageField
            ? "@if(\${$singular}->{$imageField})\n            <img src=\"{{ asset('storage/' . \${$singular}->{$imageField}) }}\" class=\"absolute inset-0 w-full h-full object-cover\" />\n            @endif"
            : '';
        $cardImage = $imageField
            ? "@if(\$article->{$imageField})\n                <img src=\"{{ asset('storage/' . \$article->{$imageField}) }}\" class=\"w-full h-full object-cover transition group-hover:scale-105 duration-300\" />\n                @endif"
            : '';
        $descLine = $descField
            ? "<p class=\"mt-1 text-gray-300 text-sm line-clamp-2\">{{ \${$singular}->{$descField} }}</p>"
            : '';
        $cardDesc = $descField
            ? "<p class=\"mt-2 text-gray-500 text-sm line-clamp-2\">{{ \$article->{$descField} }}</p>"
            : '';
        $authorChip = isset($relPrev['user']) || isset($relPrev['author'])
            ? "<span class=\"text-xs text-gray-400\">by {{ \${$singular}->user?->name ?? \${$singular}->author?->name ?? 'Unknown' }}</span>"
            : '';
        $catChip = isset($relPrev['category']) || isset($relPrev['categories'])
            ? "<span class=\"inline-block px-2 py-0.5 rounded text-xs font-semibold bg-indigo-600 text-white uppercase tracking-wide mb-2\">{{ \${$singular}->category?->name ?? 'Article' }}</span>"
            : "<span class=\"inline-block px-2 py-0.5 rounded text-xs font-semibold bg-indigo-600 text-white uppercase tracking-wide mb-2\">Article</span>";

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-900">{$title}</h2>
            <a href="{{ route('{$route}.create') }}"
               class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                Write {$entity}
            </a>
        </div>
    </x-slot>

    <div class="py-8 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        {{-- Search + filter --}}
        <form method="GET" class="mb-8 flex gap-3">
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Search articles…"
                   class="flex-1 max-w-sm border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
            <button type="submit" class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">Search</button>
        </form>

        @if(\${$plural}->count() > 0)
        @php \${$singular} = \${$plural}->first(); @endphp

        {{-- Hero featured post --}}
        <a href="{{ route('{$route}.show', \${$singular}) }}" class="block relative rounded-2xl overflow-hidden h-80 mb-8 group">
            <div class="absolute inset-0 bg-gray-900">
                {$heroImage}
                <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
            </div>
            <div class="absolute bottom-0 p-8 text-white">
                {$catChip}
                <h2 class="text-2xl font-bold leading-snug group-hover:underline">{{ \${$singular}->{$titleField} }}</h2>
                {$descLine}
                <div class="mt-3 flex items-center gap-3 text-xs text-gray-300">
                    {$authorChip}
                    <span>·</span>
                    <span>{{ \${$singular}->created_at?->format('M j, Y') }}</span>
                    <span>·</span>
                    <span>{{ ceil(str_word_count(\${$singular}->{$descField ?? $titleField} ?? '') / 200) }} min read</span>
                </div>
            </div>
        </a>

        {{-- Article grid --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach(\${$plural}->skip(1) as \$article)
            <article class="group bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden hover:shadow-md transition">
                <div class="aspect-video bg-gray-100 overflow-hidden">
                    {$cardImage}
                </div>
                <div class="p-5">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xs font-semibold text-indigo-600 uppercase tracking-wide">Article</span>
                        <span class="text-xs text-gray-400">·</span>
                        <span class="text-xs text-gray-400">{{ ceil(str_word_count(\$article->{$descField ?? $titleField} ?? '') / 200) }} min read</span>
                    </div>
                    <a href="{{ route('{$route}.show', \$article) }}" class="block">
                        <h3 class="font-semibold text-gray-900 leading-snug group-hover:text-indigo-600 transition">{{ \$article->{$titleField} }}</h3>
                        {$cardDesc}
                    </a>
                    <div class="mt-4 flex items-center justify-between text-xs text-gray-400">
                        <span>{{ \$article->created_at?->format('M j, Y') }}</span>
                        <div class="flex gap-2">
                            <a href="{{ route('{$route}.edit', \$article) }}" class="hover:text-indigo-600">Edit</a>
                            <form method="POST" action="{{ route('{$route}.destroy', \$article) }}" onsubmit="return confirm('Delete?')" class="inline">
                                @csrf @method('DELETE')
                                <button class="hover:text-red-500">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            </article>
            @endforeach
        </div>

        @else
        <div class="text-center py-24 text-gray-400">
            <p class="text-lg mb-2">Nothing published yet.</p>
            <a href="{{ route('{$route}.create') }}" class="text-indigo-500 hover:underline text-sm">Write your first {$entity}</a>
        </div>
        @endif

        <div class="mt-8">{{ \${$plural}->withQueryString()->links() }}</div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Create — editorial composer ───────────────────────────────────────────

    public function renderCreate(array $contract): string
    {
        return $this->renderForm($contract, false);
    }

    // ── Edit — editorial composer (pre-filled) ────────────────────────────────

    public function renderEdit(array $contract): string
    {
        return $this->renderForm($contract, true);
    }

    // ── Shared form renderer ──────────────────────────────────────────────────

    private function renderForm(array $contract, bool $isEdit): string
    {
        $entity     = $contract['entity'];
        $screen     = $contract['screens']['create'] ?? [];
        $fields     = $screen['fields'] ?? [];
        $relations  = $screen['relations'] ?? [];
        $meta       = $contract['meta'] ?? [];
        $route      = Str::kebab(Str::plural($entity));
        $singular   = Str::camel($entity);
        $titleField = $meta['title_field'] ?? 'title';
        $descField  = $meta['description_field'];
        $imageField = $this->detectField($fields, ['image', 'cover', 'thumbnail', 'photo', 'banner']);
        $slugField  = $this->detectField($fields, ['slug']);

        $action     = $isEdit ? "route('{$route}.update', \${$singular})" : "route('{$route}.store')";
        $method     = $isEdit ? "@csrf\n            @method('PATCH')" : '@csrf';
        $heading    = $isEdit ? "Edit {$entity}" : "New {$entity}";
        $button     = $isEdit ? "Save Changes" : "Publish {$entity}";
        $cancelLink = $isEdit ? "route('{$route}.show', \${$singular})" : "route('{$route}.index')";
        $val        = fn(string $f) => $isEdit ? "old('{$f}', \${$singular}->{$f})" : "old('{$f}')";

        $slugScript = $slugField && !$isEdit ? <<<JS

    <script>
        document.getElementById('{$titleField}')?.addEventListener('input', function () {
            const slug = this.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-\$/g, '');
            const slugEl = document.getElementById('{$slugField}');
            if (slugEl && !slugEl.dataset.edited) slugEl.value = slug;
        });
        document.getElementById('{$slugField}')?.addEventListener('input', function () {
            this.dataset.edited = '1';
        });
    </script>
JS : '';

        $mainFields = '';
        foreach ($fields as $field) {
            if ($field === $descField || $field === $imageField) continue;
            $label = Str::headline($field);
            $type  = $this->inferType($field);
            $valExpr = $val($field);

            if ($field === $slugField) {
                $mainFields .= <<<BLADE

            <div>
                <label for="{$field}" class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Slug <span class="text-gray-300 font-normal normal-case">(auto-filled)</span></label>
                <div class="flex items-center border border-gray-300 rounded-lg overflow-hidden">
                    <span class="px-3 py-2 bg-gray-50 text-gray-400 text-sm border-r border-gray-300">/</span>
                    <input type="text" name="{$field}" id="{$field}" value="{{ {$valExpr} }}"
                           class="flex-1 px-3 py-2 text-sm focus:ring-0 focus:outline-none border-0">
                </div>
                @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
            </div>
BLADE;
            } elseif ($field === $titleField) {
                $mainFields .= <<<BLADE

            <div>
                <input type="text" name="{$field}" id="{$field}" value="{{ {$valExpr} }}"
                       placeholder="Article title…"
                       class="w-full border-0 border-b-2 border-gray-200 py-2 text-2xl font-bold text-gray-900 placeholder-gray-300 focus:ring-0 focus:border-indigo-400 bg-transparent">
                @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
            </div>
BLADE;
            } else {
                $mainFields .= <<<BLADE

            <div>
                <label for="{$field}" class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{$label}</label>
                <input type="{$type}" name="{$field}" id="{$field}" value="{{ {$valExpr} }}"
                       class="block w-full border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
            </div>
BLADE;
            }
        }

        $bodyField = $descField ? <<<BLADE

            <div class="mt-4">
                <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Body</label>
                <textarea name="{$descField}" rows="16"
                          placeholder="Write your article…"
                          class="w-full border border-gray-200 rounded-xl p-4 text-gray-800 text-sm leading-relaxed focus:ring-indigo-500 focus:border-indigo-500 resize-none">{{ {$val($descField)} }}</textarea>
                @error('{$descField}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
            </div>
BLADE : '';

        $imageUpload = $imageField ? <<<BLADE

                <div>
                    <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Cover Image</label>
                    @if(\${$singular}?->{$imageField})
                    <img src="{{ asset('storage/' . \${$singular}->{$imageField}) }}" class="w-full h-32 object-cover rounded-lg mb-2" />
                    @endif
                    <div class="border-2 border-dashed border-gray-200 rounded-xl p-4 text-center hover:border-indigo-300 transition">
                        <label for="{$imageField}" class="cursor-pointer text-sm text-indigo-600 hover:underline">
                            {{ \${$singular}?->{$imageField} ? 'Replace image' : 'Upload cover' }}
                        </label>
                        <input type="file" name="{$imageField}" id="{$imageField}" accept="image/*" class="sr-only">
                        <p class="text-xs text-gray-400 mt-1">PNG, JPG · max 5 MB</p>
                    </div>
                    @error('{$imageField}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
                </div>
BLADE : '';

        $relInputs = '';
        foreach ($relations as $rel => $uiType) {
            $label   = Str::headline($rel);
            $valExpr = $isEdit
                ? ($uiType === 'multi_select'
                    ? "old('{$rel}', \${$singular}->{$rel}->pluck('id')->toArray())"
                    : "old('{$rel}_id', \${$singular}->{$rel}_id)")
                : ($uiType === 'multi_select' ? "old('{$rel}', [])" : "old('{$rel}_id')");

            if ($uiType === 'select') {
                $relInputs .= <<<BLADE

                <div>
                    <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{$label}</label>
                    <select name="{$rel}_id" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">— {$label} —</option>
                        @foreach (\${$rel}Options as \$option)
                        <option value="{{ \$option->id }}" {{ {$valExpr} == \$option->id ? 'selected' : '' }}>{{ \$option->name ?? \$option->id }}</option>
                        @endforeach
                    </select>
                </div>
BLADE;
            } elseif ($uiType === 'multi_select') {
                $relInputs .= <<<BLADE

                <div>
                    <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{$label}</label>
                    <div class="space-y-1 max-h-36 overflow-y-auto border border-gray-200 rounded-lg p-2">
                        @foreach (\${$rel}Options as \$option)
                        <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 px-1 py-0.5 rounded text-sm">
                            <input type="checkbox" name="{$rel}[]" value="{{ \$option->id }}"
                                   {{ in_array(\$option->id, {$valExpr}) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-indigo-600">
                            {{ \$option->name ?? \$option->id }}
                        </label>
                        @endforeach
                    </div>
                </div>
BLADE;
            }
        }

        $publishDate = $this->detectField($fields, ['published_at', 'publish_date', 'published'])
            ? <<<BLADE

                <div>
                    <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Publish Date</label>
                    <input type="datetime-local" name="published_at"
                           value="{{ old('published_at', \${$singular}?->published_at?->format('Y-m-d\TH:i')) }}"
                           class="block w-full border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                </div>
BLADE : '';

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ {$cancelLink} }}" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h2 class="text-lg font-semibold text-gray-900">{$heading}</h2>
        </div>
    </x-slot>

    <div class="py-6 px-4 sm:px-6 lg:px-8">
        <form method="POST" action="{{ {$action} }}" enctype="multipart/form-data">
            {$method}

            <div class="max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- Main editorial area --}}
                <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-8 space-y-4">
{$mainFields}
{$bodyField}
                </div>

                {{-- Sidebar: publish settings, image, relations --}}
                <div class="space-y-4">

                    {{-- Publish --}}
                    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 space-y-3">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Publish</h3>
{$publishDate}
                        <button type="submit"
                                class="w-full px-4 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition">
                            {$button}
                        </button>
                        <a href="{{ {$cancelLink} }}"
                           class="block w-full text-center px-4 py-2 text-sm text-gray-500 bg-gray-50 rounded-lg hover:bg-gray-100">
                            Cancel
                        </a>
                    </div>

                    {{-- Cover image --}}
                    @if(isset(\$imageField))
                    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Cover</h3>
{$imageUpload}
                    </div>
                    @endif

                    {{-- Relations (series, tags, categories) --}}
                    @if(count([]) > 0){{-- placeholder --}}@endif
                    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 space-y-4">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Taxonomy</h3>
{$relInputs}
                    </div>

                </div>
            </div>
        </form>
    </div>
{$slugScript}
</x-app-layout>
BLADE;
    }

    // ── Show — article layout ─────────────────────────────────────────────────

    public function renderShow(array $contract): string
    {
        $entity     = $contract['entity'];
        $screen     = $contract['screens']['show'] ?? [];
        $relations  = $screen['relations'] ?? [];
        $actions    = $screen['actions'] ?? ['edit', 'delete'];
        $meta       = $contract['meta'] ?? [];
        $listFields = $contract['screens']['list']['fields'] ?? [];
        $route      = Str::kebab(Str::plural($entity));
        $singular   = Str::camel($entity);
        $titleField = $meta['title_field'] ?? 'title';
        $descField  = $meta['description_field'];
        $imageField = $this->detectField($listFields, ['image', 'cover', 'thumbnail', 'photo', 'banner']);

        $heroImage = $imageField
            ? "@if(\${$singular}->{$imageField})\n        <div class=\"w-full h-72 sm:h-96 bg-gray-200 overflow-hidden rounded-2xl mb-8\">\n            <img src=\"{{ asset('storage/' . \${$singular}->{$imageField}) }}\" class=\"w-full h-full object-cover\" />\n        </div>\n        @endif"
            : '';
        $bodyBlock = $descField
            ? "<div class=\"prose prose-lg prose-gray max-w-none mt-8\">{{ \${$singular}->{$descField} }}</div>"
            : '';
        $seriesNav  = isset($relations['series']) ? $this->seriesNav($singular) : '';
        $tagChips   = isset($relations['tags']) ? $this->tagChips($singular) : '';
        $authorCard = isset($contract['relations']['user']) ? $this->authorCard($singular) : '';

        $editBtn = in_array('edit', $actions)
            ? "<a href=\"{{ route('{$route}.edit', \${$singular}) }}\" class=\"inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition\">Edit</a>"
            : '';
        $deleteBtn = in_array('delete', $actions)
            ? "<form method=\"POST\" action=\"{{ route('{$route}.destroy', \${$singular}) }}\" onsubmit=\"return confirm('Delete?')\" class=\"inline\">\n                    @csrf @method('DELETE')\n                    <button class=\"inline-flex items-center px-3 py-1.5 border border-red-200 rounded-lg text-sm text-red-600 hover:bg-red-50 transition\">Delete</button>\n                </form>"
            : '';

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <a href="{{ route('{$route}.index') }}" class="flex items-center gap-2 text-gray-500 hover:text-gray-800 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                All {$entity}s
            </a>
            <div class="flex gap-2">
                {$editBtn}
                {$deleteBtn}
            </div>
        </div>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

        {$heroImage}

        {{-- Series breadcrumb --}}
        @isset(\${$singular}->series)
        <nav class="flex items-center gap-2 text-sm text-gray-400 mb-4">
            <a href="{{ route('{$route}.index') }}" class="hover:text-indigo-600">All {$entity}s</a>
            <span>›</span>
            <span class="text-indigo-600">{{ \${$singular}->series?->name ?? '' }}</span>
        </nav>
        @endisset

        {{-- Article header --}}
        <header class="mb-8">
            <div class="flex items-center gap-2 mb-3">
                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-indigo-100 text-indigo-700 uppercase tracking-wide">Article</span>
                <span class="text-xs text-gray-400">{{ ceil(str_word_count(\${$singular}->{$descField ?? $titleField} ?? '') / 200) }} min read</span>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight">{{ \${$singular}->{$titleField} }}</h1>
            <div class="mt-4 flex items-center gap-4 text-sm text-gray-500">
                <span>{{ \${$singular}->user?->name ?? \${$singular}->author?->name ?? '' }}</span>
                <span>·</span>
                <span>{{ \${$singular}->created_at?->format('F j, Y') }}</span>
                @isset(\${$singular}->updated_at)
                @if(\${$singular}->updated_at->gt(\${$singular}->created_at->addHour()))
                <span>·</span>
                <span class="text-xs">Updated {{ \${$singular}->updated_at->diffForHumans() }}</span>
                @endif
                @endisset
            </div>
        </header>

        {$bodyBlock}

        {$tagChips}

        {$seriesNav}

        {$authorCard}

    </div>
</x-app-layout>
BLADE;
    }

    // ── Show helpers ──────────────────────────────────────────────────────────

    private function seriesNav(string $singular): string
    {
        return <<<BLADE


        {{-- Series navigation --}}
        @isset(\${$singular}->series)
        <nav class="mt-12 pt-8 border-t border-gray-100 grid grid-cols-2 gap-4 text-sm">
            @if(\$prev = \${$singular}->series?->articles?->where('id', '<', \${$singular}->id)->last())
            <a href="{{ route(request()->route()->getName(), \$prev) }}" class="group flex flex-col p-4 border border-gray-100 rounded-xl hover:border-indigo-200 transition">
                <span class="text-xs text-gray-400 mb-1">← Previous</span>
                <span class="font-medium text-gray-700 group-hover:text-indigo-600 line-clamp-2">{{ \$prev->title }}</span>
            </a>
            @else
            <div></div>
            @endif

            @if(\$next = \${$singular}->series?->articles?->where('id', '>', \${$singular}->id)->first())
            <a href="{{ route(request()->route()->getName(), \$next) }}" class="group flex flex-col items-end p-4 border border-gray-100 rounded-xl hover:border-indigo-200 transition text-right">
                <span class="text-xs text-gray-400 mb-1">Next →</span>
                <span class="font-medium text-gray-700 group-hover:text-indigo-600 line-clamp-2">{{ \$next->title }}</span>
            </a>
            @else
            <div></div>
            @endif
        </nav>
        @endisset
BLADE;
    }

    private function tagChips(string $singular): string
    {
        return <<<BLADE


        {{-- Tags --}}
        <div class="mt-8 flex flex-wrap gap-2">
            @foreach(\${$singular}->tags as \$tag)
            <a href="{{ request()->fullUrlWithQuery(['tag' => \$tag->slug ?? \$tag->id]) }}"
               class="inline-flex px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-600 hover:bg-indigo-100 hover:text-indigo-700 transition">
                # {{ \$tag->name ?? \$tag->id }}
            </a>
            @endforeach
        </div>
BLADE;
    }

    private function authorCard(string $singular): string
    {
        return <<<BLADE


        {{-- Author card --}}
        @isset(\${$singular}->user)
        <aside class="mt-12 p-6 bg-gray-50 rounded-2xl flex items-start gap-5">
            <div class="w-14 h-14 flex-shrink-0 rounded-full bg-indigo-100 flex items-center justify-center text-xl font-bold text-indigo-600 overflow-hidden">
                @if(\${$singular}->user->avatar)
                <img src="{{ \${$singular}->user->avatar }}" class="w-full h-full object-cover" />
                @else
                {{ strtoupper(substr(\${$singular}->user->name, 0, 1)) }}
                @endif
            </div>
            <div>
                <p class="font-semibold text-gray-900">{{ \${$singular}->user->name }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ \${$singular}->user->bio ?? '' }}</p>
            </div>
        </aside>
        @endisset
BLADE;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private function inferType(string $field): string
    {
        $lower = strtolower($field);
        if (str_contains($lower, 'email')) return 'email';
        if (str_contains($lower, 'url') || str_contains($lower, 'website')) return 'url';
        if (str_contains($lower, 'date') || str_ends_with($lower, '_at')) return 'date';
        return 'text';
    }
}
