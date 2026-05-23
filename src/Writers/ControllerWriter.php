<?php

namespace Julio\Capyrel\Writers;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\FrameworkDetector;
use Julio\Capyrel\Detectors\UploadColumnDetector;

class ControllerWriter
{
    public function __construct(private FrameworkDetector $framework) {}

    // ── Public API ────────────────────────────────────────────────────────────

    public function generateNested(
        string $modelName,
        string $parentModel,
        string $parentFk,
        array  $columns = []
    ): string {
        $variable     = Str::camel($modelName);
        $parentVar    = Str::camel($parentModel);
        $storeRules   = $this->inlineRules($columns, [], 'store');
        $updateRules  = $this->inlineRules($columns, [], 'update');
        $uploadCols   = UploadColumnDetector::collectFrom($columns);
        $storeUpload       = $this->buildUploadBlock($uploadCols, 'store');
        $updateUpload      = $this->buildUploadBlock($uploadCols, 'update', '$' . $variable);
        $fileCleanupUpdate = $this->fileCleanupAfterUpdate($uploadCols);  // runs after ->update(), uses $_prev* vars
        $fileCleanupDelete = $this->fileCleanupLines($uploadCols, '$' . $variable); // runs after ->delete(), reads model
        $useStorage        = !empty($uploadCols) ? "\nuse Illuminate\\Support\\Facades\\Storage;" : '';
        $useStr       = !empty($uploadCols) ? "\nuse Illuminate\\Support\\Str;" : '';

        return <<<PHP
<?php

namespace App\Http\Controllers;

use App\Models\\{$modelName};
use App\Models\\{$parentModel};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;{$useStorage}{$useStr}

class {$modelName}Controller extends Controller
{
    public function store(Request \$request, {$parentModel} \${$parentVar}): RedirectResponse|JsonResponse
    {
        \$validated = \$request->validate([
{$storeRules}
        ]);
{$storeUpload}
        \$validated['{$parentFk}'] = \${$parentVar}->id;
        \${$variable} = {$modelName}::create(\$validated);

        if (\$request->wantsJson()) {
            return response()->json(['message' => '{$modelName} added.', 'data' => \${$variable}], 201);
        }
        return back()->with('success', '{$modelName} added.');
    }

    public function update(Request \$request, {$parentModel} \${$parentVar}, {$modelName} \${$variable}): RedirectResponse|JsonResponse
    {
        \$validated = \$request->validate([
{$updateRules}
        ]);
{$updateUpload}
        \${$variable}->update(\$validated);
{$fileCleanupUpdate}
        if (\$request->wantsJson()) {
            return response()->json(['message' => '{$modelName} updated.', 'data' => \${$variable}->fresh()]);
        }
        return back()->with('success', '{$modelName} updated.');
    }

    public function destroy(Request \$request, {$parentModel} \${$parentVar}, {$modelName} \${$variable}): RedirectResponse|JsonResponse
    {
        \${$variable}->delete();
{$fileCleanupDelete}
        if (\$request->wantsJson()) {
            return response()->json(['message' => '{$modelName} deleted.']);
        }
        return back()->with('success', '{$modelName} deleted.');
    }
}
PHP;
    }

