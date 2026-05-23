<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

/**
 * Generates a per-model Artisan command with subcommands:
 *   php artisan posts list [--status=draft] [--format=table|json|csv]
 *   php artisan posts create --title="Hello" --status=draft
 *   php artisan posts delete 42 [--force]
 *   php artisan posts show 42
 *   php artisan posts stats
 */
class ArtisanCommandGenerator
{
    public function generate(string $modelName, array $columns, array $relationships): string
    {
        $route      = Str::kebab(Str::plural($modelName));
        $variable   = Str::camel($modelName);
        $variables  = Str::camel(Str::plural($modelName));
        $title      = Str::headline(Str::plural($modelName));
        $hasSoft    = in_array('deleted_at', array_column($columns, 'name'));

        $createOptions  = $this->buildCreateOptions($columns);
        $createFill     = $this->buildCreateFill($columns);
        $tableHeaders   = $this->buildTableHeaders($columns);
        $tableRows      = $this->buildTableRows($columns, $variable);
        $statsBody      = $this->buildStatsBody($modelName, $columns);
        $softCommands   = $hasSoft ? $this->buildSoftDeleteSubcommands($modelName, $variable) : '';

        return <<<PHP
<?php

namespace App\Console\Commands;

use App\Models\\{$modelName};
use Illuminate\Console\Command;

/**
 * Artisan management command for the {$modelName} model.
 *
 * Usage examples:
 *   php artisan {$route} list
 *   php artisan {$route} list --format=json
 *   php artisan {$route} create --title="Hello World"
 *   php artisan {$route} show 42
 *   php artisan {$route} delete 42 --force
 *   php artisan {$route} stats
 */
class {$modelName}Command extends Command
{
    protected \$signature = '{$route}
                            {action : list|show|create|delete|stats" . ($hasSoft ? '|restore' : '') . "}
                            {id?    : Record ID (for show/delete" . ($hasSoft ? '/restore' : '') . ")}
                            {--format=table : Output format for list: table|json|csv}
                            {--force        : Skip confirmation for destructive actions}
                            {--trashed      : Include soft-deleted records in list}
{$createOptions}';

    protected \$description = 'Manage {$title} from the command line';

    public function handle(): int
    {
        return match(\$this->argument('action')) {
            'list'    => \$this->actionList(),
            'show'    => \$this->actionShow(),
            'create'  => \$this->actionCreate(),
            'delete'  => \$this->actionDelete(),
            'stats'   => \$this->actionStats(),
{$softCommands}
            default   => \$this->invalidAction(),
        };
    }

    private function actionList(): int
    {
        \$query = {$modelName}::query();
        if (\$this->option('trashed')) {
            \$query->withTrashed();
        }

        \${$variables} = \$query->latest()->get();

        if (\${$variables}->isEmpty()) {
            \$this->line('<fg=yellow>No {$title} found.</>');
            return self::SUCCESS;
        }

        match (\$this->option('format')) {
            'json' => \$this->line(\${$variables}->toJson(JSON_PRETTY_PRINT)),
            'csv'  => \$this->outputCsv(\${$variables}->toArray()),
            default => \$this->table(
                [{$tableHeaders}],
                \${$variables}->map(fn(\${$variable}) => [{$tableRows}])->toArray()
            ),
        };

        \$this->line('  <fg=gray>Total: ' . \${$variables}->count() . '</>');

        return self::SUCCESS;
    }

    private function actionShow(): int
    {
        \$id = \$this->argument('id');
        if (! \$id) { \$this->error('Please provide an ID.'); return self::FAILURE; }

        \${$variable} = {$modelName}::findOrFail(\$id);
        \$this->table(['Field', 'Value'],
            collect(\${$variable}->toArray())
                ->map(fn(\$v, \$k) => [\$k, is_array(\$v) ? json_encode(\$v) : \$v])
                ->values()
                ->toArray()
        );

        return self::SUCCESS;
    }

    private function actionCreate(): int
    {
        \$data = array_filter([
{$createFill}
        ]);

        if (empty(\$data)) {
            \$this->error('No data provided. Use --field=value options.');
            return self::FAILURE;
        }

        \${$variable} = {$modelName}::create(\$data);
        \$this->info("✔ Created {$modelName} #{{\${$variable}->id}}");

        return self::SUCCESS;
    }

    private function actionDelete(): int
    {
        \$id = \$this->argument('id');
        if (! \$id) { \$this->error('Please provide an ID.'); return self::FAILURE; }

        \${$variable} = {$modelName}::findOrFail(\$id);

        if (! \$this->option('force') && ! \$this->confirm("Delete {$modelName} #{\$id}?")) {
            \$this->line('Aborted.');
            return self::SUCCESS;
        }

        \${$variable}->delete();
        \$this->info("✔ {$modelName} #{{\$id}} deleted.");

        return self::SUCCESS;
    }

    private function actionStats(): int
    {
        \$this->line('');
        \$this->line('  <fg=cyan;options=bold>{$title} Statistics</>');
        \$this->line('');
{$statsBody}
        \$this->line('');

        return self::SUCCESS;
    }
{$this->buildSoftMethods($modelName, $variable, $hasSoft)}
    private function invalidAction(): int
    {
        \$valid = ['list', 'show', 'create', 'delete', 'stats'" . ($hasSoft ? ", 'restore'" : '') . "];
        \$this->error("Invalid action '{{\$this->argument('action')}}'. Valid: " . implode(', ', \$valid));
        return self::FAILURE;
    }

    private function outputCsv(array \$rows): void
    {
        if (empty(\$rows)) return;
        \$this->line(implode(',', array_keys(\$rows[0])));
        foreach (\$rows as \$row) {
            \$this->line(implode(',', array_map('strval', \$row)));
        }
    }
}
PHP;
    }

