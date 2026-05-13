<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

class FormRequestGenerator
{
    /** Column type → base validation rules */
    private array $typeRules = [
        'varchar'            => ['string', 'max:255'],
        'char'               => ['string'],
        'text'               => ['string'],
        'mediumtext'         => ['string'],
        'longtext'           => ['string'],
        'tinytext'           => ['string'],
        'int'                => ['integer'],
        'integer'            => ['integer'],
        'bigint'             => ['integer'],
        'smallint'           => ['integer'],
        'mediumint'          => ['integer'],
        'tinyint'            => ['integer'],
        'boolean'            => ['boolean'],
        'bool'               => ['boolean'],
        'decimal'            => ['numeric'],
        'float'              => ['numeric'],
        'double'             => ['numeric'],
        'real'               => ['numeric'],
        'numeric'            => ['numeric'],
        'date'               => ['date'],
        'datetime'           => ['date'],
        'timestamp'          => ['date'],
        'time'               => ['date_format:H:i:s'],
        'year'               => ['integer', 'digits:4'],
        'json'               => ['array'],
        'jsonb'              => ['array'],
        'uuid'               => ['uuid'],
        'blob'               => ['string'],
        'binary'             => ['string'],
        'mixed'              => ['string'],      // MongoDB fallback
        'character varying'  => ['string', 'max:255'],
    ];

    public function generateStore(string $modelName, string $table, array $columns, array $foreignKeys, array $indexes): string
    {
        $rules = $this->buildRules($columns, $foreignKeys, $indexes, $table, required: true);
        return $this->buildClass($modelName, 'Store', $rules);
    }

    public function generateUpdate(string $modelName, string $table, array $columns, array $foreignKeys, array $indexes): string
    {
        $rules = $this->buildRules($columns, $foreignKeys, $indexes, $table, required: false);
        return $this->buildClass($modelName, 'Update', $rules);
    }

    private function buildRules(array $columns, array $fks, array $indexes, string $table, bool $required): array
    {
        $skip    = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];
        $fkCols  = collect($fks)->flatMap(fn($fk) => $fk['columns'])->toArray();
        $unique  = $this->uniqueColumns($indexes);
        $rules   = [];

        foreach ($columns as $col) {
            $name     = $col['name'];
            $typeName = strtolower($col['type_name'] ?? 'string');
            $nullable = $col['nullable'] ?? false;

            if (in_array($name, $skip)) continue;

            $colRules = [];

            // Required vs nullable
            if ($required) {
                $colRules[] = $nullable ? "'nullable'" : "'required'";
            } else {
                $colRules[] = "'sometimes'";
                if ($nullable) $colRules[] = "'nullable'";
            }

            // FK column → exists rule
            if (in_array($name, $fkCols) || str_ends_with($name, '_id')) {
                $fk           = collect($fks)->firstWhere('columns.0', $name);
                $relatedTable = $fk['foreign_table'] ?? Str::plural(Str::beforeLast($name, '_id'));
                $colRules[]   = "'integer'";
                $colRules[]   = "'exists:{$relatedTable},id'";
            } else {
                // Type-based rules
                $typeRule = $this->typeRules[$typeName] ?? ['string'];
                foreach ($typeRule as $r) {
                    $colRules[] = "'{$r}'";
                }

                // Name-based semantic rules
                $colRules = array_merge($colRules, $this->nameRules($name, $table, $unique, $required));
            }

            $rules[$name] = $colRules;
        }

        return $rules;
    }

    private function nameRules(string $name, string $table, array $unique, bool $required): array
    {
        $extra = [];

        if (str_contains($name, 'email')) {
            $extra[] = "'email'";
        }

        if (str_contains($name, 'url') || $name === 'website' || $name === 'link') {
            $extra[] = "'url'";
        }

        if ($name === 'password' || str_ends_with($name, '_password')) {
            $extra = ["'string'", "'min:8'"];
            if ($required) $extra[] = "'confirmed'";
        }

        if (in_array($name, $unique)) {
            $extra[] = "Rule::unique('{$table}', '{$name}')";
        }

        return $extra;
    }

    private function uniqueColumns(array $indexes): array
    {
        return collect($indexes)
            ->filter(fn($idx) => ($idx['unique'] ?? false) && !($idx['primary'] ?? false))
            ->flatMap(fn($idx) => count($idx['columns']) === 1 ? $idx['columns'] : [])
            ->toArray();
    }

    private function buildClass(string $modelName, string $type, array $rules): string
    {
        $hasRule = collect($rules)->flatten()->contains(fn($r) => str_contains($r, 'Rule::'));
        $import  = $hasRule ? "\nuse Illuminate\Validation\Rule;" : '';

        $rulesCode = collect($rules)->map(function ($colRules, $col) {
            $ruleList = implode(', ', $colRules);
            return "            '{$col}' => [{$ruleList}],";
        })->implode("\n");

        return <<<PHP
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;{$import}

class {$type}{$modelName}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true; // capyrel: update to match your authorization logic
    }

    public function rules(): array
    {
        return [
{$rulesCode}
        ];
    }
}
PHP;
    }
}
