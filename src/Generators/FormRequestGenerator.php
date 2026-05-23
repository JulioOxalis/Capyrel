<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\UploadColumnDetector;

class FormRequestGenerator
{
    /** Column type_name → base Laravel validation rules */
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

    /** Columns to exclude from validation rules entirely. */
    private array $skipColumns = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'remember_token', 'email_verified_at',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function generateStore(string $modelName, string $table, array $columns, array $foreignKeys, array $indexes): string
    {
        $rules = $this->buildRules($modelName, $table, $columns, $foreignKeys, $indexes, required: true);
        return $this->buildClass($modelName, 'Store', $table, $rules);
    }

    public function generateUpdate(string $modelName, string $table, array $columns, array $foreignKeys, array $indexes): string
    {
        $rules = $this->buildRules($modelName, $table, $columns, $foreignKeys, $indexes, required: false);
        return $this->buildClass($modelName, 'Update', $table, $rules);
    }

    // -------------------------------------------------------------------------
    // Rule building
    // -------------------------------------------------------------------------

    private function buildRules(
        string $modelName,
        string $table,
        array  $columns,
        array  $fks,
        array  $indexes,
        bool   $required
    ): array {
        $fkCols    = collect($fks)->flatMap(fn($fk) => $fk['columns'])->toArray();
        $unique    = $this->uniqueColumns($indexes);
        $variable  = Str::camel($modelName); // e.g. Post → post
        $rules     = [];

        foreach ($columns as $col) {
            $name     = $col['name'];
            $typeName = strtolower($col['type_name'] ?? 'string');
            $fullType = $col['type'] ?? '';
            $nullable = $col['nullable'] ?? false;

            if (in_array($name, $this->skipColumns)) continue;

            // Upload/file columns get file-specific rules, not generic type rules
            if (UploadColumnDetector::isUploadColumn($name)) {
                $rules[$name] = $this->buildUploadRules($name, $required);
                continue;
            }

            $colRules = [];

            // Required / nullable / sometimes
            if ($required) {
                $colRules[] = $nullable ? "'nullable'" : "'required'";
            } else {
                $colRules[] = "'sometimes'";
                if ($nullable) $colRules[] = "'nullable'";
            }

            // FK column → exists rule (skip raw _id in resource output but validate here)
            if (in_array($name, $fkCols) || str_ends_with($name, '_id')) {
                $fk           = collect($fks)->firstWhere('columns.0', $name);
                $relatedTable = $fk['foreign_table'] ?? Str::plural(Str::beforeLast($name, '_id'));
                $colRules[]   = "'integer'";
                $colRules[]   = "'exists:{$relatedTable},id'";
                $rules[$name] = $colRules;
                continue;
            }

            // ENUM → 'in:val1,val2,...'
            if ($typeName === 'enum') {
                $values = $this->parseEnumValues($fullType);
                if (!empty($values)) {
                    $colRules[] = "'in:" . implode(',', $values) . "'";
                    $rules[$name] = $colRules;
                    continue;
                }
            }

            // Password columns get special treatment
            if ($name === 'password' || str_ends_with($name, '_password')) {
                $colRules[] = "'string'";
                $colRules[] = "'min:8'";
                if ($required) {
                    $colRules[] = "'confirmed'";
                } else {
                    // On update, also validate the current password if provided
                    $colRules[] = "'confirmed'";
                }
                $rules[$name] = $colRules;
                continue;
            }

            // Type-based rules
            foreach ($this->typeRules[$typeName] ?? ['string'] as $r) {
                $colRules[] = "'{$r}'";
            }

            // Name-based semantic rules
            $colRules = array_merge($colRules, $this->nameRules($name, $table, $unique, $required, $variable));

            $rules[$name] = $colRules;
        }

        return $rules;
    }

    private function buildUploadRules(string $name, bool $required): array
    {
        // UploadColumnDetector::validationRules() returns a comma-separated string of quoted rules
        // e.g. "'nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'"
        // For store, replace 'nullable' with 'required' for non-nullable upload fields
        $raw = UploadColumnDetector::validationRules($name);

        if ($required) {
            // Keep nullable since file uploads are often optional even on store
            return array_map('trim', explode(',', $raw));
        }

        $parts   = array_map('trim', explode(',', $raw));
        // Ensure 'sometimes' is prepended for update requests
        array_unshift($parts, "'sometimes'");
        return $parts;
    }

