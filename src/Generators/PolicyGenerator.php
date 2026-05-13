<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

class PolicyGenerator
{
    public function generate(string $modelName, array $columns, array $relationships): string
    {
        $variable    = Str::camel($modelName);
        $ownerCheck  = $this->ownerCheck($variable, $columns);
        $viewAny     = $this->viewAnyPolicy();
        $view        = $this->viewPolicy($modelName, $variable, $ownerCheck);
        $create      = $this->createPolicy();
        $update      = $this->updatePolicy($modelName, $variable, $ownerCheck);
        $delete      = $this->deletePolicy($modelName, $variable, $ownerCheck);
        $restore     = $this->hasColumn($columns, 'deleted_at') ? $this->restorePolicy($modelName, $variable, $ownerCheck) : '';
        $forceDelete = $this->hasColumn($columns, 'deleted_at') ? $this->forceDeletePolicy($modelName, $variable) : '';
        $roleChecks  = $this->roleBasedMethods($modelName, $relationships);

        return <<<PHP
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\\{$modelName};
use Illuminate\Auth\Access\Response;

class {$modelName}Policy
{
{$viewAny}

{$view}

{$create}

{$update}

{$delete}
{$restore}
{$forceDelete}
{$roleChecks}}
PHP;
    }

    private function ownerCheck(string $variable, array $columns): string
    {
        $ownerColumns = ['user_id', 'author_id', 'owner_id', 'created_by'];
        foreach ($ownerColumns as $col) {
            if ($this->hasColumn($columns, $col)) {
                return "\${$variable}->{$col} === \$user->id";
            }
        }
        return 'true'; // no owner column — allow authenticated users
    }

    private function viewAnyPolicy(): string
    {
        return <<<PHP
    public function viewAny(User \$user): bool
    {
        return true; // any authenticated user can list
    }
PHP;
    }

    private function viewPolicy(string $model, string $variable, string $ownerCheck): string
    {
        return <<<PHP
    public function view(User \$user, {$model} \${$variable}): bool
    {
        return true; // any authenticated user can view
    }
PHP;
    }

    private function createPolicy(): string
    {
        return <<<PHP
    public function create(User \$user): bool
    {
        return true; // any authenticated user can create
    }
PHP;
    }

    private function updatePolicy(string $model, string $variable, string $ownerCheck): string
    {
        return <<<PHP
    public function update(User \$user, {$model} \${$variable}): bool
    {
        return {$ownerCheck};
    }
PHP;
    }

    private function deletePolicy(string $model, string $variable, string $ownerCheck): string
    {
        return <<<PHP
    public function delete(User \$user, {$model} \${$variable}): bool
    {
        return {$ownerCheck};
    }
PHP;
    }

    private function restorePolicy(string $model, string $variable, string $ownerCheck): string
    {
        return <<<PHP

    public function restore(User \$user, {$model} \${$variable}): bool
    {
        return {$ownerCheck};
    }
PHP;
    }

    private function forceDeletePolicy(string $model, string $variable): string
    {
        return <<<PHP

    public function forceDelete(User \$user, {$model} \${$variable}): bool
    {
        return false; // only super-admins — implement your role check here
    }
PHP;
    }

    private function roleBasedMethods(string $modelName, array $relationships): string
    {
        // If model has a roles belongsToMany, generate a role-check helper
        $hasBtm = collect($relationships)->where('type', 'belongsToMany')->isNotEmpty();
        if (!$hasBtm) return '';

        return <<<PHP

    // capyrel: helper — check if user has a specific role
    private function userHasRole(User \$user, string \$role): bool
    {
        return method_exists(\$user, 'roles')
            && \$user->roles->contains('name', \$role);
    }

PHP;
    }

    private function hasColumn(array $columns, string $name): bool
    {
        return collect($columns)->pluck('name')->contains($name);
    }
}
