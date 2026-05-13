<?php

namespace Julio\Capyrel\Writers;

use Illuminate\Support\Str;

class ControllerWriter
{
    /**
     * Generate a nested resource controller for DEPENDENT models (Comment under Post, etc).
     * The parent is injected via route model binding — FK is set automatically.
     */
    public function generateNested(string $modelName, string $parentModel, string $parentFk, array $columns = []): string
    {
        $variable    = Str::camel($modelName);
        $parentVar   = Str::camel($parentModel);
        $viewPrefix  = Str::kebab(Str::plural($parentModel)) . '.' . Str::kebab(Str::plural($modelName));
        $parentRoute = Str::kebab(Str::plural($parentModel));
        $storeRules  = $this->inlineRules($columns, [], 'store');
        $updateRules = $this->inlineRules($columns, [], 'update');

        return <<<PHP
<?php

namespace App\Http\Controllers;

use App\Models\\{$modelName};
use App\Models\\{$parentModel};
use Illuminate\Http\Request;

// capyrel: nested controller — {$modelName} always belongs to {$parentModel}
class {$modelName}Controller extends Controller
{
    public function store(Request \$request, {$parentModel} \${$parentVar})
    {
        \$validated = \$request->validate([
{$storeRules}
        ]);

        \$validated['{$parentFk}'] = \${$parentVar}->id;
        \${$variable} = {$modelName}::create(\$validated);

        return back()->with('success', '{$modelName} added.');
    }

    public function edit({$parentModel} \${$parentVar}, {$modelName} \${$variable})
    {
        return view('{$viewPrefix}.edit', compact('{$parentVar}', '{$variable}'));
    }

    public function update(Request \$request, {$parentModel} \${$parentVar}, {$modelName} \${$variable})
    {
        \$validated = \$request->validate([
{$updateRules}
        ]);

        \${$variable}->update(\$validated);

        return back()->with('success', '{$modelName} updated.');
    }

    public function destroy({$parentModel} \${$parentVar}, {$modelName} \${$variable})
    {
        \${$variable}->delete();

        return back()->with('success', '{$modelName} deleted.');
    }
}
PHP;
    }

    /**
     * Generate a full resource controller with inline validation and relationship awareness.
     * Uses $request->validate([...]) — works without FormRequest, no validated() crash.
     */
    public function generate(string $modelName, array $relationships, array $columns = []): string
    {
        $variable     = Str::camel($modelName);
        $variables    = Str::camel(Str::plural($modelName));
        $viewPrefix   = Str::kebab(Str::plural($modelName));
        $eagerLoad    = $this->eagerLoadString($relationships);
        $storeRules   = $this->inlineRules($columns, $relationships, 'store');
        $updateRules  = $this->inlineRules($columns, $relationships, 'update');
        $fillable     = $this->fillableString($columns);
        $extraMethods = $this->extraMethods($modelName, $relationships);
        $relatedLoads = $this->relatedLoadForCreate($relationships);

        return <<<PHP
<?php

namespace App\Http\Controllers;

use App\Models\\{$modelName};
use Illuminate\Http\Request;

class {$modelName}Controller extends Controller
{
    // capyrel: eager loads all detected relations — prevents N+1
    public function index()
    {
        \${$variables} = {$modelName}::with([{$eagerLoad}])->paginate(15);

        return view('{$viewPrefix}.index', compact('{$variables}'));
    }

    public function create()
    {{$relatedLoads}
        return view('{$viewPrefix}.create'{$this->compactRelated($relationships)});
    }

    public function store(Request \$request)
    {
        \$validated = \$request->validate([
{$storeRules}
        ]);

        \${$variable} = {$modelName}::create(\$validated);

        return redirect()->route('{$viewPrefix}.show', \${$variable})
            ->with('success', '{$modelName} created successfully.');
    }

    // capyrel: loads all detected relations for the detail view
    public function show({$modelName} \${$variable})
    {
        \${$variable}->load([{$eagerLoad}]);

        return view('{$viewPrefix}.show', compact('{$variable}'));
    }

    public function edit({$modelName} \${$variable})
    {{$relatedLoads}
        return view('{$viewPrefix}.edit', compact('{$variable}'{$this->compactRelatedRaw($relationships)}));
    }

    public function update(Request \$request, {$modelName} \${$variable})
    {
        \$validated = \$request->validate([
{$updateRules}
        ]);

        \${$variable}->update(\$validated);

        return redirect()->route('{$viewPrefix}.show', \${$variable})
            ->with('success', '{$modelName} updated successfully.');
    }

    public function destroy({$modelName} \${$variable})
    {
        \${$variable}->delete();

        return redirect()->route('{$viewPrefix}.index')
            ->with('success', '{$modelName} deleted.');
    }
{$extraMethods}}
PHP;
    }

