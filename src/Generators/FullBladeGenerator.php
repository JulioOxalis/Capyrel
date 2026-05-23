<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\ColumnTypeDetector;
use Julio\Capyrel\Detectors\FrameworkDetector;
use Julio\Capyrel\Detectors\UploadColumnDetector;

/**
 * Generates a modal-first index page only.
 * All CRUD modals are inline: create, edit, view, delete, bulk-delete.
 * Features: AJAX submit, per-field errors, toast, dirty-state warning,
 *           delete-typing confirmation, bulk selection, color picker,
 *           slug auto-fill, spatial coordinate inputs, ENUM selects.
 */
class FullBladeGenerator
{
    public function __construct(private FrameworkDetector $framework) {}

    private string $stamp = "{{-- capyrel:generated — remove with: php artisan capyrel:clean --views --}}\n";

    // ── Public API ────────────────────────────────────────────────────────────

    public function generateIndex(string $model, array $columns, array $relationships = []): string
    {
        return $this->stamp . ($this->isTw()
            ? $this->twIndex($model, $columns, $relationships)
            : $this->bsIndex($model, $columns, $relationships));
    }

    // ── Tailwind + Alpine index ───────────────────────────────────────────────

    private function twIndex(string $model, array $columns, array $relationships): string
    {
        $plural    = Str::camel(Str::plural($model));
        $singular  = Str::camel($model);
        $route     = Str::kebab(Str::plural($model));
        $title     = Str::headline(Str::plural($model));
        $cols      = $this->displayCols($columns);
        $headers   = $this->twHeaders($cols);
        $cells     = $this->twCells($singular, $cols);
        $viewFields = $this->twViewFields($cols);
        $createInputs = $this->buildInputs($columns, $relationships, null, 'create', 'tailwind');
        $editInputs   = $this->buildInputs($columns, $relationships, null, 'edit', 'tailwind');
        $hasFiles  = !empty(UploadColumnDetector::collectFrom($columns));
        $enctype   = $hasFiles ? ' enctype="multipart/form-data"' : '';
        $deleteTyping = config('capyrel.modals.delete_typing', true);
        $unsavedWarning = config('capyrel.modals.unsaved_warning', true);

        // delete modal x-data and body differ based on typing config
        $deleteXData = $deleteTyping
            ? "{ open: false, url: '', name: '', typed: '' }"
            : "{ open: false, url: '', name: '' }";
        $deleteOpenHandler = $deleteTyping
            ? "url = \$event.detail.url; name = \$event.detail.name; typed = ''; open = true"
            : "url = \$event.detail.url; name = \$event.detail.name; open = true";
        $deleteConfirmField = $deleteTyping ? <<<BLADE

                    <p class="mt-3 text-sm text-gray-600">
                        Type <code class="font-mono bg-gray-100 px-1.5 py-0.5 rounded text-xs font-medium" x-text="name"></code> to confirm:
                    </p>
                    <input x-model="typed" type="text" autocomplete="off"
                           placeholder="Type to confirm..."
                           class="mt-2 block w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-red-400 focus:ring-red-300 transition">
BLADE : '';
        $deleteDisabled = $deleteTyping ? ':disabled="typed !== name"' : '';

        // unsaved changes overlay for modals
        $unsavedOverlay = $unsavedWarning ? <<<BLADE

                {{-- Unsaved-changes confirmation --}}
                <div x-show="confirmClose"
                     class="absolute inset-0 bg-white/95 backdrop-blur-sm z-10 flex items-center justify-center p-6 rounded-r-2xl">
                    <div class="text-center max-w-xs">
                        <div class="w-12 h-12 bg-amber-100 rounded-2xl flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        </div>
                        <p class="font-semibold text-gray-900">Unsaved changes</p>
                        <p class="text-sm text-gray-500 mt-1">You'll lose your edits if you close now.</p>
                        <div class="flex gap-3 mt-5 justify-center">
                            <button @click="confirmClose = false"
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition">
                                Keep editing
                            </button>
                            <button @click="open = false; isDirty = false; confirmClose = false"
                                    class="px-4 py-2 text-sm font-medium text-white bg-amber-500 rounded-xl hover:bg-amber-600 transition">
                                Discard
                            </button>
                        </div>
                    </div>
                </div>
BLADE : '';
        $dirtyXData = $unsavedWarning ? ', isDirty: false, confirmClose: false' : '';
        $dirtyTracking = $unsavedWarning ? ' @input="isDirty = true"' : '';
        $closeHandler = $unsavedWarning
            ? "isDirty ? confirmClose = true : (open = false)"
            : "open = false";
        $editCloseHandler = $unsavedWarning
            ? "isDirty ? confirmClose = true : (show = false)"
            : "show = false";
        $editCloseBtn = $unsavedWarning
            ? "isDirty ? confirmClose = true : (show = false)"
            : "show = false";

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

            {{-- ═══ Bulk + Search state ═══ --}}
            <div x-data="{
                     search: '',
                     selected: [],
                     allIds: {{ Js::from(\${$plural}->pluck('id')) }},
                     get allSelected() { return this.selected.length === this.allIds.length && this.allIds.length > 0; },
                     toggleAll() { this.selected = this.allSelected ? [] : [...this.allIds]; }
                 }">

                {{-- Search --}}
                <div class="mb-4 flex items-center gap-3">
                    <div class="relative max-w-xs flex-1">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                        <input x-model="search" type="search" placeholder="Search {$title}..."
                               class="block w-full pl-9 pr-4 py-2.5 text-sm rounded-xl border border-gray-200 bg-white shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 outline-none transition">
                    </div>
                </div>

                {{-- Bulk action bar --}}
                <div x-show="selected.length > 0"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="mb-3 flex items-center gap-3 px-4 py-3 bg-indigo-50 border border-indigo-200 rounded-xl">
                    <span class="text-sm font-medium text-indigo-700" x-text="`\${selected.length} selected`"></span>
                    <button @click="\$dispatch('open-bulk-delete', { ids: selected, count: selected.length })"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete selected
                    </button>
                    <button @click="selected = []" class="ml-auto text-xs text-indigo-500 hover:text-indigo-700 transition">
                        Clear
                    </button>
                </div>