    public function generate(string $modelName, array $relationships, array $columns = []): string
    {
        $variable    = Str::camel($modelName);
        $variables   = Str::camel(Str::plural($modelName));
        $viewPrefix  = Str::kebab(Str::plural($modelName));
        $tableName   = Str::snake(Str::plural($modelName));

        // Relations
        $withRelations = $this->withRelationsString($relationships);
        $withCountPart = $this->withCountString($relationships);
        $eagerChain    = $this->buildEagerChain($withRelations, $withCountPart);

        // Validation
        $hasSlugCol  = !empty(array_filter($columns, fn($c) => in_array($c['name'], ['slug','handle','permalink','url_key'])));
        $storeRules  = $this->inlineRules($columns, $relationships, 'store',  $tableName);
        $updateRules = $this->inlineRules($columns, $relationships, 'update', $tableName);

        // Upload
        $uploadCols   = UploadColumnDetector::collectFrom($columns);
        $hasSoftDelete = $this->hasSoftDelete($columns);
        $storeUpload  = $this->buildUploadBlock($uploadCols, 'store');
        $updateUpload = $this->buildUploadBlock($uploadCols, 'update', '$' . $variable);
        $fileCleanupUpdate  = $this->fileCleanupAfterUpdate($uploadCols);
        // On soft-delete we preserve files so the record can be restored intact.
        // forceDelete() handles final removal.
        $fileCleanupDestroy = $hasSoftDelete
            ? ''
            : $this->fileCleanupLines($uploadCols, '$' . $variable);
        $fileCleanupForce = $this->fileCleanupLines($uploadCols, '$' . $variable);

        // Pivot sync (must run AFTER model is saved)
        $hasBtm      = collect($relationships)->contains('type', 'belongsToMany');
        $useTransaction = $hasBtm && (bool) config('capyrel.features.transaction_pivots', true);
        $pivotSync   = $this->buildBtmSync($relationships, $variable, $useTransaction);

        // Index: related model loads for select dropdowns
        $relatedLoads = $this->relatedLoadForIndex($relationships);
        $compactExtra = $this->compactRelated($relationships);

        // Search
        $searchEnabled = (bool) config('capyrel.features.search', true);
        $searchCols    = $searchEnabled ? $this->searchableColumns($columns) : [];
        $searchBlock   = $this->buildSearchBlock($searchCols);
        $enumCols      = $this->enumColumns($columns);
        $enumFilter    = $this->buildEnumFilterBlock($enumCols);

        // Pagination
        $paginationType = config('capyrel.pagination.type', 'paginate');
        $perPage        = (int) config('capyrel.pagination.per_page', 15);
        $paginateCall   = match ($paginationType) {
            'cursor' => "cursorPaginate({$perPage})",
            'simple' => "simplePaginate({$perPage})",
            default  => "paginate({$perPage})",
        };

        // Integrations
        $useScout  = (bool) config('capyrel.scout.enabled', true) && $this->framework->hasLaravelScout();
        $useMedia  = (bool) config('capyrel.spatie.media_library', true) && $this->framework->hasSpatieMedia();
        $usePerms  = (bool) config('capyrel.spatie.permissions', true) && $this->framework->hasSpatiePermissions();

        // C3 — Advanced filters (range / multi-enum / date-range)
        $filterBlock  = $this->buildFilterBlock($columns);

        // C4 — Column sorting
        $sortBlock    = $this->buildSortBlock($columns);

        // C5 — Conditional relationship includes
        $relNames           = collect($relationships)->filter(fn($r) => !empty($r['method']) && $r['type'] !== 'morphTo')->pluck('method')->values()->all();
        $includesIndexBlock = $this->buildIncludesBlock($relNames, 'index');
        $includesShowBlock  = $this->buildIncludesBlock($relNames, 'show', $variable);

        // C6 — Cache layer
        $cacheTtl    = (int) config('capyrel.cache_ttl', 0);
        $useCache    = $cacheTtl > 0;
        $cacheBlock  = $useCache ? $this->buildCacheBlock($modelName, $variables, $paginateCall) : '';
        $cacheInvalidate = $useCache ? $this->buildCacheInvalidation($modelName) : '';

        // C7 — Event dispatching
        $eventDispatches = $this->buildEventDispatches($modelName, $variable);
        $eventClasses    = $eventDispatches['classes'];
        $storeEvent      = $eventDispatches['store'];
        $updateEvent     = $eventDispatches['update'];
        $destroyEvent    = $eventDispatches['destroy'];

        // C14 — Multitenancy scoping
        $tenantCol       = $this->detectTenantColumn($columns);
        $tenantIndexBlock = $tenantCol ? $this->buildTenantScoping($tenantCol, 'index') : '';
        $tenantStoreBlock = $tenantCol ? $this->buildTenantScoping($tenantCol, 'store') : '';
        $tenantCheckBlock = $tenantCol ? $this->buildTenantScoping($tenantCol, 'check', $variable) : '';

        // C15 — Auth owner stamping (user_id, owner_id, created_by, etc.)
        $authOwnerCol        = $tenantCol ? null : $this->detectAuthOwnerColumn($columns);
        $authOwnerStoreBlock = $authOwnerCol ? $this->buildAuthOwnerBlock($authOwnerCol, 'store') : '';
        $authOwnerCheckBlock = $authOwnerCol ? $this->buildAuthOwnerBlock($authOwnerCol, 'check', $variable) : '';

        // C18 — Trashed param
        $trashedBlock = $hasSoftDelete ? $this->buildTrashedBlock() : '';

        // Build the index query block
        $indexQuery = $useScout
            ? $this->scoutIndexBlock($modelName, $variables, $perPage)
            : $this->standardIndexBlock(
                $modelName, $variables, $eagerChain,
                $searchBlock, $enumFilter, $paginateCall,
                $filterBlock, $sortBlock,
                $includesIndexBlock, $tenantIndexBlock,
                $trashedBlock, $cacheBlock
            );

        // Authorization lines per method
        $authIndex   = $usePerms ? "        \$this->authorize('viewAny', {$modelName}::class);\n" : '';
        $authStore   = $usePerms ? "        \$this->authorize('create', {$modelName}::class);\n" : '';
        $authShow    = $usePerms ? "        \$this->authorize('view', \${$variable});\n" : '';
        $authUpdate  = $usePerms ? "        \$this->authorize('update', \${$variable});\n" : '';
        $authDestroy = $usePerms ? "        \$this->authorize('delete', \${$variable});\n" : '';

        // Soft deletes
        $softMethods = ($hasSoftDelete && (bool) config('capyrel.features.soft_deletes', true))
            ? $this->buildSoftDeleteMethods($modelName, $variable, $viewPrefix, $fileCleanupForce)
            : '';

        // Imports
        $imports = $this->buildImports($uploadCols, $useMedia, $hasBtm && $useTransaction, $relationships, $hasSlugCol, $useCache, $eventClasses);

        // JSON store response — load relations so API clients get full data
        $storeJsonData = $withRelations
            ? "\${$variable}->load([{$withRelations}])"
            : "\${$variable}";

        // Bulk destroy
        $bulkMethod = $this->buildBulkDestroyMethod($modelName, $tableName, $uploadCols, $viewPrefix, $usePerms);

        return <<<PHP
<?php

namespace App\Http\Controllers;

use App\Models\\{$modelName};
{$imports}
class {$modelName}Controller extends Controller
{
    public function index(Request \$request): \Illuminate\View\View|\Illuminate\Http\JsonResponse
    {
{$authIndex}{$indexQuery}{$relatedLoads}
        if (\$request->wantsJson()) {
            return response()->json(\${$variables});
        }
        return view('{$viewPrefix}.index', compact('{$variables}'{$compactExtra}));
    }

    public function store(Request \$request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
{$authStore}        \$validated = \$request->validate([
{$storeRules}
        ]);
{$storeUpload}{$tenantStoreBlock}{$authOwnerStoreBlock}
        \${$variable} = {$modelName}::create(\$validated);
{$pivotSync}{$storeEvent}{$cacheInvalidate}
        if (\$request->wantsJson()) {
            return response()->json(['message' => '{$modelName} created successfully.', 'data' => {$storeJsonData}], 201);
        }
        return redirect()->route('{$viewPrefix}.index')
            ->with('success', '{$modelName} created successfully.');
    }

    public function show({$modelName} \${$variable}, Request \$request): \Illuminate\View\View|\Illuminate\Http\JsonResponse
    {
{$authShow}{$tenantCheckBlock}{$includesShowBlock}        \${$variable}->loadMissing([{$withRelations}]);

        if (\$request->wantsJson()) {
            return response()->json(\${$variable});
        }
        return view('{$viewPrefix}.show', compact('{$variable}'));
    }

    public function update(Request \$request, {$modelName} \${$variable}): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
{$authUpdate}{$tenantCheckBlock}{$authOwnerCheckBlock}        \$validated = \$request->validate([
{$updateRules}
        ]);
{$updateUpload}
        \${$variable}->update(\$validated);
{$fileCleanupUpdate}{$pivotSync}{$updateEvent}{$cacheInvalidate}
        if (\$request->wantsJson()) {
            return response()->json(['message' => '{$modelName} updated successfully.', 'data' => \${$variable}->fresh()]);
        }
        return redirect()->route('{$viewPrefix}.index')
            ->with('success', '{$modelName} updated successfully.');
    }

    public function destroy(Request \$request, {$modelName} \${$variable}): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
{$authDestroy}{$tenantCheckBlock}{$authOwnerCheckBlock}{$destroyEvent}        \${$variable}->delete();
{$fileCleanupDestroy}{$cacheInvalidate}
        if (\$request->wantsJson()) {
            return response()->json(['message' => '{$modelName} deleted.']);
        }
        return redirect()->route('{$viewPrefix}.index')
            ->with('success', '{$modelName} deleted.');
    }
{$bulkMethod}{$softMethods}}
PHP;
    }

    public function inject(string $path, string $modelName, array $relationships): bool
    {
        if (!file_exists($path)) return false;

        $content = file_get_contents($path);
        $eager   = $this->withRelationsString($relationships);

        if (empty($eager) || str_contains($content, 'capyrel:')) return false;

        $replaced = preg_replace(
            '/(' . preg_quote($modelName, '/') . '::)(paginate|get|all|first)\s*\(/',
            "{$modelName}::with([{$eager}])->$2(",
            $content, -1, $count
        );

        if ($count === 0 || $replaced === $content) return false;

        $replaced = str_replace(
            "class {$modelName}Controller",
            "// capyrel: eager loading injected\nclass {$modelName}Controller",
            $replaced
        );

        file_put_contents($path, $replaced);
        return true;
    }

    public function findControllerPath(string $modelName): ?string
    {
        $path = app_path("Http/Controllers/{$modelName}Controller.php");
        return file_exists($path) ? $path : null;
    }

    // ── Import builder ────────────────────────────────────────────────────────

    private function buildImports(
        array $uploadCols,
        bool  $useMedia,
        bool  $useDb,
        array $relationships,
        bool  $hasSlugCol    = false,
        bool  $useCache      = false,
        array $eventClasses  = []
    ): string {
        $lines = [];

        // Related models for select dropdowns
        $relatedModels = collect($relationships)
            ->filter(fn($r) => in_array($r['type'], ['belongsTo', 'belongsToMany']) && !empty($r['related']))
            ->pluck('related')
            ->unique()
            ->sort()
            ->values();

        foreach ($relatedModels as $model) {
            $lines[] = "use App\\Models\\{$model};";
        }

        if ($useDb) {
            $lines[] = 'use Illuminate\Support\Facades\DB;';
        }

        if ($useCache) {
            $lines[] = 'use Illuminate\Support\Facades\Cache;';
        }

        if (!empty($uploadCols) && !$useMedia) {
            $lines[] = 'use Illuminate\Support\Facades\Storage;';
            $lines[] = 'use Illuminate\Support\Str;';
        }

        if ($hasSlugCol) {
            $lines[] = 'use Illuminate\Validation\Rule;';
        }

        foreach ($eventClasses as $eventClass) {
            $lines[] = "use App\\Events\\{$eventClass};";
        }

        $lines[] = 'use Illuminate\Http\Request;';

        sort($lines);

        return implode("\n", $lines) . "\n";
    }

    // ── Eager loading ─────────────────────────────────────────────────────────

    /**
     * Eager-load string covering ALL relation types (including belongsTo to avoid N+1).
     */
    private function withRelationsString(array $relationships): string
    {
        return collect($relationships)
            ->filter(fn($r) => !empty($r['method']) && $r['type'] !== 'morphTo')
            ->map(fn($r) => "'{$r['method']}'")
            ->implode(', ');
    }

    /**
     * withCount() for hasMany / belongsToMany relations — avoids N+1 counts.
     */
    private function withCountString(array $relationships): string
    {
        return collect($relationships)
            ->filter(fn($r) => in_array($r['type'], ['hasMany', 'belongsToMany']) && !empty($r['method']))
            ->map(fn($r) => "'{$r['method']}'")
            ->implode(', ');
    }

    private function buildEagerChain(string $withRelations, string $withCount): string
    {
        $chain = '';
        if ($withRelations) $chain .= "->with([{$withRelations}])";
        if ($withCount)     $chain .= "->withCount([{$withCount}])";
        return $chain;
    }

    // ── Index query blocks ────────────────────────────────────────────────────

    private function standardIndexBlock(
        string $modelName,
        string $variables,
        string $eagerChain,
        string $searchBlock,
        string $enumFilter,
        string $paginateCall,
        string $filterBlock    = '',
        string $sortBlock      = '',
        string $includesBlock  = '',
        string $tenantBlock    = '',
        string $trashedBlock   = '',
        string $cacheBlock     = ''
    ): string {
        // When caching is active the paginate call is embedded inside $cacheBlock
        $paginateLine = $cacheBlock !== ''
            ? ''
            : "        \${$variables} = \$query->latest()->{$paginateCall};\n";

        return <<<PHP
        \$query = {$modelName}::{$eagerChain};
{$includesBlock}{$tenantBlock}{$trashedBlock}{$searchBlock}{$enumFilter}{$filterBlock}{$sortBlock}{$paginateLine}{$cacheBlock}
PHP;
    }

    private function scoutIndexBlock(string $modelName, string $variables, int $perPage): string
    {
        return <<<PHP

        \${$variables} = \$request->filled('search')
            ? {$modelName}::search(\$request->string('search'))->paginate({$perPage})
            : {$modelName}::latest()->paginate({$perPage});

PHP;
    }

    // ── Search ────────────────────────────────────────────────────────────────

    private function buildSearchBlock(array $searchCols): string
    {
        if (empty($searchCols)) return '';

        $first      = array_shift($searchCols);
        $orClauses  = '';
        foreach ($searchCols as $col) {
            $orClauses .= "\n                  ->orWhere('{$col}', 'like', \"%{\$term}%\")";
        }

        return <<<PHP

        if (\$request->filled('search')) {
            // Escape LIKE special chars so user input cannot affect the pattern
            \$term = str_replace(['%', '_'], ['\\\\%', '\\\\_'], \$request->string('search'));
            \$query->where(function (\$q) use (\$term) {
                \$q->where('{$first}', 'like', "%{\$term}%"){$orClauses};
            });
        }

PHP;
    }

    private function buildEnumFilterBlock(array $enumCols): string
    {
        if (empty($enumCols)) return '';

        $lines = ["\n        // ENUM column filters\n"];
        foreach ($enumCols as ['name' => $name, 'values' => $values]) {
            $in = "'" . implode("','", $values) . "'";
            $lines[] = "        if (\$request->filled('{$name}') && in_array(\$request->input('{$name}'), [{$in}])) {";
            $lines[] = "            \$query->where('{$name}', \$request->input('{$name}'));";
            $lines[] = "        }";
        }
        return implode("\n", $lines) . "\n";
    }

    // ── Validation ────────────────────────────────────────────────────────────

    private function inlineRules(array $columns, array $relationships, string $mode, string $tableName = ''): string
    {
        $authOwnerCandidates = ['user_id', 'owner_id', 'created_by', 'author_id', 'assigned_to', 'submitted_by'];
        $skip     = array_merge(
            ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'],
            $authOwnerCandidates
        );
        $lines    = [];
        $presence = $mode === 'store' ? "'required'" : "'sometimes'";

        $columnNames = array_column($columns, 'name');

        foreach ($columns as $col) {
            $name     = $col['name'];
            $nullable = (bool) ($col['nullable'] ?? false);
            $type     = strtolower($col['type_name'] ?? 'string');
            $maxLen   = $col['length'] ?? null;

            if (in_array($name, $skip)) continue;

            // ── File / image ───────────────────────────────────────────────
            if (UploadColumnDetector::isUploadColumn($name)) {
                $lines[] = "            '{$name}' => [" . UploadColumnDetector::validationRules($name) . "],";
                continue;
            }

            $rules = [];

            // ── Email ──────────────────────────────────────────────────────
            if (str_contains($name, 'email')) {
                $rules[] = $nullable ? "'nullable'" : $presence;
                $rules[] = "'string'";
                $rules[] = "'lowercase'";
                $rules[] = "'email:rfc,dns'";
                $rules[] = "'max:255'";
                $lines[] = "            '{$name}' => [{$this->joinRules($rules)}],";
                continue;
            }

            // ── Password ───────────────────────────────────────────────────
            if ($name === 'password' || str_ends_with($name, '_password')) {
                $rules = ["'required'", "'string'", "'min:8'", "'confirmed'"];
                $lines[] = "            '{$name}' => [{$this->joinRules($rules)}],";
                continue;
            }

            // ── Slug / handle (unique, ignore self on update) ──────────────
            if (in_array($name, ['slug', 'handle', 'permalink', 'url_key'])) {
                $rules[] = $nullable ? "'nullable'" : $presence;
                $rules[] = "'string'";
                $rules[] = "'max:255'";
                if ($tableName !== '') {
                    if ($mode === 'store') {
                        $rules[] = "'unique:{$tableName},{$name}'";
                    } else {
                        // Ignore the current record's own value on update
                        $routeKey = Str::camel(Str::singular($tableName));
                        $rules[]  = "Rule::unique('{$tableName}', '{$name}')->ignore(\$request->route('{$routeKey}')?->id)";
                    }
                }
                $lines[] = "            '{$name}' => [{$this->joinRules($rules)}],";
                continue;
            }

            // ── FK (_id) ───────────────────────────────────────────────────
            if (str_ends_with($name, '_id')) {
                $guessedTable = Str::plural(Str::beforeLast($name, '_id'));
                $rules[]      = $nullable ? "'nullable'" : $presence;
                $rules[]      = "'integer'";
                $rules[]      = "'min:1'";
                $rules[]      = "'exists:{$guessedTable},id'";
                $lines[]      = "            '{$name}' => [{$this->joinRules($rules)}],";
                continue;
            }

            // ── ENUM ───────────────────────────────────────────────────────
            if ($type === 'enum') {
                $vals    = $this->parseEnumValues($col['type'] ?? '');
                $rules[] = $nullable ? "'nullable'" : $presence;
                $rules[] = !empty($vals) ? "'in:" . implode(',', $vals) . "'" : "'string'";
                $lines[] = "            '{$name}' => [{$this->joinRules($rules)}],";
                continue;
            }

            // ── Type-based rules ───────────────────────────────────────────
            $rules[] = $nullable ? "'nullable'" : $presence;

            if (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint'])) {
                $rules[] = "'integer'";
            } elseif (in_array($type, ['decimal', 'float', 'double', 'numeric', 'real'])) {
                $rules[] = "'numeric'";
            } elseif (in_array($type, ['boolean', 'bool'])) {
                $rules[] = "'boolean'";
            } elseif (in_array($type, ['date'])) {
                $rules[] = "'date'";
                $rules[] = "'date_format:Y-m-d'";
            } elseif (in_array($type, ['datetime', 'timestamp'])) {
                $rules[] = "'date'";
            } elseif (in_array($type, ['json', 'jsonb'])) {
                $rules[] = "'array'";
            } elseif (in_array($type, ['text', 'mediumtext', 'longtext'])) {
                $rules[] = "'string'";
            } else {
                $rules[] = "'string'";
                $limit   = $maxLen ?? 255;
                $rules[] = "'max:{$limit}'";
            }

            // Slug detection: source column for a slug field
            if (in_array($name, ['title', 'name', 'headline', 'subject']) && in_array('slug', $columnNames)) {
                // Nothing extra — slug handles its own uniqueness above
            }

            $lines[] = "            '{$name}' => [{$this->joinRules($rules)}],";
        }

        return implode("\n", $lines);
    }

    // ── Upload handling ───────────────────────────────────────────────────────

    /**
     * For store(): if a file is uploaded store it; always unset the key otherwise
     * so mass-assignment never receives null from a missing file input.
     *
     * For update(): capture the current path INSIDE the if-block so we have the
     * old value available for post-update cleanup without any class-level state.
     */
    private function buildUploadBlock(array $uploadCols, string $mode, string $modelVar = ''): string
    {
        if (empty($uploadCols)) return '';

        $useMedia = (bool) config('capyrel.spatie.media_library', true) && $this->framework->hasSpatieMedia();
        $disk     = config('capyrel.storage_disk', 'public');
        $maxW     = (int) config('capyrel.images.max_width', 1200);
        $maxH     = (int) config('capyrel.images.max_height', 1200);
        $quality  = (int) config('capyrel.images.quality', 80);
        $hasIv    = $this->framework->hasInterventionImage();
        $lines    = ["\n        // File uploads"];

        foreach ($uploadCols as $col) {
            $storagePath = UploadColumnDetector::storagePath($col);
            $isImage     = UploadColumnDetector::isImageColumn($col);
            $prev        = "\$_prev" . Str::studly($col);   // e.g. $_prevAvatar

            if ($useMedia) {
                $collection = Str::slug($storagePath);
                $lines[] = "        // Spatie Media Library — call after model save:";
                $lines[] = "        // \$model->addMediaFromRequest('{$col}')->toMediaCollection('{$collection}');";
                $lines[] = "        // (Model must implement HasMedia + InteractsWithMedia)";
                continue;
            }

            $lines[] = "        if (\$request->hasFile('{$col}') && \$request->file('{$col}')->isValid()) {";

            if ($mode === 'update' && $modelVar !== '') {
                // Read the OLD path directly from the model instance — $validated never holds the
                // current DB value, it only holds what came in from the request.
                $lines[] = "            {$prev} = {$modelVar}->{$col}; // current path — deleted after successful update";
            }

            if ($isImage && $hasIv) {
                $lines[] = "            \$_f   = \$request->file('{$col}');";
                $lines[] = "            \$_mgr = new \\Intervention\\Image\\ImageManager(new \\Intervention\\Image\\Drivers\\Gd\\Driver());";
                $lines[] = "            \$_img = \$_mgr->read(\$_f->getPathname())->scaleDown({$maxW}, {$maxH});";
                $lines[] = "            \$_p   = '{$storagePath}/' . Str::uuid() . '.webp';";
                $lines[] = "            Storage::disk('{$disk}')->put(\$_p, (string) \$_img->toWebp({$quality}));";
                $lines[] = "            \$validated['{$col}'] = \$_p;";
            } else {
                $lines[] = "            \$validated['{$col}'] = \$request->file('{$col}')->store('{$storagePath}', '{$disk}');";
            }

            $lines[] = "        } else {";
            $lines[] = "            unset(\$validated['{$col}']); // no new file — keep existing DB value";
            $lines[] = "        }";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Cleanup that runs AFTER $model->update() succeeds.
     * Deletes old files whose paths were captured inside buildUploadBlock('update').
     * Only runs when a new file was actually uploaded (prev var will be set).
     */
    private function fileCleanupAfterUpdate(array $uploadCols): string
    {
        if (empty($uploadCols)) return '';

        $disk  = config('capyrel.storage_disk', 'public');
        $lines = ["\n        // Remove replaced files from storage (runs only when a new file was uploaded)"];

        foreach ($uploadCols as $col) {
            $prev = "\$_prev" . Str::studly($col);
            $lines[] = "        if (isset({$prev}) && {$prev}) {";
            $lines[] = "            Storage::disk('{$disk}')->delete({$prev});";
            $lines[] = "        }";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * File cleanup for destroy() — runs AFTER delete() succeeds.
     * Soft-delete controllers should NOT delete files here; only forceDelete should.
     */
    private function fileCleanupLines(array $uploadCols, string $modelVar): string
    {
        if (empty($uploadCols)) return '';

        $disk  = config('capyrel.storage_disk', 'public');
        $lines = ["        // Clean up stored files"];
        foreach ($uploadCols as $col) {
            $lines[] = "        if ({$modelVar}->{$col}) {";
            $lines[] = "            Storage::disk('{$disk}')->delete({$modelVar}->{$col});";
            $lines[] = "        }";
        }
        return implode("\n", $lines) . "\n";
    }

    // ── Pivot sync (always AFTER model is created/updated) ────────────────────

    private function buildBtmSync(array $relationships, string $variable, bool $wrapTransaction): string
    {
        $btm = collect($relationships)->where('type', 'belongsToMany');
        if ($btm->isEmpty()) return '';

        $syncLines = [];
        foreach ($btm as $rel) {
            $method      = $rel['method'];
            $relatedIds  = "{$method}_ids";
            $syncLines[] = "            if (\$request->has('{$relatedIds}')) {";
            $syncLines[] = "                \${$variable}->{$method}()->sync(\$request->input('{$relatedIds}', []));";
            $syncLines[] = "            }";
        }

        $inner = implode("\n", $syncLines);

        if ($wrapTransaction) {
            return <<<PHP

        DB::transaction(function () use (\$request, \${$variable}) {
{$inner}
        });

PHP;
        }

        return "\n" . $inner . "\n";
    }

    // ── Bulk destroy ──────────────────────────────────────────────────────────

    private function buildBulkDestroyMethod(
        string $modelName,
        string $tableName,
        array  $uploadCols,
        string $viewPrefix,
        bool   $usePerms
    ): string {
        $disk        = config('capyrel.storage_disk', 'public');
        $authLine    = $usePerms ? "\n        \$this->authorize('deleteAny', {$modelName}::class);\n" : '';

        $fileCleanup = '';
        if (!empty($uploadCols)) {
            $lines = [];
            foreach ($uploadCols as $col) {
                $lines[] = "            if (\$model->{$col}) Storage::disk('{$disk}')->delete(\$model->{$col});";
            }
            $fileCleanup = implode("\n", $lines) . "\n            ";
        }

        $useStorage = !empty($uploadCols) ? '' : '';

        return <<<PHP


    // Add this route inside your auth middleware group in routes/web.php:
    // Route::post('/{$viewPrefix}/bulk-destroy', [static::class, 'bulkDestroy'])->name('{$viewPrefix}.bulk-destroy');
    public function bulkDestroy(\Illuminate\Http\Request \$request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
{$authLine}        \$ids = \$request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'exists:{$tableName},id'],
        ])['ids'];

        {$modelName}::whereIn('id', \$ids)->each(function ({$modelName} \$model) {
            {$fileCleanup}\$model->delete();
        });

        \$count = count(\$ids);

        if (\$request->wantsJson()) {
            return response()->json(['message' => "{\$count} record(s) deleted."]);
        }
        return redirect()->route('{$viewPrefix}.index')
            ->with('success', "{\$count} record(s) deleted.");
    }
PHP;
    }

    // ── Soft delete methods ───────────────────────────────────────────────────

    private function buildSoftDeleteMethods(
        string $modelName,
        string $variable,
        string $viewPrefix,
        string $fileCleanupForce
    ): string {
        return <<<PHP


    public function restore(Request \$request, int \$id): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        \${$variable} = {$modelName}::onlyTrashed()->findOrFail(\$id);
        \${$variable}->restore();

        if (\$request->wantsJson()) {
            return response()->json(['message' => '{$modelName} restored.', 'data' => \${$variable}]);
        }
        return back()->with('success', '{$modelName} restored.');
    }

    public function forceDelete(Request \$request, int \$id): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        \${$variable} = {$modelName}::onlyTrashed()->findOrFail(\$id);
        \${$variable}->forceDelete();
{$fileCleanupForce}
        if (\$request->wantsJson()) {
            return response()->json(['message' => '{$modelName} permanently deleted.']);
        }
        return redirect()->route('{$viewPrefix}.index')
            ->with('success', '{$modelName} permanently deleted.');
    }
