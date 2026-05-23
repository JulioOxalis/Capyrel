<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

/**
 * Generates the Repository pattern:
 *   App\Repositories\Contracts\{Model}RepositoryInterface
 *   App\Repositories\{Model}Repository (Eloquent implementation)
 *
 * The interface defines the contract; the implementation can be swapped
 * for testing (ArrayRepository) or different backends without changing
 * any business logic.
 */
class RepositoryGenerator
{
    public function generateInterface(string $modelName, array $columns, array $relationships): string
    {
        $variable  = Str::camel($modelName);
        $variables = Str::camel(Str::plural($modelName));
        $hasSoft   = in_array('deleted_at', array_column($columns, 'name'));
        $softMethods = $hasSoft ? $this->softInterfaceMethods($modelName, $variable) : '';

        return <<<PHP
<?php

namespace App\Repositories\Contracts;

use App\Models\\{$modelName};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface {$modelName}RepositoryInterface
{
    /** Return paginated, filtered, sorted list. */
    public function paginate(array \$filters = [], int \$perPage = 15): LengthAwarePaginator;

    /** Return all records (use sparingly on large tables). */
    public function all(array \$filters = []): Collection;

    /** Find by primary key — throws ModelNotFoundException if missing. */
    public function findOrFail(int|string \$id): {$modelName};

    /** Find by a specific column value. */
    public function findBy(string \$column, mixed \$value): ?{$modelName};

    /** Create and persist a new record. */
    public function create(array \$data): {$modelName};

    /** Update an existing record. */
    public function update({$modelName} \${$variable}, array \$data): {$modelName};

    /** Delete (or soft-delete) a record. */
    public function delete({$modelName} \${$variable}): bool;

    /** Check existence without loading the model. */
    public function exists(int|string \$id): bool;

    /** Count matching records. */
    public function count(array \$filters = []): int;
{$softMethods}}
PHP;
    }

    public function generateImplementation(string $modelName, array $columns, array $relationships): string
    {
        $variable  = Str::camel($modelName);
        $variables = Str::camel(Str::plural($modelName));
        $hasSoft   = in_array('deleted_at', array_column($columns, 'name'));

        $searchCols  = $this->searchableColumns($columns);
        $searchBlock = $searchCols
            ? $this->buildSearchBlock($searchCols)
            : '        // No searchable string columns detected';

        $sortCols     = $this->sortableColumns($columns);
        $sortList     = "'" . implode("', '", $sortCols) . "'";
        $withRelations = collect($relationships)
            ->filter(fn($r) => !empty($r['method']))
            ->map(fn($r) => "'{$r['method']}'")
            ->implode(', ');

        $softMethods = $hasSoft ? $this->softImplementationMethods($modelName, $variable) : '';

        return <<<PHP
<?php

namespace App\Repositories;

use App\Models\\{$modelName};
use App\Repositories\Contracts\\{$modelName}RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class {$modelName}Repository implements {$modelName}RepositoryInterface
{
    private const SORTABLE = [{$sortList}];
    private const WITH     = [{$withRelations}];

    public function paginate(array \$filters = [], int \$perPage = 15): LengthAwarePaginator
    {
        \$query = {$modelName}::with(self::WITH)->withCount(array_filter(self::WITH));
{$searchBlock}
        \$sort = in_array(\$filters['sort'] ?? 'created_at', self::SORTABLE)
            ? \$filters['sort']
            : 'created_at';
        \$dir  = (\$filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return \$query->orderBy(\$sort, \$dir)->paginate(\$perPage);
    }

    public function all(array \$filters = []): Collection
    {
        return {$modelName}::with(self::WITH)->get();
    }

    public function findOrFail(int|string \$id): {$modelName}
    {
        return {$modelName}::with(self::WITH)->findOrFail(\$id);
    }

    public function findBy(string \$column, mixed \$value): ?{$modelName}
    {
        return {$modelName}::where(\$column, \$value)->first();
    }

    public function create(array \$data): {$modelName}
    {
        return {$modelName}::create(\$data);
    }

    public function update({$modelName} \${$variable}, array \$data): {$modelName}
    {
        \${$variable}->update(\$data);
        return \${$variable}->fresh();
    }

    public function delete({$modelName} \${$variable}): bool
    {
        return (bool) \${$variable}->delete();
    }

    public function exists(int|string \$id): bool
    {
        return {$modelName}::where('id', \$id)->exists();
    }

    public function count(array \$filters = []): int
    {
        return {$modelName}::count();
    }
{$softMethods}}
PHP;
    }

    // ── Soft-delete methods ───────────────────────────────────────────────────

    private function softInterfaceMethods(string $modelName, string $variable): string
    {
        return <<<PHP

    public function restore(int|string \$id): {$modelName};
    public function forceDelete({$modelName} \${$variable}): bool;
    public function trashed(): \Illuminate\Database\Eloquent\Collection;

PHP;
    }

    private function softImplementationMethods(string $modelName, string $variable): string
    {
        return <<<PHP

    public function restore(int|string \$id): {$modelName}
    {
        \${$variable} = {$modelName}::onlyTrashed()->findOrFail(\$id);
        \${$variable}->restore();
        return \${$variable};
    }

    public function forceDelete({$modelName} \${$variable}): bool
    {
        return (bool) \${$variable}->forceDelete();
    }

    public function trashed(): \Illuminate\Database\Eloquent\Collection
    {
        return {$modelName}::onlyTrashed()->get();
    }

PHP;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function searchableColumns(array $columns): array
    {
        $skip   = ['id', 'password', 'remember_token', 'created_at', 'updated_at', 'deleted_at'];
        $result = [];
        foreach ($columns as $col) {
            $t = strtolower($col['type_name'] ?? '');
            if (in_array($col['name'], $skip)) continue;
            if (str_ends_with($col['name'], '_id')) continue;
            if (in_array($t, ['varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext'])) {
                $result[] = $col['name'];
                if (count($result) >= 3) break;
            }
        }
        return $result;
    }

    private function buildSearchBlock(array $searchCols): string
    {
        $first = array_shift($searchCols);
        $orClauses = '';
        foreach ($searchCols as $col) {
            $orClauses .= "\n                  ->orWhere('{$col}', 'like', \"%{\$term}%\")";
        }
        return <<<PHP

        if (!empty(\$filters['search'])) {
            \$term = str_replace(['%','_'], ['\\\\%','\\\\_'], \$filters['search']);
            \$query->where(fn(\$q) => \$q->where('{$first}', 'like', "%{\$term}%"){$orClauses});
        }

PHP;
    }

    private function sortableColumns(array $columns): array
    {
        $skip   = ['password', 'remember_token', 'two_factor_secret'];
        $result = ['created_at'];
        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $skip) || $name === 'id') continue;
            if (str_ends_with($name, '_at')) {
                $result[] = $name;
                continue;
            }
            $type = strtolower($col['type_name'] ?? '');
            if (in_array($type, ['varchar', 'char', 'int', 'integer', 'decimal', 'float', 'tinyint', 'boolean'])) {
                $result[] = $name;
            }
        }
        return array_unique($result);
    }
}
