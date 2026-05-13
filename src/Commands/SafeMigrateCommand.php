<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Julio\Capyrel\Analyzers\Diagnostic;
use Julio\Capyrel\Analyzers\MigrationSafetyAnalyzer;

class SafeMigrateCommand extends Command
{
    protected $signature = 'migrate:safe
                            {--check      : Scan only — do not run migrations}
                            {--force      : Skip confirmation and run even if issues found}
                            {--database=  : Database connection to use}
                            {--path=      : Migration path}';

    protected $description = 'Scan pending migrations for dangerous patterns before running them';

    public function __construct(private MigrationSafetyAnalyzer $analyzer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pending = $this->getPendingMigrations();

        if (empty($pending)) {
            $this->line('  <fg=green>✔</> No pending migrations.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line("  <fg=cyan;options=bold>Scanning " . count($pending) . " pending migration(s) for safety issues...</>");
        $this->line('');

        $allDiagnostics = [];

        foreach ($pending as $name => $path) {
            $fileDiagnostics = $this->analyzer->analyze($path);
            if (!empty($fileDiagnostics)) {
                $allDiagnostics[$name] = $fileDiagnostics;
            }
        }

        if (empty($allDiagnostics)) {
            $this->line('  <fg=green>✔</> No issues found. Migrations look safe.');
            $this->line('');

            if (!$this->option('check')) {
                $this->call('migrate', array_filter([
                    '--database' => $this->option('database'),
                    '--path'     => $this->option('path'),
                ]));
            }

            return self::SUCCESS;
        }

        // Show issues grouped by migration file
        $errorCount   = 0;
        $warningCount = 0;

        foreach ($allDiagnostics as $migrationName => $diagnostics) {
            $this->line("  <fg=white;options=bold>{$migrationName}</>");

            foreach ($diagnostics as $d) {
                [$icon, $color] = match ($d->level) {
                    Diagnostic::ERROR   => ['✖', 'red'],
                    Diagnostic::WARNING => ['⚠', 'yellow'],
                    default             => ['ℹ', 'gray'],
                };

                $this->line("  {$icon} <fg={$color}>{$d->message}</>");
                $this->line("    <fg=gray>↳ {$d->suggestion}</>");
                $this->line('');

                if ($d->level === Diagnostic::ERROR)   $errorCount++;
                if ($d->level === Diagnostic::WARNING) $warningCount++;
            }
        }

        if ($this->option('check')) {
            $this->line("  <fg=yellow>Check complete — {$errorCount} error(s), {$warningCount} warning(s) found. No migrations were run.</>");
            return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($errorCount > 0 && !$this->option('force')) {
            $this->line("  <fg=red;options=bold>{$errorCount} critical issue(s) found.</>");
            if (!$this->confirm('  These migrations could cause data loss or failure. Run anyway?', false)) {
                $this->line('  <fg=yellow>Aborted. Fix the issues above before migrating.</> ');
                return self::FAILURE;
            }
        } elseif ($warningCount > 0 && !$this->option('force')) {
            if (!$this->confirm("  {$warningCount} warning(s) found. Run migrations anyway?", true)) {
                $this->line('  <fg=yellow>Aborted.</>');
                return self::FAILURE;
            }
        }

        $this->call('migrate', array_filter([
            '--database' => $this->option('database'),
            '--path'     => $this->option('path'),
            '--force'    => true,
        ]));

        return self::SUCCESS;
    }

    private function getPendingMigrations(): array
    {
        try {
            $migrator   = app('migrator');
            $allFiles   = $migrator->getMigrationFiles($migrator->paths());
            $ran        = $migrator->getRepository()->getRan();
            $pending    = [];

            foreach ($allFiles as $name => $path) {
                if (!in_array($name, $ran)) {
                    $pending[$name] = $path;
                }
            }

            return $pending;
        } catch (\Throwable $e) {
            // Migrations table may not exist yet
            $migDir = database_path('migrations');
            $files  = [];
            foreach (glob("{$migDir}/*.php") as $f) {
                $files[pathinfo($f, PATHINFO_FILENAME)] = $f;
            }
            return $files;
        }
    }
}