    private function buildCreateOptions(array $columns): string
    {
        $skip  = ['id','created_at','updated_at','deleted_at','remember_token','email_verified_at'];
        $lines = [];

        foreach (array_slice($columns, 0, 8) as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;
            $lines[] = "                            {--{$name}= : Set the {$name} field}";
        }

        return implode("\n", $lines);
    }

    private function buildCreateFill(array $columns): string
    {
        $skip  = ['id','created_at','updated_at','deleted_at','remember_token','email_verified_at'];
        $lines = [];

        foreach (array_slice($columns, 0, 8) as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;
            $lines[] = "            '{$name}' => \$this->option('{$name}'),";
        }

        return implode("\n", $lines);
    }

    private function buildTableHeaders(array $columns): string
    {
        $cols = array_slice(array_column($columns, 'name'), 0, 6);
        return implode(', ', array_map(fn($c) => "'$c'", $cols));
    }

    private function buildTableRows(array $columns, string $variable): string
    {
        $cols = array_slice(array_column($columns, 'name'), 0, 6);
        return implode(', ', array_map(fn($c) => "\${$variable}->{$c}", $cols));
    }

    private function buildStatsBody(string $modelName, array $columns): string
    {
        $lines = ["        \$this->line('  Total: ' . {$modelName}::count());"];

        if (in_array('deleted_at', array_column($columns, 'name'))) {
            $lines[] = "        \$this->line('  Trashed: ' . {$modelName}::onlyTrashed()->count());";
        }

        if (in_array('created_at', array_column($columns, 'name'))) {
            $lines[] = "        \$this->line('  Created today: ' . {$modelName}::whereDate('created_at', today())->count());";
            $lines[] = "        \$this->line('  Created this week: ' . {$modelName}::where('created_at', '>=', now()->startOfWeek())->count());";
        }

        $enumCols = array_filter($columns, fn($c) => strtolower($c['type_name'] ?? '') === 'enum');
        foreach ($enumCols as $col) {
            preg_match_all("/'([^']+)'/", $col['type'] ?? '', $m);
            $name = $col['name'];
            foreach ($m[1] ?? [] as $val) {
                $lines[] = "        \$this->line('  {$name}={$val}: ' . {$modelName}::where('{$name}', '{$val}')->count());";
            }
        }

        return implode("\n", $lines);
    }

    private function buildSoftDeleteSubcommands(string $modelName, string $variable): string
    {
        return "            'restore' => \$this->actionRestore(),\n";
    }

    private function buildSoftMethods(string $modelName, string $variable, bool $hasSoft): string
    {
        if (!$hasSoft) return '';

        return <<<PHP


    private function actionRestore(): int
    {
        \$id = \$this->argument('id');
        if (! \$id) { \$this->error('Please provide an ID.'); return self::FAILURE; }

        \${$variable} = {$modelName}::onlyTrashed()->findOrFail(\$id);
        \${$variable}->restore();
        \$this->info("✔ {$modelName} #{{\$id}} restored.");

        return self::SUCCESS;
    }

PHP;
    }
}
