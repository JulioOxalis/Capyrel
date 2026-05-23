<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\StateMachineGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class StateMachineCommand extends Command
{
    protected $signature = 'model:state-machine
                            {model            : The model name (e.g. Post)}
                            {--column=status  : The ENUM column to build the machine from}
                            {--connection=    : Database connection to use}
                            {--force          : Overwrite existing files}
                            {--dry-run        : Preview without writing}';

    protected $description = 'Generate a StateMachine class for a model\'s status/state ENUM column';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private StateMachineGenerator $generator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $modelName = Str::studly($this->argument('model'));
        $column    = $this->option('column');

        try {
            $this->analyzer->analyze($this->option('connection') ?? '');
        } catch (\Throwable $e) {
            $this->error("Cannot read schema: {$e->getMessage()}");
            return self::FAILURE;
        }

        // Resolve table name
        $table = null;
        foreach ($this->analyzer->getTables() as $t) {
            if (Str::studly(Str::singular($t)) === $modelName) {
                $table = $t;
                break;
            }
        }

        if (!$table) {
            $this->error("No table found for model {$modelName}.");
            return self::FAILURE;
        }

        // Find the ENUM column
        $enumValues = $this->analyzer->getEnumValues($table, $column);
        if (empty($enumValues)) {
            $this->error("Column '{$column}' in table '{$table}' is not an ENUM or has no values.");
            return self::FAILURE;
        }

        $this->line('');
        $this->line("  <fg=cyan>■</> <fg=white;options=bold>{$modelName}</> state machine — column: <fg=yellow>{$column}</>");
        $this->line("  <fg=gray>States: " . implode(', ', $enumValues) . "</>");
        $this->line('');

        if ($this->option('dry-run')) {
            $this->line('  <fg=yellow>Dry run — no files written.</>');
            return self::SUCCESS;
        }

        $written = 0;

        // Exception class
        $exPath = app_path('Exceptions/InvalidStateTransitionException.php');
        if (!file_exists($exPath) || $this->option('force')) {
            if (!is_dir(dirname($exPath))) mkdir(dirname($exPath), 0755, true);
            file_put_contents($exPath, $this->generator->generateException());
            $this->line('  <fg=green>✔</> Created <fg=white>app/Exceptions/InvalidStateTransitionException.php</>');
            $written++;
        } else {
            $this->line('  <fg=gray>~ app/Exceptions/InvalidStateTransitionException.php already exists</>');
        }

        // StatusChanged event
        $eventPath = app_path("Events/{$modelName}StatusChanged.php");
        if (!file_exists($eventPath) || $this->option('force')) {
            if (!is_dir(dirname($eventPath))) mkdir(dirname($eventPath), 0755, true);
            file_put_contents($eventPath, $this->generator->generateStatusChangedEvent($modelName));
            $this->line("  <fg=green>✔</> Created <fg=white>app/Events/{$modelName}StatusChanged.php</>");
            $written++;
        } else {
            $this->line("  <fg=gray>~ app/Events/{$modelName}StatusChanged.php already exists</>");
        }

        // State machine class
        $smDir  = app_path('StateMachines');
        $smPath = "{$smDir}/{$modelName}StateMachine.php";
        if (!is_dir($smDir)) mkdir($smDir, 0755, true);
        if (!file_exists($smPath) || $this->option('force')) {
            file_put_contents($smPath, $this->generator->generate($modelName, $column, $enumValues));
            $this->line("  <fg=green>✔</> Created <fg=white>app/StateMachines/{$modelName}StateMachine.php</>");
            $written++;
        } else {
            $this->line("  <fg=gray>~ app/StateMachines/{$modelName}StateMachine.php already exists</>");
        }

        $this->line('');
        $controllerMethods = $this->generator->generateControllerMethods($modelName, $column, $enumValues);
        $this->line('  <fg=yellow>Add these methods to your controller:</> (or re-scaffold with model:scaffold)</>');
        $this->line('  <fg=gray>' . str_replace("\n", "\n  ", trim($controllerMethods)) . '</>');
        $this->line('');
        $this->line("  <fg=green;options=bold>✔ Done.</> {$written} file(s) created.");
        $this->line('');

        return self::SUCCESS;
    }
}