    private function nameRules(string $name, string $table, array $unique, bool $required, string $variable): array
    {
        $extra = [];

        if (str_contains($name, 'email')) {
            $extra[] = "'email'";
        }

        if (str_contains($name, 'url') || $name === 'website' || $name === 'link') {
            $extra[] = "'url'";
        }

        if (in_array($name, $unique)) {
            if ($required) {
                // Store: plain unique rule
                $extra[] = "Rule::unique('{$table}', '{$name}')";
            } else {
                // Update: ignore the current record
                $extra[] = "Rule::unique('{$table}', '{$name}')->ignore(\$this->route('{$variable}')?->id)";
            }
        }

        return $extra;
    }

    // -------------------------------------------------------------------------
    // Class builder
    // -------------------------------------------------------------------------

    private function buildClass(string $modelName, string $type, string $table, array $rules): string
    {
        $variable = Str::camel($modelName);          // post, blogPost, etc.
        $hasRule  = collect($rules)->flatten()->contains(fn($r) => str_contains($r, 'Rule::'));

        // Collect imports
        $imports   = [];
        $imports[] = "use Illuminate\\Foundation\\Http\\FormRequest;";
        $imports[] = "use App\\Models\\{$modelName};";
        if ($hasRule) {
            $imports[] = "use Illuminate\\Validation\\Rule;";
        }
        sort($imports);
        $importsCode = implode("\n", $imports);

        // authorize() body
        if ($type === 'Store') {
            $authorizeBody = "return \$this->user()->can('create', {$modelName}::class);";
        } else {
            $authorizeBody = "return \$this->user()->can('update', \$this->route('{$variable}'));";
        }

        // rules() body
        $rulesCode = collect($rules)->map(function ($colRules, $col) {
            $ruleList = implode(', ', $colRules);
            return "            '{$col}' => [{$ruleList}],";
        })->implode("\n");

        // messages() stub — generate entries for required fields
        $messagesCode = $this->buildMessagesMethod($rules, $type);

        return <<<PHP
<?php

namespace App\Http\Requests;

{$importsCode}

class {$type}{$modelName}Request extends FormRequest
{
    public function authorize(): bool
    {
        {$authorizeBody}
    }

    public function rules(): array
    {
        return [
{$rulesCode}
        ];
    }

{$messagesCode}
}
PHP;
    }

    // -------------------------------------------------------------------------
    // messages() method stub
    // -------------------------------------------------------------------------

    private function buildMessagesMethod(array $rules, string $type): string
    {
        $entries = [];

        foreach ($rules as $col => $colRules) {
            $label = Str::headline($col);   // e.g. title → Title, blog_title → Blog Title

            // required
            if (in_array("'required'", $colRules)) {
                $entries[] = "            '{$col}.required' => 'Please provide a {$label}.',";
            }

            // string max
            if (in_array("'max:255'", $colRules)) {
                $entries[] = "            '{$col}.max' => 'The {$label} may not exceed 255 characters.',";
            }

            // email
            if (in_array("'email'", $colRules)) {
                $entries[] = "            '{$col}.email' => 'Please provide a valid email address.',";
            }

            // unique
            if (collect($colRules)->contains(fn($r) => str_contains($r, 'Rule::unique'))) {
                $entries[] = "            '{$col}.unique' => 'This {$label} has already been taken.',";
            }

            // exists (FK)
            if (collect($colRules)->contains(fn($r) => str_starts_with($r, "'exists:"))) {
                $entries[] = "            '{$col}.exists' => 'The selected {$label} is invalid.',";
            }

            // file / image
            if (in_array("'image'", $colRules)) {
                $entries[] = "            '{$col}.image' => 'The {$label} must be an image file.',";
            }

            // in (enum)
            if (collect($colRules)->contains(fn($r) => str_starts_with($r, "'in:"))) {
                $entries[] = "            '{$col}.in' => 'The selected {$label} is not valid.',";
            }

            // confirmed (password)
            if (in_array("'confirmed'", $colRules)) {
                $entries[] = "            '{$col}.confirmed' => 'The {$label} confirmation does not match.',";
            }
        }

        if (empty($entries)) {
            return <<<PHP
    public function messages(): array
    {
        return [];
    }
PHP;
        }

        $body = implode("\n", $entries);

        return <<<PHP
    public function messages(): array
    {
        return [
{$body}
        ];
    }
PHP;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function uniqueColumns(array $indexes): array
    {
        return collect($indexes)
            ->filter(fn($idx) => ($idx['unique'] ?? false) && !($idx['primary'] ?? false))
            ->flatMap(fn($idx) => count($idx['columns']) === 1 ? $idx['columns'] : [])
            ->toArray();
    }

    /**
     * Parse ENUM values from MySQL type string.
     * Laravel returns e.g. "enum('draft','published','archived')" in col['type'].
     */
    private function parseEnumValues(string $fullType): array
    {
        preg_match_all("/'([^']+)'/", $fullType, $matches);
        return $matches[1] ?? [];
    }
}