                {{-- Table --}}
                <div class="bg-white shadow-sm rounded-2xl overflow-hidden border border-gray-100">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead>
                            <tr class="bg-gray-50/80">
                                <th class="px-4 py-3.5 w-10">
                                    <input type="checkbox" @click="toggleAll()" :checked="allSelected"
                                           class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                                </th>
{$headers}
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider w-32">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse(\${$plural} as \${$singular})
                            <tr class="hover:bg-indigo-50/30 transition-colors group"
                                x-show="!search || \$el.textContent.toLowerCase().includes(search.toLowerCase())">
                                <td class="px-4 py-3.5" @click.stop>
                                    <input type="checkbox" x-model="selected" :value="{{ \${$singular}->id }}"
                                           class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                                </td>
{$cells}
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-all">
                                        <button type="button"
                                                @click="\$dispatch('open-view', {{ Js::from(\${$singular}->makeHidden(['password','remember_token','two_factor_secret'])->toArray()) }})"
                                                title="Quick view"
                                                class="p-1.5 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        </button>
                                        <button type="button"
                                                @click="\$dispatch('open-edit', {{ Js::from(\${$singular}->makeHidden(['password','remember_token','two_factor_secret'])->toArray()) }})"
                                                title="Edit"
                                                class="p-1.5 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded-lg transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
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
                            <tr>
                                <td colspan="99" class="px-6 py-16 text-center">
                                    <div class="w-16 h-16 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                    </div>
                                    <p class="text-gray-500 font-medium">No {$title} yet</p>
                                    <p class="text-gray-400 text-sm mt-1">Create the first one to get started.</p>
                                    <button @click="\$dispatch('open-create')"
                                            class="mt-4 inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm rounded-xl hover:bg-indigo-700 transition">
                                        + New {$model}
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

            </div>{{-- /bulk+search --}}

        </div>
    </div>