PHP;
    }

    // ── Related loads for index (edit modal selects) ──────────────────────────

    private function relatedLoadForIndex(array $relationships): string
    {
        $btRelations = collect($relationships)->filter(
            fn($r) => in_array($r['type'], ['belongsTo', 'belongsToMany']) && !empty($r['related'])
        )->unique('related');

        if ($btRelations->isEmpty()) return '';

        $lines = [];
        foreach ($btRelations as $rel) {
            $relatedClass = $rel['related'];
            $var          = Str::camel(Str::plural($relatedClass));
            // Only select the columns needed for dropdowns — never dump entire tables
            $lines[] = "        \${$var} = \\App\\Models\\{$relatedClass}::select(['id', 'name'])->orderBy('name')->get();";
        }

        return "\n" . implode("\n", $lines) . "\n";
    }

    private function compactRelated(array $relationships): string
    {
        $btRelations = collect($relationships)->filter(
            fn($r) => in_array($r['type'], ['belongsTo', 'belongsToMany']) && !empty($r['related'])
        )->unique('related');

        if ($btRelations->isEmpty()) return '';

        $vars = $btRelations
            ->map(fn($r) => "'" . Str::camel(Str::plural($r['related'])) . "'")
            ->implode(', ');

        return ", {$vars}";
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function searchableColumns(array $columns): array
    {
        $skip = ['id', 'password', 'remember_token', 'two_factor_secret', 'email_verified_at',
                 'created_at', 'updated_at', 'deleted_at'];
        $result = [];
        foreach ($columns as $col) {
            $name = $col['name'];
            $type = strtolower($col['type_name'] ?? 'string');
            if (in_array($name, $skip) || str_ends_with($name, '_id')) continue;
            if (UploadColumnDetector::isUploadColumn($name)) continue;
            if (in_array($type, ['varchar', 'char', 'string', 'text', 'mediumtext', 'longtext', 'tinytext'])) {
                $result[] = $name;
                if (count($result) >= 3) break;
            }
        }
        return $result;
    }

    private function enumColumns(array $columns): array
    {
        $result = [];
        foreach ($columns as $col) {
            if (strtolower($col['type_name'] ?? '') === 'enum') {
                $vals = $this->parseEnumValues($col['type'] ?? '');
                if (!empty($vals)) {
                    $result[] = ['name' => $col['name'], 'values' => $vals];
                }
            }
        }
        return $result;
    }

    private function hasSoftDelete(array $columns): bool
    {
        return in_array('deleted_at', array_column($columns, 'name'));
    }

    private function parseEnumValues(string $typeStr): array
    {
        if (!str_starts_with(strtolower($typeStr), 'enum(')) return [];
        preg_match_all("/'([^']+)'/", $typeStr, $matches);
        return $matches[1] ?? [];
    }

    private function joinRules(array $rules): string
    {
        return implode(', ', $rules);
    }

    // ── C3 — Advanced filtering ───────────────────────────────────────────────

    /**
     * Builds range filters for numeric/decimal columns, multi-value ENUM filters,
     * and date-range filters for *_at datetime columns (excluding created_at/updated_at).
     */
    private function buildFilterBlock(array $columns): string
    {
        $numericTypes  = ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'decimal', 'float', 'double', 'numeric', 'real'];
        $datetimeTypes = ['datetime', 'timestamp'];
        $skipDateCols  = ['created_at', 'updated_at', 'deleted_at'];

        $lines = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            $type = strtolower($col['type_name'] ?? 'string');

            // Skip system / FK / upload columns
            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'])) continue;
            if (str_ends_with($name, '_id')) continue;
            if (UploadColumnDetector::isUploadColumn($name)) continue;

            // Numeric range filter (C3a)
            if (in_array($type, $numericTypes)) {
                $min = "{$name}_min";
                $max = "{$name}_max";
                $lines[] = "        // Range filter: ?{$min}=&{$max}=";
                $lines[] = "        if (\$request->filled('{$min}')) {";
                $lines[] = "            \$query->where('{$name}', '>=', \$request->float('{$min}'));";
                $lines[] = "        }";
                $lines[] = "        if (\$request->filled('{$max}')) {";
                $lines[] = "            \$query->where('{$name}', '<=', \$request->float('{$max}'));";
                $lines[] = "        }";
                continue;
            }

            // Multi-value ENUM filter (C3b) — moved to buildEnumFilterBlock for single-value,
            // here we add multi-value (array) variant
            if ($type === 'enum') {
                $vals    = $this->parseEnumValues($col['type'] ?? '');
                if (empty($vals)) continue;
                $allowed = "['" . implode("', '", $vals) . "']";
                $lines[] = "        // Multi-value ENUM filter: ?{$name}[]=val1&{$name}[]=val2";
                $lines[] = "        if (\$request->has('{$name}') && is_array(\$request->input('{$name}'))) {";
                $lines[] = "            \$_allowed_{$name} = {$allowed};";
                $lines[] = "            \$_input_{$name}   = array_intersect((array) \$request->input('{$name}'), \$_allowed_{$name});";
                $lines[] = "            if (\$_input_{$name}) {";
                $lines[] = "                \$query->whereIn('{$name}', array_values(\$_input_{$name}));";
                $lines[] = "            }";
                $lines[] = "        }";
                continue;
            }

            // Date range for *_at datetime columns (C3c)
            if (in_array($type, $datetimeTypes) && str_ends_with($name, '_at') && !in_array($name, $skipDateCols)) {
                $prefix = rtrim(substr($name, 0, -3), '_'); // e.g. "published" from "published_at"
                $from   = "{$prefix}_from";
                $to     = "{$prefix}_to";
                $lines[] = "        // Date range filter: ?{$from}=&{$to}=";
                $lines[] = "        if (\$request->filled('{$from}')) {";
                $lines[] = "            \$query->where('{$name}', '>=', \$request->input('{$from}'));";
                $lines[] = "        }";
                $lines[] = "        if (\$request->filled('{$to}')) {";
                $lines[] = "            \$query->where('{$name}', '<=', \$request->input('{$to}'));";
                $lines[] = "        }";
            }
        }

        if (empty($lines)) return '';

        return "\n        // Advanced column filters\n" . implode("\n", $lines) . "\n";
    }

    // ── C4 — Column sorting ───────────────────────────────────────────────────

    /**
     * Builds a whitelist-guarded orderBy block for the index query.
     */
    private function buildSortBlock(array $columns): string
    {
        $sensitiveNames = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
                           'email_verified_at', 'deleted_at'];
        $systemCols     = ['id'];

        $sortable = ['created_at']; // always include created_at as default fallback

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $sensitiveNames)) continue;
            if (in_array($name, $systemCols)) continue;
            if (UploadColumnDetector::isUploadColumn($name)) continue;
            if (!in_array($name, $sortable)) {
                $sortable[] = $name;
            }
        }

        $sortableList = "['" . implode("', '", $sortable) . "']";

        return <<<'PHPBLOCK'

        // C4 — Column sorting: ?sort=col&direction=asc|desc
