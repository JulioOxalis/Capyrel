<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

class SeederGenerator
{
    /**
     * Generate a seeder for a single model.
     */
    public function generate(string $modelName, int $count = 10): string
    {
        $plural = Str::plural(Str::headline($modelName));

        return <<<PHP
<?php

namespace Database\Seeders;

use App\Models\\{$modelName};
use Illuminate\Database\Seeder;

class {$modelName}Seeder extends Seeder
{
    public function run(): void
    {
        {$modelName}::factory()->count({$count})->create();
    }
}
PHP;
    }

    /**
     * Generate the main DatabaseSeeder in topological order (parents before children).
     */
    public function generateDatabaseSeeder(array $orderedModels): string
    {
        $calls = collect($orderedModels)
            ->map(fn($m) => "        \$this->call({$m}Seeder::class);")
            ->implode("\n");

        return <<<PHP
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed order is determined by capyrel based on FK dependencies.
     * Parents are seeded before children to satisfy foreign key constraints.
     */
    public function run(): void
    {
{$calls}
    }
}
PHP;
    }

    /**
     * Topological sort — parents come before children.
     * e.g. User must be seeded before Post (posts.user_id needs a valid user).
     */
    public function sortByDependency(array $relationships): array
    {
        $graph      = [];
        $allModels  = array_keys($relationships);

        foreach ($allModels as $model) {
            $graph[$model] = [];
        }

        foreach ($relationships as $model => $rels) {
            foreach ($rels as $rel) {
                if ($rel['type'] !== 'belongsTo' || empty($rel['related'])) continue;
                $parent = $rel['related'];
                if (!isset($graph[$parent])) $graph[$parent] = [];
                // model depends on parent
                if (!in_array($parent, $graph[$model])) {
                    $graph[$model][] = $parent;
                }
            }
        }

        return $this->topologicalSort($graph);
    }

    private function topologicalSort(array $graph): array
    {
        $sorted  = [];
        $visited = [];

        $visit = function (string $node) use (&$visit, &$sorted, &$visited, $graph): void {
            if (in_array($node, $visited)) return;
            $visited[] = $node;

            foreach ($graph[$node] ?? [] as $dep) {
                $visit($dep);
            }

            $sorted[] = $node;
        };

        foreach (array_keys($graph) as $node) {
            $visit($node);
        }

        // Reverse: dependencies come first
        return array_values(array_unique(array_reverse($sorted)));
    }
}
