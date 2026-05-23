<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\UploadColumnDetector;

/**
 * Generates typed readonly DTOs for store and update operations.
 *
 * Benefits over raw arrays:
 *  - Type safety enforced at construction time
 *  - IDE autocomplete on all properties
 *  - Immutable — no accidental mutation in service layer
 *  - Named constructor fromRequest() keeps controllers thin
 */
class DtoGenerator
{
    private array $skip = [
        'id', '_id', 'created_at', 'updated_at', 'deleted_at',
        'remember_token', 'email_verified_at', 'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public function generateStore(string $modelName, array $columns, array $relationships): string
    {
        $className  = "Create{$modelName}DTO";
        $properties = $this->buildProperties($columns, $relationships, 'store');
        $fromReq    = $this->buildFromRequest($columns, $relationships, 'store', $modelName);
        $toArray    = $this->buildToArray($columns, $relationships, 'store');

        return $this->buildClass($className, $properties, $fromReq, $toArray, $modelName);
    }

    public function generateUpdate(string $modelName, array $columns, array $relationships): string
    {
        $className  = "Update{$modelName}DTO";
        $properties = $this->buildProperties($columns, $relationships, 'update');
        $fromReq    = $this->buildFromRequest($columns, $relationships, 'update', $modelName);
        $toArray    = $this->buildToArray($columns, $relationships, 'update');

        return $this->buildClass($className, $properties, $fromReq, $toArray, $modelName);
    }

    // ── Builder helpers ───────────────────────────────────────────────────────

    private function buildClass(
        string $className,
        string $properties,
        string $fromRequest,
        string $toArray,
        string $modelName
    ): string {
        $useFile = UploadColumnDetector::collectFrom([]) !== [] // placeholder
            ? "\nuse Illuminate\\Http\\UploadedFile;" : '';

        return <<<PHP
<?php

namespace App\DTOs;

use Illuminate\Http\Request;

/**
 * Immutable data transfer object.
 * Construct via {$className}::fromRequest(\$request)
 * or {$className}::fromArray([...]).
 */
readonly class {$className}
{
{$properties}
    private function __construct(
{$this->buildConstructorArgs($properties)}
    ) {}

    public static function fromRequest(Request \$request): static
    {
        return new static(
{$fromRequest}
        );
    }

    public static function fromArray(array \$data): static
    {
        return new static(
{$this->buildFromArray($properties)}
        );
    }

    /** Convert back to array for Eloquent create/update. */
    public function toArray(): array
    {
        return array_filter([
{$toArray}
        ], fn(\$v) => \$v !== null);
    }
}
PHP;
    }

    private function buildProperties(array $columns, array $relationships, string $mode): string
    {
        $lines = [];

        foreach ($columns as $col) {
            $name     = $col['name'];
            $nullable = (bool) ($col['nullable'] ?? false);
            $type     = $this->phpType($col);

            if (in_array($name, $this->skip)) continue;
            if (UploadColumnDetector::isUploadColumn($name)) {
                $lines[] = "    public readonly ?\\Illuminate\\Http\\UploadedFile \${$name};";
                continue;
            }

            $typeHint  = $nullable || $mode === 'update' ? "?{$type}" : $type;
            $lines[]   = "    public readonly {$typeHint} \${$name};";
        }

        // FK columns from relationships
        foreach ($relationships as $rel) {
            if ($rel['type'] !== 'belongsTo') continue;
            $fk = $rel['foreign_key'] ?? (Str::snake($rel['related']) . '_id');
            if (in_array($fk, array_column($columns, 'name'))) continue; // already handled above
            $typeHint = $mode === 'update' ? '?int' : 'int';
            $lines[]  = "    public readonly {$typeHint} \${$fk};";
        }

        return implode("\n", $lines);
    }

    private function buildConstructorArgs(string $properties): string
    {
        preg_match_all('/public readonly (\S+) \$(\w+);/', $properties, $m);
        $lines = [];
        foreach (array_combine($m[1], $m[2]) as $type => $name) {
            $lines[] = "        {$type} \${$name} = null,";
        }
        return implode("\n", $lines);
    }

    private function buildFromRequest(array $columns, array $relationships, string $mode, string $modelName): string
    {
        $lines = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $this->skip)) continue;

            if (UploadColumnDetector::isUploadColumn($name)) {
                $lines[] = "            {$name}: \$request->file('{$name}'),";
                continue;
            }

            $cast = $this->requestCast($col);
            $lines[] = "            {$name}: \$request->{$cast}('{$name}'),";
        }

        return implode("\n", $lines);
    }

    private function buildFromArray(string $properties): string
    {
        preg_match_all('/public readonly \S+ \$(\w+);/', $properties, $m);
        $lines = [];
        foreach ($m[1] as $name) {
            $lines[] = "            {$name}: \$data['{$name}'] ?? null,";
        }
        return implode("\n", $lines);
    }

    private function buildToArray(array $columns, array $relationships, string $mode): string
    {
        $lines = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $this->skip)) continue;
            if (UploadColumnDetector::isUploadColumn($name)) continue; // handled separately in service
            $lines[] = "            '{$name}' => \$this->{$name},";
        }

        return implode("\n", $lines);
    }

    // ── Type helpers ──────────────────────────────────────────────────────────

    private function phpType(array $col): string
    {
        $type = strtolower($col['type_name'] ?? 'string');

        return match(true) {
            in_array($type, ['int','integer','bigint','smallint','tinyint','mediumint']) => 'int',
            in_array($type, ['decimal','float','double','numeric','real'])               => 'float',
            in_array($type, ['boolean','bool'])                                          => 'bool',
            in_array($type, ['date','datetime','timestamp','datetimetz'])                => '\\Carbon\\Carbon',
            in_array($type, ['json','jsonb'])                                            => 'array',
            default                                                                      => 'string',
        };
    }

    private function requestCast(array $col): string
    {
        $type = strtolower($col['type_name'] ?? 'string');
        $name = $col['name'];

        if (str_contains($name, 'email')) return 'string';
        if (in_array($type, ['int','integer','bigint','smallint','tinyint','mediumint'])) return 'integer';
        if (in_array($type, ['decimal','float','double','numeric','real'])) return 'float';
        if (in_array($type, ['boolean','bool'])) return 'boolean';
        if (in_array($type, ['date','datetime','timestamp'])) return 'date';
        return 'string';
    }
}
