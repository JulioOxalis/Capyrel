<?php

namespace Julio\Capyrel\Writers;

use Illuminate\Support\Str;

class ControllerWriter
{
    /**
     * Generate a full resource controller with relationship-aware methods.
     */
    public function generate(string $modelName, array $relationships): string
    {
        $variable    = Str::camel($modelName);
        $variables   = Str::camel(Str::plural($modelName));
        $viewPrefix  = Str::kebab(Str::plural($modelName));
        $eagerLoad   = $this->eagerLoadString($relationships);
        $extraMethods = $this->extraMethods($modelName, $relationships);

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
    {
        return view('{$viewPrefix}.create');
    }

    public function store(Request \$request)
    {
        \${$variable} = {$modelName}::create(\$request->validated());

        return redirect()->route('{$viewPrefix}.show', \${$variable});
    }

    // capyrel: loads all detected relations for the detail view
    public function show({$modelName} \${$variable})
    {
        \${$variable}->load([{$eagerLoad}]);

        return view('{$viewPrefix}.show', compact('{$variable}'));
    }

    public function edit({$modelName} \${$variable})
    {
        return view('{$viewPrefix}.edit', compact('{$variable}'));
    }

    public function update(Request \$request, {$modelName} \${$variable})
    {
        \${$variable}->update(\$request->validated());

        return redirect()->route('{$viewPrefix}.show', \${$variable});
    }

    public function destroy({$modelName} \${$variable})
    {
        \${$variable}->delete();

        return redirect()->route('{$viewPrefix}.index');
    }
{$extraMethods}}
PHP;
    }

    /**
     * Inject eager loading into an existing controller's index/show methods.
     * Returns true if any change was made.
     */
    public function inject(string $path, string $modelName, array $relationships): bool
    {
        if (!file_exists($path)) return false;

        $content   = file_get_contents($path);
        $eager     = $this->eagerLoadString($relationships);

        if (empty($eager) || str_contains($content, 'capyrel:')) {
            return false;
        }

        // Add ->with([...]) to bare Model:: calls in index/show
        $replaced = preg_replace(
            '/(' . preg_quote($modelName, '/') . '::)(paginate|get|all|first)\s*\(/',
            "{$modelName}::with([{$eager}])->$2(",
            $content,
            -1,
            $count
        );

        if ($count === 0 || $replaced === $content) return false;

        // Add marker comment so we don't inject twice
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

    private function eagerLoadString(array $relationships): string
    {
        return collect($relationships)
            ->filter(fn($r) => in_array($r['type'], ['hasOne', 'hasMany', 'belongsToMany', 'hasManyThrough']))
            ->map(fn($r) => "'{$r['method']}'")
            ->implode(', ');
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