    {{-- ═══ TOAST ═══ --}}
    <div x-data="{
            toasts: [],
            add(e) {
                const t = { id: Date.now(), type: e.detail?.type ?? 'success', message: e.detail?.message ?? e.detail ?? 'Done.' };
                this.toasts.push(t);
                setTimeout(() => this.toasts = this.toasts.filter(x => x.id !== t.id), 4000);
            }
         }"
         @capyrel-toast.window="add(\$event)"
         class="fixed bottom-6 right-6 z-[9999] space-y-2 pointer-events-none">
        <template x-for="t in toasts" :key="t.id">
            <div x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 :class="t.type === 'error' ? 'bg-red-600 text-white' : 'bg-white border border-green-200 text-green-800'"
                 class="pointer-events-auto flex items-center gap-3 px-5 py-3 rounded-2xl shadow-lg text-sm max-w-sm">
                <template x-if="t.type !== 'error'">
                    <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </template>
                <template x-if="t.type === 'error'">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </template>
                <span x-text="t.message"></span>
            </div>
        </template>
    </div>
    @if(session('success'))
    <script>window.dispatchEvent(new CustomEvent('capyrel-toast',{detail:{type:'success',message:'{{ addslashes(session('success')) }}'}}));</script>
    @endif

    {{-- ═══ CREATE SLIDE-OVER ═══ --}}
    <div x-data="{
            open: false{$dirtyXData},
            loading: false,
            errors: {},
            async submit(event) {
                event.preventDefault();
                this.loading = true; this.errors = {};
                try {
                    const res = await fetch(event.target.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(event.target)
                    });
                    const data = await res.json();
                    if (res.ok) {
                        this.open = false; this.isDirty = false;
                        event.target.reset();
                        window.dispatchEvent(new CustomEvent('capyrel-toast', { detail: data.message ?? 'Created.' }));
                        setTimeout(() => window.location.reload(), 800);
                    } else if (res.status === 422) {
                        this.errors = data.errors ?? {};
                    } else {
                        window.dispatchEvent(new CustomEvent('capyrel-toast', { detail: { type: 'error', message: data.message ?? 'Error.' } }));
                    }
                } catch { window.dispatchEvent(new CustomEvent('capyrel-toast',{detail:{type:'error',message:'Network error.'}})); }
                finally { this.loading = false; }
            }
         }"
         @open-create.window="open = true; isDirty = false; confirmClose = false"
         @keydown.escape.window="open && ({$closeHandler})">

        <div x-show="open" x-transition.opacity @click="{$closeHandler}"
             class="fixed inset-0 bg-black/30 backdrop-blur-sm z-40"></div>

        <div x-show="open"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full opacity-0"
             x-transition:enter-end="translate-x-0 opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0 opacity-100"
             x-transition:leave-end="translate-x-full opacity-0"
             class="fixed inset-y-0 right-0 z-50 flex flex-col w-full max-w-lg bg-white shadow-2xl">
{$unsavedOverlay}
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">New {$model}</h2>
                    <p class="text-sm text-gray-400 mt-0.5">Fill in the details below</p>
                </div>
                <button @click="{$closeHandler}" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-5">
                <form action="{{ route('{$route}.store') }}" method="POST"{$enctype}
                      @submit="submit(\$event)"{$dirtyTracking} novalidate>
                    @csrf
                    <div class="space-y-5">
{$createInputs}
                    </div>
                    <div class="flex items-center justify-between mt-8 pt-5 border-t border-gray-100">
                        <button type="button" @click="{$closeHandler}"
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

    {{-- ═══ EDIT SLIDE-OVER ═══ --}}
    <div x-data="{
            show: false{$dirtyXData},
            record: {},
            loading: false,
            errors: {},
            load(data) {
                this.record = data; this.show = true; this.isDirty = false; this.confirmClose = false;
                this.\$nextTick(() => {
                    this.\$el.querySelectorAll('[data-rf]').forEach(el => {
                        const v = this.record[el.dataset.rf] ?? '';
                        if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') el.value = v;
                    });
                });
            },
            async submit(event) {
                event.preventDefault();
                this.loading = true; this.errors = {};
                const url = '{{ url('{$route}') }}/' + this.record.id;
                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(event.target)
                    });
                    const data = await res.json();
                    if (res.ok) {
                        this.show = false; this.isDirty = false;
                        window.dispatchEvent(new CustomEvent('capyrel-toast', { detail: data.message ?? 'Updated.' }));
                        setTimeout(() => window.location.reload(), 800);
                    } else if (res.status === 422) {
                        this.errors = data.errors ?? {};
                    } else {
                        window.dispatchEvent(new CustomEvent('capyrel-toast', { detail: { type: 'error', message: data.message ?? 'Error.' } }));
                    }
                } catch { window.dispatchEvent(new CustomEvent('capyrel-toast',{detail:{type:'error',message:'Network error.'}})); }
                finally { this.loading = false; }
            }
         }"
         @open-edit.window="load(\$event.detail)"
         @keydown.escape.window="show && ({$editCloseBtn})">

        <div x-show="show" x-transition.opacity @click="{$editCloseHandler}"
             class="fixed inset-0 bg-black/30 backdrop-blur-sm z-40"></div>

        <div x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full opacity-0"
             x-transition:enter-end="translate-x-0 opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0 opacity-100"
             x-transition:leave-end="translate-x-full opacity-0"
             class="fixed inset-y-0 right-0 z-50 flex flex-col w-full max-w-lg bg-white shadow-2xl">
{$unsavedOverlay}
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Edit {$model}</h2>
                    <p class="text-sm text-gray-400 mt-0.5" x-show="record.id">ID: <span x-text="record.id"></span></p>
                </div>
                <button @click="{$editCloseBtn}" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-5">
                <form method="POST"{$enctype} @submit="submit(\$event)"{$dirtyTracking} novalidate>
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <div class="space-y-5">
{$editInputs}
                    </div>
                    <div class="flex items-center justify-between mt-8 pt-5 border-t border-gray-100">
                        <button type="button" @click="{$editCloseBtn}"
                                class="px-4 py-2.5 text-sm text-gray-500 hover:text-gray-700 rounded-xl hover:bg-gray-100 transition">
                            Cancel
                        </button>
                        <button type="submit" :disabled="loading"
                                class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 disabled:opacity-60 active:scale-95 transition shadow-sm shadow-indigo-200">
                            <svg x-show="loading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span x-text="loading ? 'Saving...' : 'Save Changes'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ═══ QUICK VIEW SLIDE-OVER ═══ --}}
    <div x-data="{ open: false, item: null }"
         @open-view.window="item = \$event.detail; open = true"
         @keydown.escape.window="open = false">
        <div x-show="open" x-transition.opacity @click="open = false"
             class="fixed inset-0 bg-black/20 backdrop-blur-sm z-40"></div>
        <div x-show="open"
             x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 right-0 z-50 flex flex-col w-full max-w-md bg-white shadow-2xl">
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{$model} Details</h2>
                    <p x-show="item" class="text-xs text-gray-400 mt-0.5">ID: <span x-text="item?.id"></span></p>
                </div>
                <div class="flex items-center gap-2">
                    <template x-if="item">
                        <button @click="\$dispatch('open-edit', item); open = false"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Edit
                        </button>
                    </template>
                    <button @click="open = false" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition">
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

    {{-- ═══ DELETE CONFIRMATION ═══ --}}
    <div x-data="{$deleteXData}"
         @open-delete.window="{$deleteOpenHandler}"
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
                            You're about to permanently delete <strong x-text="name"></strong>.
                        </p>
{$deleteConfirmField}
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button @click="open = false"
                            class="flex-1 px-4 py-2.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 active:scale-95 transition">
                        Cancel
                    </button>
                    <form :action="url" method="POST" class="flex-1">
                        @csrf @method('DELETE')
                        <button type="submit" {$deleteDisabled}
                                class="w-full px-4 py-2.5 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed transition shadow-sm shadow-red-200">
                            Yes, Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══ BULK DELETE MODAL ═══ --}}
    <div x-data="{ open: false, ids: [], count: 0 }"
         @open-bulk-delete.window="ids = \$event.detail.ids; count = \$event.detail.count; open = true"
         @keydown.escape.window="open = false">
        <div x-show="open" x-transition.opacity @click="open = false"
             class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div @click.stop x-show="open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
                <div class="flex items-start gap-4 mb-5">
                    <div class="w-12 h-12 bg-red-100 rounded-2xl flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 text-lg">Delete <span x-text="count"></span> items?</h3>
                        <p class="text-sm text-gray-500 mt-1">All selected records will be permanently removed.</p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button @click="open = false"
                            class="flex-1 px-4 py-2.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition">
                        Cancel
                    </button>
                    <form action="{{ url('{$route}/bulk-destroy') }}" method="POST" class="flex-1"
                          @submit="open = false">
                        @csrf
                        <template x-for="id in ids">
                            <input type="hidden" name="ids[]" :value="id">
                        </template>
                        <button type="submit"
                                class="w-full px-4 py-2.5 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 active:scale-95 transition shadow-sm shadow-red-200">
                            Delete All
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</x-app-layout>
BLADE;
    }

    // ── Bootstrap index ───────────────────────────────────────────────────────

    private function bsIndex(string $model, array $columns, array $relationships): string
    {
        $plural      = Str::camel(Str::plural($model));
        $singular    = Str::camel($model);
        $route       = Str::kebab(Str::plural($model));
        $title       = Str::headline(Str::plural($model));
        $cols        = $this->displayCols($columns);
        $headers     = $this->bsHeaders($cols);
        $cells       = $this->bsCells($singular, $cols);
        $createForm  = $this->buildInputs($columns, $relationships, null, 'create', 'bootstrap');
        $editForm    = $this->buildInputs($columns, $relationships, null, 'edit', 'bootstrap');
        $hasFiles    = !empty(UploadColumnDetector::collectFrom($columns));
        $enctype     = $hasFiles ? ' enctype="multipart/form-data"' : '';
        $deleteTyping = config('capyrel.modals.delete_typing', true);
        $deleteTypingHtml = $deleteTyping
            ? '<div class="mt-3"><label class="form-label small">Type the name to confirm:</label><input type="text" id="deleteTypingInput" class="form-control form-control-sm rounded-3" autocomplete="off"></div>'
            : '';
        $deleteTypingJs = $deleteTyping
            ? "document.getElementById('deleteTypingInput').value=''; document.getElementById('{$singular}DeleteBtn').disabled=true;"
            : '';
        $deleteTypingListener = $deleteTyping
            ? "document.getElementById('deleteTypingInput').addEventListener('input',function(){document.getElementById('{$singular}DeleteBtn').disabled=this.value!==window.capyrelDeleteName;});"
            : '';

        return <<<BLADE
@extends('layouts.app')
@section('content')
<div class="container-xl py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0 fw-bold">{$title}</h1>
        <button type="button" class="btn btn-primary rounded-3 d-inline-flex align-items-center gap-2"
                data-bs-toggle="offcanvas" data-bs-target="#createOffcanvas">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New {$model}
        </button>
    </div>

    {{-- Search --}}
    <div class="mb-3 d-flex align-items-center gap-3">
        <input type="search" id="{$singular}Search" class="form-control form-control-sm rounded-3" style="max-width:280px"
               placeholder="Search {$title}..." oninput="capyrelSearch(this.value)">
        <div id="bulkBar" class="d-none align-items-center gap-2">
            <span id="bulkCount" class="badge bg-primary rounded-pill small">0 selected</span>
            <button type="button" class="btn btn-sm btn-outline-danger rounded-3"
                    onclick="capyrelOpenBulkDelete()">Delete Selected</button>
            <button type="button" class="btn btn-sm btn-link text-muted" onclick="capyrelClearSelection()">Clear</button>
        </div>
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="{$singular}Table">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:40px">
                            <input type="checkbox" class="form-check-input" id="{$singular}SelectAll" onclick="capyrelToggleAll(this)">
                        </th>
{$headers}
                        <th class="text-end pe-4 text-muted small">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse(\${$plural} as \${$singular})
                    <tr class="{$singular}-row" data-id="{{ \${$singular}->id }}">
                        <td class="ps-3" onclick="event.stopPropagation()">
                            <input type="checkbox" class="form-check-input {$singular}-checkbox" value="{{ \${$singular}->id }}"
                                   onchange="capyrelUpdateBulk()">
                        </td>
{$cells}
                        <td class="text-end pe-3">
                            <div class="d-flex align-items-center justify-content-end gap-1">
                                <button type="button" class="btn btn-sm p-1 text-secondary"
                                        onclick="capyrelOpenView({{ Js::from(\${$singular}->makeHidden(['password','remember_token','two_factor_secret'])->toArray()) }})" title="View">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                                <button type="button" class="btn btn-sm p-1 text-secondary"
                                        onclick="capyrelOpenEdit({{ Js::from(\${$singular}->makeHidden(['password','remember_token','two_factor_secret'])->toArray()) }})" title="Edit">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                                <button type="button" class="btn btn-sm p-1 text-danger"
                                        onclick="capyrelOpenDelete('{{ route('{$route}.destroy', \${$singular}) }}', '{{ addslashes(\${$singular}->name ?? \${$singular}->title ?? '#'.\${$singular}->id) }}')" title="Delete">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="99" class="text-center py-5 text-muted">No {$title} yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if(\${$plural}->hasPages())
        <div class="card-footer bg-white border-top">{{ \${$plural}->links() }}</div>
        @endif
    </div>

    {{-- CREATE OFFCANVAS --}}
    <div class="offcanvas offcanvas-end" tabindex="-1" id="createOffcanvas" style="width:480px">
        <div class="offcanvas-header border-bottom py-4">
            <h5 class="offcanvas-title fw-bold mb-0">New {$model}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <div id="createErrors" class="alert alert-danger rounded-3 small d-none"></div>
            <form action="{{ route('{$route}.store') }}" method="POST"{$enctype} id="{$singular}CreateForm" novalidate>
                @csrf
                <div class="row g-3">
{$createForm}
                </div>
                <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="offcanvas">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4" id="{$singular}CreateBtn">
                        <span class="btn-label">Create {$model}</span>
                        <span class="spinner-border spinner-border-sm ms-1 d-none"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- EDIT OFFCANVAS --}}
    <div class="offcanvas offcanvas-end" tabindex="-1" id="editOffcanvas" style="width:480px">
        <div class="offcanvas-header border-bottom py-4">
            <h5 class="offcanvas-title fw-bold mb-0">Edit {$model}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <div id="editErrors" class="alert alert-danger rounded-3 small d-none"></div>
            <form method="POST"{$enctype} id="{$singular}EditForm" novalidate>
                @csrf
                <input type="hidden" name="_method" value="PUT">
                <div class="row g-3" id="editFields">
{$editForm}
                </div>
                <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="offcanvas">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4" id="{$singular}EditBtn">
                        <span class="btn-label">Save Changes</span>
                        <span class="spinner-border spinner-border-sm ms-1 d-none"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- VIEW OFFCANVAS --}}
    <div class="offcanvas offcanvas-end" tabindex="-1" id="viewOffcanvas" style="width:400px">
        <div class="offcanvas-header border-bottom py-4">
            <h5 class="offcanvas-title fw-bold">{$model} Details</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-3" id="viewToEditBtn">Edit</button>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
            </div>
        </div>
        <div class="offcanvas-body"><dl class="row small" id="viewDl"></dl></div>
    </div>

    {{-- DELETE MODAL --}}
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-body p-4">
                    <h6 class="fw-bold mb-1">Delete {$model}?</h6>
                    <p class="text-muted small" id="deleteMsg"></p>
                    {$deleteTypingHtml}
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-light flex-fill rounded-3" data-bs-dismiss="modal">Cancel</button>
                        <form id="deleteForm" method="POST" class="flex-fill">
                            @csrf @method('DELETE')
                            <button type="submit" id="{$singular}DeleteBtn" class="btn btn-danger w-100 rounded-3">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- BULK DELETE MODAL --}}
    <div class="modal fade" id="bulkDeleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-body p-4">
                    <h6 class="fw-bold mb-1">Delete selected?</h6>
                    <p class="text-muted small" id="bulkDeleteMsg"></p>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-light flex-fill rounded-3" data-bs-dismiss="modal">Cancel</button>
                        <form id="bulkDeleteForm" action="{{ url('{$route}/bulk-destroy') }}" method="POST" class="flex-fill">
                            @csrf
                            <div id="bulkDeleteIds"></div>
                            <button type="submit" class="btn btn-danger w-100 rounded-3">Delete All</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="capyrelToastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index:9999"></div>

