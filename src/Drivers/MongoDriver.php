<?php

namespace Julio\Capyrel\Drivers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MongoDriver implements SchemaDriverInterface
{
    private array $tableCache = [];

    public function __construct(private string $connection) {}

    public function getTables(): array
    {
        if ($this->tableCache) return $this->tableCache;

        try {
            $db    = DB::connection($this->connection)->getMongoDB();
            $names = collect($db->listCollections())->map(fn($c) => $c->getName())->toArray();
        } catch (\Throwable) {
            // Fallback: list via command
            $names = collect(DB::connection($this->connection)->listCollections() ?? [])
                ->map(fn($c) => is_array($c) ? $c['name'] : (string) $c)
                ->toArray();
        }

        // Filter out system collections
        $this->tableCache = array_values(array_filter($names, fn($n) => !str_starts_with($n, 'system.')));

        return $this->tableCache;
    }

    public function getColumns(string $table): array
    {
        // 1. Try sampling live documents first
        try {
            $docs = DB::connection($this->connection)
                ->collection($table)
                ->limit(10)
                ->get()
                ->toArray();

            if (!empty($docs)) {
                $fields = [];
                foreach ($docs as $doc) {
                    foreach (array_keys((array) $doc) as $key) {
                        $fields[$key] = true;
                    }
                }
                return collect(array_keys($fields))->map(fn($name) => [
                    'name'      => $name,
                    'type_name' => 'mixed',
                    'nullable'  => true,
                    'default'   => null,
                ])->toArray();
            }
        } catch (\Throwable) {}

        // 2. Fallback: parse migration files for column definitions
        return $this->columnsFromMigrations($table);
    }

    /**
     * Parse database/migrations/ files to extract column names for a given table.
     * This handles empty MongoDB collections and apps without FK constraints.
     */
    private function columnsFromMigrations(string $table): array
    {
        $migDir = database_path('migrations');
        if (!is_dir($migDir)) return [];

        $columns = [];

        foreach (glob("{$migDir}/*.php") as $file) {
            $content = file_get_contents($file);

            // Only process migrations that mention this table
            if (!str_contains($content, "'{$table}'") && !str_contains($content, "\"{$table}\"")) {
                continue;
            }

            // Match: $table->type('column_name')  or  $table->type('column_name', ...)
            preg_match_all('/\$table->(\w+)\s*\(\s*[\'"](\w+)[\'"]/', $content, $m);

            foreach ($m[2] as $colName) {
                $columns[$colName] = [
                    'name'      => $colName,
                    'type_name' => 'mixed',
                    'nullable'  => false,
                    'default'   => null,
                ];
            }

            // morphs('name') expands to name_type + name_id
            preg_match_all('/->morphs\s*\(\s*[\'"](\w+)[\'"]/', $content, $morphs);
            foreach ($morphs[1] as $morphName) {
                $columns["{$morphName}_type"] = ['name' => "{$morphName}_type", 'type_name' => 'string', 'nullable' => false, 'default' => null];
                $columns["{$morphName}_id"]   = ['name' => "{$morphName}_id",   'type_name' => 'bigint', 'nullable' => false, 'default' => null];
            }
        }

        return array_values($columns);
    }

    public function getForeignKeys(string $table): array
    {
        // MongoDB has no FK constraints — infer from _id suffix naming convention
        $columns    = $this->getColumns($table);
        $allTables  = $this->getTables();
        $fks        = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (!Str::endsWith($name, '_id') || in_array($name, ['_id', 'id'])) continue;

            $guessed = Str::plural(Str::beforeLast($name, '_id'));
            if (!in_array($guessed, $allTables)) continue;

            $fks[] = [
                'name'            => "{$table}_{$name}_inferred",
                'columns'         => [$name],
                'foreign_table'   => $guessed,
                'foreign_columns' => ['_id'],
                'on_update'       => 'no action',
                'on_delete'       => 'no action',
                'inferred'        => true,  // flag: no real constraint
            ];
        }

        return $fks;
    }

    public function getIndexes(string $table): array
    {
        try {
            $collection = DB::connection($this->connection)
                ->getMongoDB()
                ->selectCollection($table);

            $indexes = [];
            foreach ($collection->listIndexes() as $idx) {
                $keys = array_keys((array) $idx->getKey());
                $indexes[] = [
                    'name'    => $idx->getName(),
                    'columns' => $keys,
                    'unique'  => (bool) ($idx->isUnique() ?? false),
                    'primary' => $idx->getName() === '_id_',
                    'type'    => ($idx->isUnique() ?? false) ? 'unique' : 'index',
                ];
            }

            return $indexes;
        } catch (\Throwable) {
            return [];
        }
    }

    public function getDriverName(): string
    {
        return 'mongodb';
    }
}
