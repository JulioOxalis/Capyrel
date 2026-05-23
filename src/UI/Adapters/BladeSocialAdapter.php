<?php

namespace Julio\Capyrel\UI\Adapters;

use Illuminate\Support\Str;
use Julio\Capyrel\UI\Contracts\UiAdapter;

/**
 * blade-social adapter — feed-first social UI.
 *
 * list   → card feed with avatar, timestamp, action bar (like / comment counts)
 * create → post composer with rich textarea + relation pickers
 * show   → full post detail, comment thread, reaction bar
 */
class BladeSocialAdapter implements UiAdapter
{
    private string $stamp = "{{-- capyrel:ui:social — remove with: php artisan capyrel:clean --views --}}\n";

    public function name(): string
    {
        return 'blade-social';
    }

    public function capabilities(): array
    {
        return ['list', 'create', 'show', 'feed', 'comments', 'reactions'];
    }

    // ── List — card feed ──────────────────────────────────────────────────────

    public function renderList(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['list'] ?? [];
        $fields    = $screen['fields'] ?? [];
        $relPrev   = $screen['relations_preview'] ?? [];
        $meta      = $contract['meta'] ?? [];
        $title     = Str::headline(Str::plural($entity));
        $route     = Str::kebab(Str::plural($entity));
        $plural    = Str::camel(Str::plural($entity));
        $singular  = Str::camel($entity);
        $titleField = $meta['title_field'] ?? ($fields[1] ?? 'id');
        $descField  = $meta['description_field'];

        $descLine = $descField
            ? "<p class=\"mt-2 text-gray-600 text-sm line-clamp-3\">{{ \${$singular}->{$descField} }}</p>"
            : '';

        $authorChunk = isset($relPrev['user']) || isset($relPrev['author'])
            ? $this->feedAvatarChunk($singular, array_key_first(array_filter($relPrev, fn($v, $k) => in_array($k, ['user', 'author']), ARRAY_BOTH) ?: ['user' => 'avatar']))
            : "<span class=\"text-sm font-medium text-gray-800\">{{ \${$singular}->id }}</span>";

        $actionBar = $this->feedActionBar($singular, $relPrev, $route);

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-900">{$title}</h2>
            <a href="{{ route('{$route}.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-full hover:bg-indigo-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New {$entity}
            </a>
        </div>
    </x-slot>

    <div class="py-8 max-w-2xl mx-auto px-4 sm:px-6 space-y-4">
        @forelse (\${$plural} as \${$singular})
        <article class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition">

            {{-- Author row --}}
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-3">
                    {$authorChunk}
                    <div class="text-xs text-gray-400">{{ \${$singular}->created_at?->diffForHumans() }}</div>
                </div>
                <a href="{{ route('{$route}.show', \${$singular}) }}" class="text-xs text-indigo-500 hover:underline">View</a>
            </div>

            {{-- Content --}}
            <a href="{{ route('{$route}.show', \${$singular}) }}" class="block group">
                <h3 class="font-semibold text-gray-900 group-hover:text-indigo-600 transition">{{ \${$singular}->{$titleField} }}</h3>
                {$descLine}
            </a>

            {{-- Action bar --}}
            {$actionBar}

        </article>
        @empty
        <div class="text-center py-20 text-gray-400">
            <p class="text-lg">Nothing here yet.</p>
            <a href="{{ route('{$route}.create') }}" class="mt-3 inline-block text-indigo-500 hover:underline text-sm">Be the first to post</a>
        </div>
        @endforelse

        <div class="pt-2">{{ \${$plural}->links() }}</div>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Create — composer ─────────────────────────────────────────────────────

    public function renderCreate(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['create'] ?? [];
        $fields    = $screen['fields'] ?? [];
        $relations = $screen['relations'] ?? [];
        $meta      = $contract['meta'] ?? [];
        $route     = Str::kebab(Str::plural($entity));
        $titleField = $meta['title_field'];
        $descField  = $meta['description_field'];

        $titleInput = $titleField
            ? $this->composerInput($titleField, 'text', "What's on your mind?")
            : '';
        $bodyInput = $descField
            ? $this->composerTextarea($descField)
            : '';

        // Remaining fields (excluding title + body already handled)
        $otherInputs = '';
        foreach ($fields as $field) {
            if ($field === $titleField || $field === $descField) continue;
            $otherInputs .= $this->composerInput($field, $this->inferType($field));
        }

        $relInputs = $this->composerRelations($relations);

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-900">Compose {$entity}</h2>
    </x-slot>

    <div class="py-8 max-w-2xl mx-auto px-4 sm:px-6">
        <form method="POST" action="{{ route('{$route}.store') }}" enctype="multipart/form-data"
              class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
            @csrf

            {$titleInput}
            {$bodyInput}
            {$otherInputs}
            {$relInputs}

            <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                <a href="{{ route('{$route}.index') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
                <button type="submit"
                        class="px-5 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-full hover:bg-indigo-700 transition">
                    Post {$entity}
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
BLADE;
    }

    // ── Show — post detail ────────────────────────────────────────────────────

    public function renderShow(array $contract): string
    {
        $entity    = $contract['entity'];
        $screen    = $contract['screens']['show'] ?? [];
        $relations = $screen['relations'] ?? [];
        $actions   = $screen['actions'] ?? ['edit', 'delete'];
        $meta      = $contract['meta'] ?? [];
        $route     = Str::kebab(Str::plural($entity));
        $singular  = Str::camel($entity);
        $titleField = $meta['title_field'] ?? 'id';
        $descField  = $meta['description_field'];

        $bodyBlock = $descField
            ? "<div class=\"prose prose-gray max-w-none mt-4 text-gray-700\">{{ \${$singular}->{$descField} }}</div>"
            : '';

        $reactionBar = in_array('like', $actions) ? $this->reactionBar($singular, $route) : '';
        $commentSection = isset($relations['comments']) ? $this->commentSection($singular) : '';
        $editDelete = $this->showActionButtons($singular, $route, $entity, $actions);
        $tagChips = $this->tagChips($singular, $relations);

        return $this->stamp . <<<BLADE
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('{$route}.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h2 class="text-xl font-semibold text-gray-900">{{ \${$singular}->{$titleField} }}</h2>
        </div>
    </x-slot>

    <div class="py-8 max-w-2xl mx-auto px-4 sm:px-6 space-y-4">

        {{-- Main post card --}}
        <article class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-semibold text-sm">
                        {{ strtoupper(substr(\${$singular}->{$titleField}, 0, 1)) }}
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900">{{ \${$singular}->{$titleField} }}</p>
                        <p class="text-xs text-gray-400">{{ \${$singular}->created_at?->format('M j, Y · g:i A') }}</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    {$editDelete}
                </div>
            </div>

            {$bodyBlock}

            {$tagChips}
            {$reactionBar}
        </article>

        {$commentSection}

    </div>
</x-app-layout>
BLADE;
    }

    // ── Feed helpers ──────────────────────────────────────────────────────────

    private function feedAvatarChunk(string $singular, string $rel): string
    {
        return <<<BLADE
<div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-semibold text-xs overflow-hidden">
                        @if(\${$singular}->{$rel}?->avatar)
                            <img src="{{ \${$singular}->{$rel}->avatar }}" class="w-full h-full object-cover" />
                        @else
                            {{ strtoupper(substr(\${$singular}->{$rel}?->name ?? '?', 0, 1)) }}
                        @endif
                    </div>
                    <span class="text-sm font-medium text-gray-800">{{ \${$singular}->{$rel}?->name ?? 'Unknown' }}</span>
BLADE;
    }

    private function feedActionBar(string $singular, array $relPrev, string $route): string
    {
        $likeCount = isset($relPrev['likes']) || isset($relPrev['reactions'])
            ? "<span class=\"text-xs text-gray-500\">{{ \${$singular}->likes_count ?? 0 }} likes</span>"
            : '';
        $commentCount = isset($relPrev['comments'])
            ? "<span class=\"text-xs text-gray-500\">{{ \${$singular}->comments_count ?? 0 }} comments</span>"
            : '';

        return <<<BLADE

            <div class="mt-4 pt-3 border-t border-gray-100 flex items-center gap-4">
                {$likeCount}
                {$commentCount}
                <a href="{{ route('{$route}.show', \${$singular}) }}" class="ml-auto text-xs text-indigo-500 hover:underline">Read more →</a>
            </div>
BLADE;
    }

    private function reactionBar(string $singular, string $route): string
    {
        return <<<BLADE

            <div class="mt-5 pt-4 border-t border-gray-100 flex items-center gap-3">
                <form method="POST" action="{{ route('{$route}.like', \${$singular}) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm text-gray-600 bg-gray-100 hover:bg-indigo-100 hover:text-indigo-600 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                        Like
                    </button>
                </form>
                <a href="#comments" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm text-gray-600 bg-gray-100 hover:bg-indigo-100 hover:text-indigo-600 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    Comment
                </a>
            </div>
BLADE;
    }

    private function commentSection(string $singular): string
    {
        return <<<BLADE
        {{-- Comment thread --}}
        <section id="comments" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Comments</h3>

            <div class="space-y-4 mb-6">
                @forelse (\${$singular}->comments as \$comment)
                <div class="flex gap-3">
                    <div class="w-8 h-8 flex-shrink-0 rounded-full bg-gray-100 flex items-center justify-center text-xs font-semibold text-gray-600">
                        {{ strtoupper(substr(\$comment->user?->name ?? '?', 0, 1)) }}
                    </div>
                    <div class="flex-1 bg-gray-50 rounded-xl px-4 py-3">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-sm font-medium text-gray-800">{{ \$comment->user?->name ?? 'Guest' }}</span>
                            <span class="text-xs text-gray-400">{{ \$comment->created_at?->diffForHumans() }}</span>
                        </div>
                        <p class="text-sm text-gray-700">{{ \$comment->content ?? \$comment->body }}</p>
                    </div>
                </div>
                @empty
                <p class="text-sm text-gray-400">No comments yet. Be the first!</p>
                @endforelse
            </div>

            @auth
            <form method="POST" action="#" class="flex gap-3">
                @csrf
                <div class="w-8 h-8 flex-shrink-0 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-semibold text-indigo-600">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </div>
                <div class="flex-1 flex gap-2">
                    <input type="text" name="content" placeholder="Write a comment…"
                           class="flex-1 border-gray-200 rounded-full px-4 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                    <button type="submit"
                            class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-full hover:bg-indigo-700 transition">
                        Post
                    </button>
                </div>
            </form>
            @endauth
        </section>
BLADE;
    }

    private function tagChips(string $singular, array $relations): string
    {
        if (!isset($relations['tags'])) return '';
        return <<<BLADE

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach (\${$singular}->tags as \$tag)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700">
                    # {{ \$tag->name ?? \$tag->id }}
                </span>
                @endforeach
            </div>
BLADE;
    }

    private function showActionButtons(string $singular, string $route, string $entity, array $actions): string
    {
        $html = '';
        if (in_array('edit', $actions)) {
            $html .= "<a href=\"{{ route('{$route}.edit', \${$singular}) }}\" class=\"text-xs text-gray-500 hover:text-indigo-600\">Edit</a>\n";
        }
        if (in_array('delete', $actions)) {
            $html .= <<<BLADE
                    <form method="POST" action="{{ route('{$route}.destroy', \${$singular}) }}" onsubmit="return confirm('Delete?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                    </form>
BLADE;
        }
        return $html;
    }

    // ── Composer helpers ──────────────────────────────────────────────────────

    private function composerInput(string $field, string $type = 'text', string $placeholder = ''): string
    {
        $label = Str::headline($field);
        $ph    = $placeholder ?: $label;
        return <<<BLADE

            <div>
                <input type="{$type}" name="{$field}" value="{{ old('{$field}') }}"
                       placeholder="{$ph}"
                       class="w-full border-0 border-b border-gray-200 pb-2 text-gray-900 placeholder-gray-400 focus:ring-0 focus:border-indigo-400 bg-transparent text-sm">
                @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
            </div>
BLADE;
    }

    private function composerTextarea(string $field): string
    {
        $label = Str::headline($field);
        return <<<BLADE

            <div>
                <textarea name="{$field}" rows="5" placeholder="{$label}…"
                          class="w-full border-0 border-b border-gray-200 pb-2 text-gray-700 placeholder-gray-400 focus:ring-0 focus:border-indigo-400 bg-transparent text-sm resize-none">{{ old('{$field}') }}</textarea>
                @error('{$field}') <p class="mt-1 text-xs text-red-500">{{ \$message }}</p> @enderror
            </div>
BLADE;
    }

    private function composerRelations(array $relations): string
    {
        $html = '';
        foreach ($relations as $rel => $uiType) {
            $label = Str::headline($rel);
            if ($uiType === 'multi_select') {
                $html .= <<<BLADE

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{$label}</label>
                <div class="flex flex-wrap gap-2 p-2 border border-gray-200 rounded-xl min-h-[2.5rem]">
                    @foreach (\${$rel}Options as \$option)
                    <label class="inline-flex items-center gap-1 cursor-pointer">
                        <input type="checkbox" name="{$rel}[]" value="{{ \$option->id }}"
                               {{ in_array(\$option->id, old('{$rel}', [])) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-indigo-600">
                        <span class="text-sm text-gray-700">{{ \$option->name ?? \$option->id }}</span>
                    </label>
                    @endforeach
                </div>
            </div>
BLADE;
            } elseif ($uiType === 'select') {
                $html .= <<<BLADE

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{$label}</label>
                <select name="{$rel}_id" class="w-full border-gray-200 rounded-xl text-sm focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">— {$label} —</option>
                    @foreach (\${$rel}Options as \$option)
                    <option value="{{ \$option->id }}" {{ old('{$rel}_id') == \$option->id ? 'selected' : '' }}>
                        {{ \$option->name ?? \$option->id }}
                    </option>
                    @endforeach
                </select>
            </div>
BLADE;
            }
        }
        return $html;
    }

    private function inferType(string $field): string
    {
        $lower = strtolower($field);
        if (str_contains($lower, 'email')) return 'email';
        if (str_contains($lower, 'url') || str_contains($lower, 'website')) return 'url';
        if (str_contains($lower, 'date')) return 'date';
        return 'text';
    }
}