</div>
@endsection

@push('scripts')
<script>
(function () {
    function showToast(msg, type) {
        var bg = type === 'error' ? 'text-bg-danger' : 'text-bg-success';
        var el = document.createElement('div');
        el.className = 'toast show align-items-center ' + bg + ' border-0 rounded-4 shadow mb-2';
        el.innerHTML = '<div class="d-flex"><div class="toast-body fw-medium">' + msg + '</div>' +
                       '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
        document.getElementById('capyrelToastContainer').appendChild(el);
        setTimeout(() => el.remove(), 4500);
    }

    @if(session('success'))
    showToast('{{ addslashes(session('success')) }}');
    @endif

    function ajaxForm(formEl, btnEl, errEl, onSuccess) {
        formEl.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = btnEl, lbl = btn.querySelector('.btn-label'), spin = btn.querySelector('.spinner-border');
            btn.disabled = true; spin.classList.remove('d-none'); lbl.textContent = 'Saving...';
            errEl.classList.add('d-none');
            fetch(formEl.action || formEl.dataset.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(formEl)
            })
            .then(r => r.json().then(d => ({ ok: r.ok, status: r.status, data: d })))
            .then(function ({ ok, status, data }) {
                if (ok) { onSuccess(data); }
                else if (status === 422) {
                    errEl.innerHTML = '<ul class="mb-0 ps-3">' + Object.values(data.errors || {}).flat().map(m => '<li>' + m + '</li>').join('') + '</ul>';
                    errEl.classList.remove('d-none');
                } else { showToast(data.message || 'Error.', 'error'); }
            })
            .catch(function () { showToast('Network error.', 'error'); })
            .finally(function () { btn.disabled = false; spin.classList.add('d-none'); lbl.textContent = lbl.dataset.orig || 'Save'; });
        });
    }

    var cForm = document.getElementById('{$singular}CreateForm');
    var cBtn  = document.getElementById('{$singular}CreateBtn');
    if (cBtn) cBtn.querySelector('.btn-label').dataset.orig = cBtn.querySelector('.btn-label').textContent;
    if (cForm && cBtn) {
        ajaxForm(cForm, cBtn, document.getElementById('createErrors'), function (d) {
            bootstrap.Offcanvas.getInstance(document.getElementById('createOffcanvas'))?.hide();
            showToast(d.message || 'Created.'); cForm.reset();
            setTimeout(() => window.location.reload(), 800);
        });
    }

    var eForm = document.getElementById('{$singular}EditForm');
    var eBtn  = document.getElementById('{$singular}EditBtn');
    if (eBtn) eBtn.querySelector('.btn-label').dataset.orig = eBtn.querySelector('.btn-label').textContent;
    if (eForm && eBtn) {
        ajaxForm(eForm, eBtn, document.getElementById('editErrors'), function (d) {
            bootstrap.Offcanvas.getInstance(document.getElementById('editOffcanvas'))?.hide();
            showToast(d.message || 'Updated.');
            setTimeout(() => window.location.reload(), 800);
        });
    }

    window.capyrelOpenEdit = function (data) {
        eForm.dataset.action = '{{ url('{$route}') }}/' + data.id;
        document.getElementById('editFields').querySelectorAll('[data-rf]').forEach(function (el) {
            el.value = data[el.dataset.rf] ?? '';
        });
        bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('editOffcanvas')).show();
    };

    window.capyrelOpenView = function (data) {
        var hidden = ['password', 'remember_token', 'two_factor_secret'];
        document.getElementById('viewDl').innerHTML = Object.entries(data).filter(([k]) => !hidden.includes(k))
            .map(([k, v]) => '<dt class="col-5 text-muted text-capitalize small">' + k.replace(/_/g,' ') + '</dt>' +
                             '<dd class="col-7">' + (v != null && v !== '' ? String(v) : '<span class="text-muted">—</span>') + '</dd>')
            .join('');
        document.getElementById('viewToEditBtn').onclick = function () {
            bootstrap.Offcanvas.getInstance(document.getElementById('viewOffcanvas'))?.hide();
            capyrelOpenEdit(data);
        };
        bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('viewOffcanvas')).show();
    };

    window.capyrelOpenDelete = function (url, name) {
        window.capyrelDeleteName = name;
        document.getElementById('deleteForm').action = url;
        document.getElementById('deleteMsg').textContent = 'Delete "' + name + '"? This cannot be undone.';
        {$deleteTypingJs}
        bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteModal')).show();
    };
    {$deleteTypingListener}

    window.capyrelSearch = function (q) {
        document.querySelectorAll('.{$singular}-row').forEach(r => {
            r.style.display = r.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
        });
    };

    window.capyrelToggleAll = function (cb) {
        document.querySelectorAll('.{$singular}-checkbox').forEach(c => { c.checked = cb.checked; });
        capyrelUpdateBulk();
    };

    window.capyrelUpdateBulk = function () {
        var checked = document.querySelectorAll('.{$singular}-checkbox:checked');
        var bar = document.getElementById('bulkBar');
        if (checked.length > 0) {
            bar.classList.remove('d-none'); bar.classList.add('d-flex');
            document.getElementById('bulkCount').textContent = checked.length + ' selected';
        } else {
            bar.classList.add('d-none'); bar.classList.remove('d-flex');
        }
        var all = document.getElementById('{$singular}SelectAll');
        if (all) all.checked = checked.length === document.querySelectorAll('.{$singular}-checkbox').length;
    };

    window.capyrelClearSelection = function () {
        document.querySelectorAll('.{$singular}-checkbox').forEach(c => { c.checked = false; });
        document.getElementById('{$singular}SelectAll').checked = false;
        capyrelUpdateBulk();
    };

    window.capyrelOpenBulkDelete = function () {
        var ids = Array.from(document.querySelectorAll('.{$singular}-checkbox:checked')).map(c => c.value);
        document.getElementById('bulkDeleteMsg').textContent = 'Permanently delete ' + ids.length + ' records?';
        var container = document.getElementById('bulkDeleteIds');
        container.innerHTML = ids.map(id => '<input type="hidden" name="ids[]" value="' + id + '">').join('');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkDeleteModal')).show();
    };
}());
</script>
@endpush
BLADE;
    }

    // ── Field builder ─────────────────────────────────────────────────────────

    private function buildInputs(array $columns, array $relationships, ?string $singular, string $mode, string $fw): string
    {
        $skip         = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];
        $columnNames  = array_column($columns, 'name');
        $slugSource   = ColumnTypeDetector::findSlugSource($columnNames);
        $lines        = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;

            // FK → select
            if (str_ends_with($name, '_id')) {
                $rel = $this->findBelongsToRel($name, $relationships);
                if ($rel) { $lines[] = $this->selectField($name, $rel, $mode, $fw); continue; }
            }

            // ENUM → select
            $enumVals = $this->parseEnumValues($col['type'] ?? '');
            if (strtolower($col['type_name'] ?? '') === 'enum' && !empty($enumVals)) {
                $lines[] = $this->enumSelectField($name, $col, $enumVals, $mode, $fw); continue;
            }

            // Upload → file input
            if (UploadColumnDetector::isUploadColumn($name)) {
                $lines[] = $this->fileInputField($name, $col, $mode, $fw); continue;
            }

            // Color → color picker
            if (ColumnTypeDetector::isColorColumn($name)) {
                $lines[] = $this->colorInputField($name, $col, $mode, $fw); continue;
            }

            // Coordinate → number with step=any
            if (ColumnTypeDetector::isCoordinateColumn($name)) {
                $lines[] = $this->coordinateInputField($name, $col, $mode, $fw); continue;
            }

            // Slug → auto-fill from title source (create mode only)
            $isSlug = ColumnTypeDetector::isSlugColumn($name);

            $lines[] = $this->inputField($name, $col, $mode, $fw, $isSlug && $mode === 'create' ? $slugSource : null);
        }

        foreach ($relationships as $rel) {
            if ($rel['type'] !== 'belongsToMany') continue;
            $lines[] = $this->checkboxGroupField($rel, $mode, $fw);
        }

        return implode("\n\n", $lines);
    }

    private function inputField(string $name, array $col, string $mode, string $fw, ?string $slugFor = null): string
    {
        $label    = $this->colLabel($col, $name);
        $type     = $this->inputType($name, $col['type_name'] ?? 'string');
        $rawType  = strtolower($col['type_name'] ?? 'string');
        $nullable = $col['nullable'] ?? false;
        $required = $nullable ? '' : 'required';

        if ($type === 'checkbox') {
            return $fw === 'tailwind'
                ? $this->twCheckbox($name, $label, $mode)
                : $this->bsCheckbox($name, $label, $mode);
        }

        if (in_array($rawType, ['text', 'longtext', 'mediumtext'])) {
            return $fw === 'tailwind'
                ? $this->twTextarea($name, $label, $mode, $required)
                : $this->bsTextarea($name, $label, $mode, $required);
        }

        // slug auto-fill extra attrs
        $slugAttrs = '';
        if ($slugFor !== null) {
            // This field IS the source for slug auto-fill
            $slugAttrs = " x-on:input.debounce.300ms=\"(function(v){ const s=\$root.querySelector('[name=slug]'); if(s&&!s.dataset.manual) s.value=v.toLowerCase().replace(/\\\\s+/g,'-').replace(/[^a-z0-9-]/g,''); })(\\$el.value)\"";
        }
        $isSlugInput = ColumnTypeDetector::isSlugColumn($name) && $mode === 'create';
        $slugInputAttrs = $isSlugInput
            ? " x-on:input=\"\$el.dataset.manual='1'\" x-init=\"\$el.dataset.manual=''\""
            : '';

        return $fw === 'tailwind'
            ? $this->twInput($name, $label, $type, $mode, $required, $slugAttrs . $slugInputAttrs)
            : $this->bsInput($name, $label, $type, $mode, $required);
    }

    private function colorInputField(string $name, array $col, string $mode, string $fw): string
    {
        $label    = $this->colLabel($col, $name);
        $valBind  = $mode === 'edit' ? " :value=\"record.{$name} ?? '#000000'\"" : " value=\"{{ old('{$name}', '#000000') }}\"";
        $rfAttr   = $mode === 'edit' ? " data-rf=\"{$name}\"" : '';

        if ($fw === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1.5">{$label}</label>
                            <div class="flex items-center gap-3">
                                <input type="color" id="{$name}_picker"{$valBind}
                                       class="h-10 w-10 rounded-lg border border-gray-300 cursor-pointer p-0.5 shrink-0"
                                       @input="document.getElementById('{$name}').value = \$el.value">
                                <input type="text" id="{$name}" name="{$name}"{$valBind}{$rfAttr}
                                       placeholder="#000000" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"
                                       class="block w-full rounded-xl border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500 transition"
                                       @input="document.getElementById('{$name}_picker').value = /^#[0-9a-f]{6}$/i.test(\$el.value) ? \$el.value : document.getElementById('{$name}_picker').value">
                            </div>
                            <template x-if="errors.{$name}"><p class="mt-1.5 text-xs text-red-600" x-text="errors.{$name}[0]"></p></template>
                        </div>
BLADE;
        }

        return <<<BLADE
                    <div class="col-12 mb-3">
                        <label for="{$name}" class="form-label fw-semibold small">{$label}</label>
                        <div class="input-group">
                            <input type="color" id="{$name}_picker" value="{{ old('{$name}', '#000000') }}"
                                   class="form-control form-control-color rounded-start-3" style="max-width:50px"
                                   oninput="document.getElementById('{$name}').value=this.value">
                            <input type="text" id="{$name}" name="{$name}" value="{{ old('{$name}', '#000000') }}"{$rfAttr}
                                   placeholder="#000000" class="form-control rounded-end-3 font-monospace @error('{$name}') is-invalid @enderror"
                                   oninput="if(/^#[0-9a-f]{6}$/i.test(this.value)) document.getElementById('{$name}_picker').value=this.value">
                        </div>
                        @error('{$name}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                    </div>
BLADE;
    }

    private function coordinateInputField(string $name, array $col, string $mode, string $fw): string
    {
        $label   = $this->colLabel($col, $name);
        $bounds  = ColumnTypeDetector::coordinateBounds($name);
        $min     = $bounds['min'] ?? '';
        $max     = $bounds['max'] ?? '';
        $minAttr = $min !== '' ? " min=\"{$min}\"" : '';
        $maxAttr = $max !== '' ? " max=\"{$max}\"" : '';
        $nullable = $col['nullable'] ?? false;
        $required = $nullable ? '' : 'required';
        $valBind  = $mode === 'edit' ? " :value=\"record.{$name} ?? ''\"" : " value=\"{{ old('{$name}') }}\"";
        $rfAttr   = $mode === 'edit' ? " data-rf=\"{$name}\"" : '';

        if ($fw === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1.5">{$label}</label>
                            <input type="number" id="{$name}" name="{$name}" step="any"{$minAttr}{$maxAttr} {$required}{$valBind}
                                   class="block w-full rounded-xl border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500 transition @error('{$name}') border-red-400 bg-red-50 @enderror"
                                   placeholder="{$label}">
                            <template x-if="errors.{$name}"><p class="mt-1.5 text-xs text-red-600" x-text="errors.{$name}[0]"></p></template>
                            @error('{$name}') <p class="mt-1.5 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
        }

        return <<<BLADE
                    <div class="col-6 mb-3">
                        <label for="{$name}" class="form-label fw-semibold small">{$label}</label>
                        <input type="number" id="{$name}" name="{$name}" step="any"{$minAttr}{$maxAttr} {$required}
                               value="{{ old('{$name}') }}"{$rfAttr}
                               class="form-control rounded-3 font-monospace @error('{$name}') is-invalid @enderror"
                               placeholder="{$label}">
                        @error('{$name}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                    </div>
BLADE;
    }

    private function fileInputField(string $name, array $col, string $mode, string $fw): string
    {
        $label    = $this->colLabel($col, $name);
        $accept   = UploadColumnDetector::acceptAttr($name);
        $isImage  = UploadColumnDetector::isImageColumn($name);
        $nullable = $col['nullable'] ?? true;
        $required = $nullable ? '' : 'required';

        if ($fw === 'tailwind') {
            $preview = $isImage ? <<<BLADE

                            <template x-if="record.{$name}">
                                <div class="mb-2 flex items-center gap-3">
                                    <img :src="'/storage/' + record.{$name}" class="w-12 h-12 object-cover rounded-lg border border-gray-200" onerror="this.style.display='none'">
                                    <p class="text-xs text-gray-400">Current image. Upload to replace.</p>
                                </div>
                            </template>
BLADE : <<<BLADE

                            <template x-if="record.{$name}">
                                <p class="text-xs text-gray-500 mb-1">Current: <a :href="'/storage/' + record.{$name}" target="_blank" class="text-indigo-500 underline" x-text="record.{$name}?.split('/').pop()"></a></p>
                            </template>
BLADE;
            $previewHtml = $mode === 'edit' ? $preview : '';
            return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1.5">
                                {$label} @if('{$required}' === 'required') <span class="text-red-500">*</span> @endif
                            </label>
{$previewHtml}
                            <input type="file" id="{$name}" name="{$name}" {$accept} {$required}
                                   class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition @error('{$name}') ring-2 ring-red-300 @enderror">
                            <template x-if="errors.{$name}"><p class="mt-1.5 text-xs text-red-600" x-text="errors.{$name}[0]"></p></template>
                            @error('{$name}') <p class="mt-1.5 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
        }

        return <<<BLADE
                    <div class="col-12 mb-3">
                        <label for="{$name}" class="form-label fw-semibold small">{$label}</label>
                        <input type="file" id="{$name}" name="{$name}" {$accept} {$required} data-rf="{$name}"
                               class="form-control rounded-3 @error('{$name}') is-invalid @enderror">
                        @error('{$name}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                    </div>
BLADE;
    }

    private function enumSelectField(string $name, array $col, array $values, string $mode, string $fw): string
    {
        $label    = $this->colLabel($col, $name);
        $nullable = $col['nullable'] ?? false;
        $required = $nullable ? '' : 'required';
        $rfAttr   = $mode === 'edit' ? " data-rf=\"{$name}\"" : '';
        $options  = '';
        foreach ($values as $val) {
            $pretty   = Str::headline(str_replace(['_', '-'], ' ', $val));
            $options .= "\n                                <option value=\"{$val}\">{$pretty}</option>";
        }

        if ($fw === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1.5">
                                {$label} @if('{$required}' === 'required') <span class="text-red-500">*</span> @endif
                            </label>
                            <select id="{$name}" name="{$name}" {$required}{$rfAttr}
                                    class="block w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 @error('{$name}') border-red-400 @enderror">
                                <option value="">— Select —</option>{$options}
                            </select>
                            <template x-if="errors.{$name}"><p class="mt-1.5 text-xs text-red-600" x-text="errors.{$name}[0]"></p></template>
                            @error('{$name}') <p class="mt-1.5 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
        }

        return <<<BLADE
                    <div class="col-12 mb-3">
                        <label for="{$name}" class="form-label fw-semibold small">{$label}</label>
                        <select id="{$name}" name="{$name}" {$required}{$rfAttr}
                                class="form-select rounded-3 @error('{$name}') is-invalid @enderror">
                            <option value="">— Select —</option>{$options}
                        </select>
                        @error('{$name}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                    </div>
BLADE;
    }

    private function twInput(string $name, string $label, string $type, string $mode, string $req, string $extra = ''): string
    {
        $valBind = $mode === 'edit' ? " :value=\"record.{$name} ?? ''\"" : " value=\"{{ old('{$name}') }}\"";
        return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1.5">
                                {$label} @if('{$req}' === 'required') <span class="text-red-500">*</span> @endif
                            </label>
                            <input type="{$type}" id="{$name}" name="{$name}"{$valBind} {$req}{$extra}
                                   class="block w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 transition @error('{$name}') border-red-400 bg-red-50 ring-2 ring-red-200 @enderror">
                            <template x-if="errors.{$name}"><p class="mt-1.5 text-xs text-red-600" x-text="errors.{$name}[0]"></p></template>
                            @error('{$name}') <p class="mt-1.5 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
    }

    private function bsInput(string $name, string $label, string $type, string $mode, string $req): string
    {
        $rfAttr = $mode === 'edit' ? " data-rf=\"{$name}\"" : '';
        $val    = $mode === 'edit' ? '' : " value=\"{{ old('{$name}') }}\"";
        return <<<BLADE
                    <div class="col-12 mb-3">
                        <label for="{$name}" class="form-label fw-semibold small">
                            {$label} @if('{$req}' === 'required') <span class="text-danger">*</span> @endif
                        </label>
                        <input type="{$type}" id="{$name}" name="{$name}"{$val}{$rfAttr} {$req}
                               class="form-control rounded-3 @error('{$name}') is-invalid @enderror">
                        @error('{$name}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                    </div>
BLADE;
    }

    private function twTextarea(string $name, string $label, string $mode, string $req): string
    {
        $rfAttr  = $mode === 'edit' ? " data-rf=\"{$name}\"" : '';
        $content = $mode === 'edit' ? '' : "{{ old('{$name}') }}";
        return <<<BLADE
                        <div>
                            <label for="{$name}" class="block text-sm font-medium text-gray-700 mb-1.5">
                                {$label} @if('{$req}' === 'required') <span class="text-red-500">*</span> @endif
                            </label>
                            <textarea id="{$name}" name="{$name}" rows="4" {$req}{$rfAttr}
                                      class="block w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 transition @error('{$name}') border-red-400 bg-red-50 @enderror">{$content}</textarea>
                            <template x-if="errors.{$name}"><p class="mt-1.5 text-xs text-red-600" x-text="errors.{$name}[0]"></p></template>
                            @error('{$name}') <p class="mt-1.5 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
    }

    private function bsTextarea(string $name, string $label, string $mode, string $req): string
    {
        $rfAttr  = $mode === 'edit' ? " data-rf=\"{$name}\"" : '';
        $content = $mode === 'edit' ? '' : "{{ old('{$name}') }}";
        return <<<BLADE
                    <div class="col-12 mb-3">
                        <label for="{$name}" class="form-label fw-semibold small">{$label}</label>
                        <textarea id="{$name}" name="{$name}" rows="4" {$req}{$rfAttr}
                                  class="form-control rounded-3 @error('{$name}') is-invalid @enderror">{$content}</textarea>
                        @error('{$name}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                    </div>
BLADE;
    }

    private function twCheckbox(string $name, string $label, string $mode): string
    {
        $checked = $mode === 'edit' ? ":checked=\"!!record.{$name}\"" : "{{ old('{$name}') ? 'checked' : '' }}";
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

    private function bsCheckbox(string $name, string $label, string $mode): string
    {
        return <<<BLADE
                    <div class="col-12 mb-3 form-check ms-2">
                        <input type="hidden" name="{$name}" value="0">
                        <input type="checkbox" id="{$name}" name="{$name}" value="1" data-rf="{$name}"
                               class="form-check-input">
                        <label for="{$name}" class="form-check-label fw-semibold small">{$label}</label>
                    </div>
BLADE;
    }

    private function selectField(string $fkCol, array $rel, string $mode, string $fw): string
    {
        $label      = Str::headline(Str::beforeLast($fkCol, '_id'));
        $related    = $rel['related'];
        $relatedVar = Str::camel(Str::plural($related));
        $relItem    = Str::camel(Str::singular($related));
        $rfAttr     = $mode === 'edit' ? " data-rf=\"{$fkCol}\"" : '';

        if ($fw === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label for="{$fkCol}" class="block text-sm font-medium text-gray-700 mb-1.5">{$label}</label>
                            <select id="{$fkCol}" name="{$fkCol}"{$rfAttr}
                                    class="block w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 @error('{$fkCol}') border-red-400 @enderror">
                                <option value="">— Select {$label} —</option>
                                @foreach(\${$relatedVar} as \${$relItem})
                                    <option value="{{ \${$relItem}->id }}" {{ old('{$fkCol}') == \${$relItem}->id ? 'selected' : '' }}>
                                        {{ \${$relItem}->name ?? \${$relItem}->title ?? \${$relItem}->id }}
                                    </option>
                                @endforeach
                            </select>
                            <template x-if="errors.{$fkCol}"><p class="mt-1.5 text-xs text-red-600" x-text="errors.{$fkCol}[0]"></p></template>
                            @error('{$fkCol}') <p class="mt-1.5 text-xs text-red-600">{{ \$message }}</p> @enderror
                        </div>
BLADE;
        }

        return <<<BLADE
                    <div class="col-12 mb-3">
                        <label for="{$fkCol}" class="form-label fw-semibold small">{$label}</label>
                        <select id="{$fkCol}" name="{$fkCol}"{$rfAttr}
                                class="form-select rounded-3 @error('{$fkCol}') is-invalid @enderror">
                            <option value="">— Select {$label} —</option>
                            @foreach(\${$relatedVar} as \${$relItem})
                                <option value="{{ \${$relItem}->id }}" {{ old('{$fkCol}') == \${$relItem}->id ? 'selected' : '' }}>
                                    {{ \${$relItem}->name ?? \${$relItem}->title ?? \${$relItem}->id }}
                                </option>
                            @endforeach
                        </select>
                        @error('{$fkCol}') <div class="invalid-feedback">{{ \$message }}</div> @enderror
                    </div>
BLADE;
    }

    private function checkboxGroupField(array $rel, string $mode, string $fw): string
    {
        $method     = $rel['method'];
        $related    = $rel['related'];
        $relatedVar = Str::camel(Str::plural($related));
        $relItem    = Str::camel(Str::singular($related));
        $label      = Str::headline($method);

        if ($fw === 'tailwind') {
            return <<<BLADE
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{$label}</label>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach(\${$relatedVar} as \${$relItem})
                                    <label class="flex items-center gap-2.5 p-3 rounded-xl border border-gray-200 hover:border-indigo-300 cursor-pointer transition has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                                        <input type="checkbox" name="{$method}_ids[]" value="{{ \${$relItem}->id }}"
                                               class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-sm text-gray-700">{{ \${$relItem}->name ?? \${$relItem}->title ?? \${$relItem}->id }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
BLADE;
        }

        return <<<BLADE
                    <div class="col-12 mb-3">
                        <label class="form-label fw-semibold small d-block">{$label}</label>
                        <div class="row g-2">
                            @foreach(\${$relatedVar} as \${$relItem})
                                <div class="col-6 col-md-4">
                                    <label class="d-flex align-items-center gap-2 p-2 border rounded-3 cursor-pointer">
                                        <input type="checkbox" class="form-check-input mt-0"
                                               name="{$method}_ids[]" value="{{ \${$relItem}->id }}">
                                        <span class="small">{{ \${$relItem}->name ?? \${$relItem}->title ?? \${$relItem}->id }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
BLADE;
    }

    // ── Quick-view fields ─────────────────────────────────────────────────────

    private function twViewFields(array $cols): string
    {
        $skip  = ['password', 'remember_token', 'two_factor_secret'];
        $lines = [];
        foreach ($cols as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;
            $label = Str::headline($name);

            if (UploadColumnDetector::isImageColumn($name)) {
                $lines[] = <<<BLADE
                        <div>
                            <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-0.5">{$label}</dt>
                            <dd>
                                <template x-if="item?.{$name}">
                                    <img :src="'/storage/' + item.{$name}" class="w-20 h-20 object-cover rounded-xl border border-gray-200" onerror="this.style.display='none'">
                                </template>
                                <template x-if="!item?.{$name}"><span class="text-sm text-gray-400">—</span></template>
                            </dd>
                        </div>
BLADE;
                continue;
            }

            if (ColumnTypeDetector::isColorColumn($name)) {
                $lines[] = <<<BLADE
                        <div>
                            <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-0.5">{$label}</dt>
                            <dd class="flex items-center gap-2">
                                <span class="inline-block w-5 h-5 rounded-md border border-gray-200 shrink-0" :style="`background:\${item?.{$name} ?? '#eee'}`"></span>
                                <span class="text-sm font-mono text-gray-900" x-text="item?.{$name} ?? '—'"></span>
                            </dd>
                        </div>
BLADE;
                continue;
            }

            $lines[] = <<<BLADE
                        <div>
                            <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-0.5">{$label}</dt>
                            <dd class="text-sm text-gray-900" x-text="item?.{$name} ?? '—'"></dd>
                        </div>
BLADE;
        }
        return implode("\n", $lines);
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private function isTw(): bool { return $this->framework->isTailwind(); }

    private function displayCols(array $columns): array
    {
        $skip = ['password', 'remember_token', 'two_factor_secret', 'deleted_at'];
        return array_values(array_filter(array_slice($columns, 0, 6), fn($c) => !in_array($c['name'], $skip)));
    }

    private function twHeaders(array $cols): string
    {
        return collect($cols)->map(fn($c) =>
            "                            <th class=\"px-6 py-3.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider\">"
            . Str::headline($c['name']) . "</th>"
        )->implode("\n");
    }

    private function twCells(string $singular, array $cols): string
    {
        return collect($cols)->map(function ($c) use ($singular) {
            $name = $c['name'];
            if (UploadColumnDetector::isImageColumn($name)) {
                return "                            <td class=\"px-6 py-3.5\">"
                    . "@if(\${$singular}->{$name})<img src=\"{{ asset('storage/'.\${$singular}->{$name}) }}\" class=\"w-10 h-10 object-cover rounded-lg\" onerror=\"this.style.display='none'\">@else<span class=\"text-gray-300\">—</span>@endif"
                    . "</td>";
            }
            if (ColumnTypeDetector::isColorColumn($name)) {
                return "                            <td class=\"px-6 py-3.5\">"
                    . "<div class=\"flex items-center gap-2\"><span class=\"inline-block w-4 h-4 rounded border border-gray-200\" style=\"background:{{ \${$singular}->{$name} ?? '#eee' }}\"></span><span class=\"text-sm font-mono text-gray-600\">{{ \${$singular}->{$name} ?? '—' }}</span></div>"
                    . "</td>";
            }
            return "                            <td class=\"px-6 py-3.5 text-sm text-gray-900\">{{ \${$singular}->{$name} ?? '—' }}</td>";
        })->implode("\n");
    }

    private function bsHeaders(array $cols): string
    {
        return collect($cols)->map(fn($c) =>
            "                        <th class=\"fw-semibold small\">" . Str::headline($c['name']) . "</th>"
        )->implode("\n");
    }

    private function bsCells(string $singular, array $cols): string
    {
        return collect($cols)->map(function ($c) use ($singular) {
            $name = $c['name'];
            if (UploadColumnDetector::isImageColumn($name)) {
                return "                        <td>@if(\${$singular}->{$name})<img src=\"{{ asset('storage/'.\${$singular}->{$name}) }}\" style=\"width:38px;height:38px;object-fit:cover\" class=\"rounded-3\">@else<span class=\"text-muted\">—</span>@endif</td>";
            }
            return "                        <td class=\"small\">{{ \${$singular}->{$name} ?? '—' }}</td>";
        })->implode("\n");
    }

    private function inputType(string $name, string $type): string
    {
        if (str_contains($name, 'email'))                                  return 'email';
        if ($name === 'password' || str_ends_with($name, '_password'))     return 'password';
        if (str_contains($name, 'url') || $name === 'website')             return 'url';
        if (str_contains($name, 'phone') || str_contains($name, 'mobile')) return 'tel';
        if (str_ends_with($name, '_at') || $type === 'date')               return 'date';
        if (in_array($type, ['datetime', 'timestamp']))                     return 'datetime-local';
        if (in_array($type, ['int', 'integer', 'bigint', 'smallint']))     return 'number';
        if (in_array($type, ['decimal', 'float', 'double', 'numeric']))    return 'number';
        if (in_array($type, ['boolean', 'tinyint', 'bool']))                return 'checkbox';
        return 'text';
    }

    private function parseEnumValues(string $typeStr): array
    {
        if (!str_starts_with(strtolower($typeStr), 'enum(')) return [];
        preg_match_all("/'([^']+)'/", $typeStr, $matches);
        return $matches[1] ?? [];
    }

    private function colLabel(array $col, string $name): string
    {
        $comment = trim($col['comment'] ?? '');
        return $comment !== '' ? $comment : Str::headline($name);
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
