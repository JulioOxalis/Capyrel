<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

class ApiResourceGenerator
{
    private array $skipColumns = ['_id', 'remember_token', 'password', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function generate(string $modelName, array $columns, array $relationships): string
    {
        $columnLines = $this->buildColumnLines($columns);
        $relLines    = $this->buildRelationshipLines($relationships);
        $imports     = $this->buildImports($relationships);

        return <<<PHP
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
{$imports}
class {$modelName}Resource extends JsonResource
{
    public function toArray(Request \$request): array
    {
        return [
{$columnLines}
{$relLines}        ];
    }
}
PHP;
    }

    private function buildColumnLines(array $columns): string
    {
        $lines = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $this->skipColumns)) continue;
            if (str_ends_with($name, '_id')) continue; // FKs shown via relationship, not raw ID

            $lines[] = "            '{$name}' => \$this->{$name},";
        }

        return implode("\n", $lines);
    }

    private function buildRelationshipLines(array $relationships): string
    {
        if (empty($relationships)) return '';

        $lines   = [];
        $lines[] = '';
        $lines[] = '            // capyrel: relationships — only included when eager-loaded (no N+1 possible)';

        foreach ($relationships as $rel) {
            if (empty($rel['related']) || $rel['type'] === 'morphTo') continue;

            $method  = $rel['method'];
            $related = $rel['related'];

            if (in_array($rel['type'], ['hasMany', 'belongsToMany', 'hasManyThrough'])) {
                $lines[] = "            '{$method}' => {$related}Resource::collection(\$this->whenLoaded('{$method}')),";
            } else {
                $lines[] = "            '{$method}' => new {$related}Resource(\$this->whenLoaded('{$method}')),";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function buildImports(array $relationships): string
    {
        $models = collect($relationships)
            ->filter(fn($r) => !empty($r['related']) && $r['type'] !== 'morphTo')
            ->pluck('related')
            ->unique()
            ->sort()
            ->values();

        if ($models->isEmpty()) return '';

        return $models->map(fn($m) => "use App\\Http\\Resources\\{$m}Resource;")->implode("\n") . "\n";
    }
}