PHPBLOCK
            . "        \$_sortable = {$sortableList};\n"
            . "        \$_sort = in_array(\$request->input('sort'), \$_sortable) ? \$request->input('sort') : 'created_at';\n"
            . "        \$_dir  = \$request->input('direction') === 'asc' ? 'asc' : 'desc';\n"
            . "        \$query->orderBy(\$_sort, \$_dir);\n";
    }

    // ── C5 — Conditional relationship includes ────────────────────────────────

    /**
     * Builds an include block allowing API consumers to sideload relationships.
     * $mode = 'index' uses $query->with(); $mode = 'show' uses $model->loadMissing().
     *
     * @param string $variable  Only required for mode = 'show': the camelCase model variable name.
     */
    private function buildIncludesBlock(array $relNames, string $mode, string $variable = ''): string
    {
        if (empty($relNames)) return '';

        $allowed = "['" . implode("', '", $relNames) . "']";

        if ($mode === 'index') {
            return <<<PHPBLOCK

        // C5 — Conditional relationship includes: ?include=rel1,rel2
        \$_allowedIncludes = {$allowed};
        \$_includes = collect(explode(',', \$request->string('include', '')))
            ->map(fn(\$s) => trim(\$s))
            ->intersect(\$_allowedIncludes)
            ->values()->toArray();
        if (\$_includes) {
            \$query->with(\$_includes);
        }

PHPBLOCK;
        }

        // show mode — uses $variable->loadMissing()
        return <<<PHPBLOCK

        // C5 — Conditional relationship includes: ?include=rel1,rel2
        \$_allowedIncludes = {$allowed};
        \$_includes = collect(explode(',', \$request->string('include', '')))
            ->map(fn(\$s) => trim(\$s))
            ->intersect(\$_allowedIncludes)
            ->values()->toArray();
        if (\$_includes) {
            \${$variable}->loadMissing(\$_includes);
        }

PHPBLOCK;
    }

    // ── C6 — Cache layer ──────────────────────────────────────────────────────

    private function buildCacheBlock(string $modelName, string $variables, string $paginateCall): string
    {
        $key   = Str::snake($modelName);
        $ttl   = (int) config('capyrel.cache_ttl', 0);

        return <<<PHPBLOCK

        // C6 — Cache layer (capyrel.cache_ttl = {$ttl}s)
        \$_cacheKey = '{$key}.index.' . md5(serialize(\$request->query()));
        \${$variables} = Cache::remember(\$_cacheKey, config('capyrel.cache_ttl', 0), fn() => \$query->latest()->{$paginateCall});

PHPBLOCK;
    }

    private function buildCacheInvalidation(string $modelName): string
    {
        $key = Str::snake($modelName);

        return <<<PHPBLOCK

        // C6 — Bust index cache
        Cache::forget('{$key}.index.*');
PHPBLOCK;
    }

    // ── C7 — Event dispatching ────────────────────────────────────────────────

    /**
     * Returns an array with keys:
     *   'classes'  => string[] of short event class names that exist on disk
     *   'store'    => string PHP snippet for after store
     *   'update'   => string PHP snippet for after update
     *   'destroy'  => string PHP snippet for before delete
     */
    private function buildEventDispatches(string $modelName, string $variable): array
    {
        $created = "{$modelName}Created";
        $updated = "{$modelName}Updated";
        $deleted = "{$modelName}Deleted";

        $eventsPath = app_path('Events');
        $classes    = [];
        $store      = '';
        $update     = '';
        $destroy    = '';

        if (file_exists("{$eventsPath}/{$created}.php")) {
            $classes[] = $created;
            $store = "\n        event(new {$created}(\${$variable})); // C7\n";
        }

        if (file_exists("{$eventsPath}/{$updated}.php")) {
            $classes[] = $updated;
            $update = "\n        event(new {$updated}(\${$variable})); // C7\n";
        }

        if (file_exists("{$eventsPath}/{$deleted}.php")) {
            $classes[] = $deleted;
            $destroy = "\n        event(new {$deleted}(\${$variable})); // C7 — fires before delete\n";
        }

        return compact('classes', 'store', 'update', 'destroy');
    }

    // ── C14 — Multitenancy scoping ────────────────────────────────────────────

    private function detectTenantColumn(array $columns): ?string
    {
        $candidates = ['team_id', 'tenant_id', 'organization_id', 'org_id', 'account_id'];
        $names      = array_column($columns, 'name');
        foreach ($candidates as $c) {
            if (in_array($c, $names)) return $c;
        }
        return null;
    }

    private function detectAuthOwnerColumn(array $columns): ?string
    {
        $candidates = ['user_id', 'owner_id', 'created_by', 'author_id', 'assigned_to', 'submitted_by'];
        $names      = array_column($columns, 'name');
        foreach ($candidates as $c) {
            if (in_array($c, $names)) return $c;
        }
        return null;
    }

    private function buildAuthOwnerBlock(string $col, string $mode, string $variable = ''): string
    {
        return match ($mode) {
            'store'  => "\n        \$validated['{$col}'] = \$request->user()->id;\n",
            'index'  => "\n        \$query->where('{$col}', \$request->user()->id);\n",
            'check'  => "\n        abort_if(\${$variable}->{$col} !== \$request->user()->id, 403);\n",
            default  => '',
        };
    }

    /**
     * Builds tenant-scoping lines for index, store, and update/destroy check modes.
     */
    private function buildTenantScoping(string $tenantCol, string $mode, string $variable = ''): string
    {
        return match ($mode) {
            'index' => "\n        // C14 — Multitenancy scope\n        \$query->where('{$tenantCol}', \$request->user()->{$tenantCol});\n",
            'store' => "\n        // C14 — Stamp tenant column\n        \$validated['{$tenantCol}'] = \$request->user()->{$tenantCol};\n",
            'check' => "\n        // C14 — Ensure record belongs to current tenant\n        abort_if(\${$variable}->{$tenantCol} !== \$request->user()->{$tenantCol}, 403);\n",
            default => '',
        };
    }

    // ── C18 — Trashed param ───────────────────────────────────────────────────

    private function buildTrashedBlock(): string
    {
        return <<<'PHPBLOCK'

        // C18 — Trashed param: ?trashed=only|with
        if ($request->input('trashed') === 'only') {
            $query->onlyTrashed();
        } elseif ($request->input('trashed') === 'with') {
            $query->withTrashed();
        }

PHPBLOCK;
    }
}
