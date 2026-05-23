<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\UploadColumnDetector;

class ApiResourceGenerator
{
    /**
     * Columns that must never appear in any API response.
     * Checked against exact name and against suffix patterns below.
     */
    private array $sensitiveColumns = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
        'secret_key',
    ];

    /** Any column whose name ends with one of these suffixes is also suppressed. */
    private array $sensitiveSuffixes = ['_token', '_secret'];

    public function generate(string $modelName, array $columns, array $relationships): string
    {
        $imports     = $this->buildImports($relationships);
        $columnLines = $this->buildColumnLines($columns);
        $countLines  = $this->buildCountLines($relationships);
        $relLines    = $this->buildRelationshipLines($relationships);

        $bodyParts = array_filter([$columnLines, $countLines, $relLines], fn($p) => $p !== '');
        $body      = implode("\n", $bodyParts);

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
{$body}
        ];
    }
}
PHP;
    }

    // -------------------------------------------------------------------------
    // Column lines
    // -------------------------------------------------------------------------

    private function buildColumnLines(array $columns): string
    {
        $lines = [];

        foreach ($columns as $col) {
            $name     = $col['name'];
            $typeName = strtolower($col['type_name'] ?? 'string');

            // Always include id first (handled separately, but skip if we hit it in loop)
            if ($name === 'id') {
                $lines[] = "            'id'           => \$this->id,";
                continue;
            }

            if ($this->isSensitive($name)) continue;
            if ($this->isForeignKey($name))  continue; // raw _id cols shown via relationship instead

            $lines[] = $this->buildColumnLine($name, $typeName, $col['type'] ?? '');
        }

        return implode("\n", $lines);
    }

    private function buildColumnLine(string $name, string $typeName, string $fullType): string
    {
        $key   = $this->padKey($name);
        $value = $this->columnValue($name, $typeName, $fullType);
        return "            {$key} => {$value},";
    }

    private function columnValue(string $name, string $typeName, string $fullType): string
    {
        // Datetime / timestamp → nullable carbon toISOString
        if (in_array($typeName, ['datetime', 'timestamp', 'date', 'datetimetz'])) {
            return "\$this->{$name}?->toISOString()";
        }

        // Decimal / float / double → formatted number
        if (in_array($typeName, ['decimal', 'float', 'double', 'real', 'numeric'])) {
            return "number_format((float) \$this->{$name}, 2, '.', '')";
        }

        // File / image columns → _url accessor (ModelEnhancer adds this)
        if (UploadColumnDetector::isUploadColumn($name)) {
            return "\$this->{$name}_url"; // uses model accessor if defined, else raw
        }

        return "\$this->{$name}";
    }

    /** Pad a key string to align the => operators. */
    private function padKey(string $name): string
    {
        // Use a fixed minimum width of 14 to keep alignment reasonable.
        $quoted = "'{$name}'";
        return str_pad($quoted, max(strlen($quoted), 14));
    }

    // -------------------------------------------------------------------------
    // Count lines (whenCounted)
    // -------------------------------------------------------------------------

    private function buildCountLines(array $relationships): string
    {
        $pluralTypes = ['hasMany', 'belongsToMany', 'hasManyThrough', 'morphMany', 'morphToMany'];

        $lines = collect($relationships)
            ->filter(fn($r) => in_array($r['type'], $pluralTypes) && !empty($r['method']))
            ->map(fn($r) => $r['method'])
            ->unique()
            ->sort()
            ->values();

        if ($lines->isEmpty()) return '';

        $out   = [];
        $out[] = '';
        $out[] = '            // Counts — only included when withCount() was called on the query';

        foreach ($lines as $method) {
            $key   = $this->padKey("{$method}_count");
            $out[] = "            {$key} => \$this->whenCounted('{$method}'),";
        }

        return implode("\n", $out);
    }

    // -------------------------------------------------------------------------
    // Relationship lines
    // -------------------------------------------------------------------------

    private function buildRelationshipLines(array $relationships): string
    {
        if (empty($relationships)) return '';

        $lines   = [];
        $lines[] = '';
        $lines[] = '            // Relationships — only included when eager-loaded (no N+1)';

        foreach ($relationships as $rel) {
            if (empty($rel['related']) || ($rel['type'] ?? '') === 'morphTo') continue;

            $method  = $rel['method'];
            $related = $rel['related'];
            $key     = $this->padKey($method);

            if (in_array($rel['type'], ['hasMany', 'belongsToMany', 'hasManyThrough', 'morphMany', 'morphToMany'])) {
                $lines[] = "            {$key} => {$related}Resource::collection(\$this->whenLoaded('{$method}')),";
            } else {
                $lines[] = "            {$key} => new {$related}Resource(\$this->whenLoaded('{$method}')),";
            }
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Imports
    // -------------------------------------------------------------------------

    private function buildImports(array $relationships): string
    {
        $models = collect($relationships)
            ->filter(fn($r) => !empty($r['related']) && ($r['type'] ?? '') !== 'morphTo')
            ->pluck('related')
            ->unique()
            ->sort()
            ->values();

        if ($models->isEmpty()) return '';

        return $models->map(fn($m) => "use App\\Http\\Resources\\{$m}Resource;")->implode("\n") . "\n";
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isSensitive(string $name): bool
    {
        if (in_array($name, $this->sensitiveColumns)) return true;

        foreach ($this->sensitiveSuffixes as $suffix) {
            if (str_ends_with($name, $suffix)) return true;
        }

        return false;
    }

    private function isForeignKey(string $name): bool
    {
        return $name !== 'id' && str_ends_with($name, '_id');
    }
}