    /**
     * Inject eager loading into an existing controller's index/show methods.
     */
    public function inject(string $path, string $modelName, array $relationships): bool
    {
        if (!file_exists($path)) return false;

        $content = file_get_contents($path);
        $eager   = $this->eagerLoadString($relationships);

        if (empty($eager) || str_contains($content, 'capyrel:')) {
            return false;
        }

        $replaced = preg_replace(
            '/(' . preg_quote($modelName, '/') . '::)(paginate|get|all|first)\s*\(/',
            "{$modelName}::with([{$eager}])->$2(",
            $content,
            -1,
            $count
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

    // ── Private helpers ───────────────────────────────────────────────────────

    private function eagerLoadString(array $relationships): string
    {
        return collect($relationships)
            ->filter(fn($r) => in_array($r['type'], ['hasOne', 'hasMany', 'belongsToMany', 'hasManyThrough']))
            ->map(fn($r) => "'{$r['method']}'")
            ->implode(', ');
    }

    private function inlineRules(array $columns, array $relationships, string $mode): string
    {
        $skip       = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];
        $fkMethods  = collect($relationships)->where('type', 'belongsTo')->pluck('foreign_key')->toArray();
        $lines      = [];
        $required   = $mode === 'store' ? "'required'" : "'sometimes'";

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;

            $nullable = ($col['nullable'] ?? false);
            $type     = strtolower($col['type_name'] ?? 'string');
            $rules    = [];

            $rules[] = $nullable ? "'nullable'" : $required;

            if (str_contains($name, 'email'))                              $rules[] = "'email'";
            elseif ($name === 'password' || str_ends_with($name, '_password')) { $rules = ["'required'", "'string'", "'min:8'"]; }
            elseif (str_ends_with($name, '_id')) {
                $guessedTable = Str::plural(Str::beforeLast($name, '_id'));
                $rules[]      = "'integer'";
                $rules[]      = "'exists:{$guessedTable},id'";
            } elseif (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint'])) {
                $rules[] = "'integer'";
            } elseif (in_array($type, ['decimal', 'float', 'double', 'numeric', 'real'])) {
                $rules[] = "'numeric'";
            } elseif (in_array($type, ['boolean', 'bool'])) {
                $rules[] = "'boolean'";
            } elseif (in_array($type, ['date', 'datetime', 'timestamp'])) {
                $rules[] = "'date'";
            } elseif (in_array($type, ['json', 'jsonb'])) {
                $rules[] = "'array'";
            } else {
                $rules[] = "'string'";
                if ($type === 'varchar' || $type === 'character varying') $rules[] = "'max:255'";
            }

            $ruleList = implode(', ', $rules);
            $lines[]  = "            '{$name}' => [{$ruleList}],";
        }

        return implode("\n", $lines);
    }

    private function fillableString(array $columns): string
    {
        $skip = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        return collect($columns)
            ->pluck('name')
            ->filter(fn($n) => !in_array($n, $skip))
            ->map(fn($n) => "'{$n}'")
            ->implode(', ');
    }

    private function relatedLoadForCreate(array $relationships): string
    {
        $btm = collect($relationships)->where('type', 'belongsToMany');
        if ($btm->isEmpty()) return '';

        $lines = ["\n"];
        foreach ($btm as $rel) {
            $relatedClass = $rel['related'];
            $var          = Str::camel(Str::plural($relatedClass));
            $lines[]      = "        \${$var} = \\App\\Models\\{$relatedClass}::all();";
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function compactRelated(array $relationships): string
    {
        $btm = collect($relationships)->where('type', 'belongsToMany');
        if ($btm->isEmpty()) return '';

        $vars = $btm->map(fn($r) => "'" . Str::camel(Str::plural($r['related'])) . "'")->implode(', ');
        return ", compact({$vars})";
    }

    private function compactRelatedRaw(array $relationships): string
    {
        $btm = collect($relationships)->where('type', 'belongsToMany');
        if ($btm->isEmpty()) return '';

        return ', ' . $btm->map(fn($r) => "'" . Str::camel(Str::plural($r['related'])) . "'")->implode(', ');
    }

    private function extraMethods(string $modelName, array $relationships): string
    {
        $variable = Str::camel($modelName);
        $methods  = '';

        foreach ($relationships as $rel) {
            if ($rel['type'] !== 'belongsToMany') continue;

            $relatedPlural = $rel['method'];
            $relatedModel  = $rel['related'];

            $methods .= <<<PHP

    // capyrel: sync {$relatedModel} for this {$modelName} (belongsToMany)
    public function sync{$relatedModel}(Request \$request, {$modelName} \${$variable})
    {
        \${$variable}->{$relatedPlural}()->sync(\$request->input('{$relatedPlural}_ids', []));

        return back()->with('success', '{$relatedModel} updated.');
    }

PHP;
        }

        return $methods;
    }
}
