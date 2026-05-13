<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

class RelationshipTestGenerator
{
    private array $relationClasses = [
        'hasOne'         => 'HasOne',
        'hasMany'        => 'HasMany',
        'belongsTo'      => 'BelongsTo',
        'belongsToMany'  => 'BelongsToMany',
        'hasManyThrough' => 'HasManyThrough',
        'hasOneThrough'  => 'HasOneThrough',
        'morphTo'        => 'MorphTo',
        'morphMany'      => 'MorphMany',
    ];

    public function generate(string $modelName, array $relationships): string
    {
        $imports       = $this->buildImports($modelName, $relationships);
        $typeTests     = $this->buildTypeTests($modelName, $relationships);
        $factoryTests  = $this->buildFactoryTests($modelName, $relationships);

        return <<<PHP
<?php

use App\Models\\{$modelName};
{$imports}

describe('{$modelName} relationships', function () {
{$typeTests}
{$factoryTests}});
PHP;
    }

    private function buildImports(string $modelName, array $relationships): string
    {
        $classes = collect($relationships)
            ->map(fn($r) => $this->relationClasses[$r['type']] ?? null)
            ->filter()
            ->unique()
            ->sort()
            ->map(fn($c) => "use Illuminate\\Database\\Eloquent\\Relations\\{$c};")
            ->values();

        $models = collect($relationships)
            ->filter(fn($r) => !empty($r['related']))
            ->pluck('related')
            ->unique()
            ->filter(fn($m) => $m !== $modelName)
            ->sort()
            ->map(fn($m) => "use App\\Models\\{$m};")
            ->values();

        return $classes->merge($models)->implode("\n");
    }

    private function buildTypeTests(string $modelName, array $relationships): string
    {
        $lines = ["    // ── Relationship type assertions (no DB needed) ──────────────────────"];

        foreach ($relationships as $rel) {
            $class  = $this->relationClasses[$rel['type']] ?? null;
            if (!$class) continue;

            $method = $rel['method'];
            $lines[] = <<<PHP

    it('{$modelName}::{$method}() returns a {$class}', function () {
        expect((new {$modelName})->{$method}())->toBeInstanceOf({$class}::class);
    });
PHP;
        }

        return implode("\n", $lines);
    }

    private function buildFactoryTests(string $modelName, array $relationships): string
    {
        $lines      = [];
        $hasFactory = $this->factoryExists($modelName);

        if (!$hasFactory) return '';

        $lines[] = '';
        $lines[] = "    // ── Integration tests (require DB + factories) ────────────────────────";

        foreach ($relationships as $rel) {
            if (empty($rel['related'])) continue;

            $method        = $rel['method'];
            $related       = $rel['related'];
            $relatedFactory = $this->factoryExists($related);
            $singular      = Str::camel(Str::singular($related));

            if ($rel['type'] === 'hasMany' && $relatedFactory) {
                $lines[] = <<<PHP

    it('{$modelName} hasMany {$related} relationship works', function () {
        \$model  = {$modelName}::factory()->create();
        \$child  = {$related}::factory()->create(['{$this->guessFk($modelName)}' => \$model->id]);
        expect(\$model->fresh()->{$method})->toHaveCount(1);
    })->skip('Requires factory and DB — remove skip() to enable');
PHP;
            }

            if ($rel['type'] === 'belongsToMany' && $relatedFactory) {
                $lines[] = <<<PHP

    it('{$modelName} can attach {$related}', function () {
        \$model   = {$modelName}::factory()->create();
        \${$singular} = {$related}::factory()->create();
        \$model->{$method}()->attach(\${$singular}->id);
        expect(\$model->fresh()->{$method})->toHaveCount(1);
    })->skip('Requires factory and DB — remove skip() to enable');
PHP;
            }
        }

        return implode("\n", $lines);
    }

    private function factoryExists(string $modelName): bool
    {
        return file_exists(database_path("factories/{$modelName}Factory.php"));
    }

    private function guessFk(string $modelName): string
    {
        return Str::snake($modelName) . '_id';
    }
}
