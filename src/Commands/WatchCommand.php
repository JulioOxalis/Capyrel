<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Writers\ModelWriter;

class WatchCommand extends Command
{
    protected $signature = 'model:watch
                            {--connection=  : Database connection to use}
                            {--interval=2   : Poll interval in seconds}';

    protected $description = 'Watch migration files and auto-update model relationships when they change';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private ModelWriter          $modelWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->option('connection') ?? '';
        $interval   = max(1, (int) $this->option('interval'));
        $migDir     = database_path('migrations');

        if (!is_dir($migDir)) {
            $this->error("Migrations directory not found: {$migDir}");
            return self::FAILURE;
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Capyrel Watch Mode</>');
        $this->line("  Watching <fg=white>{$migDir}</>");
        $this->line("  Polling every {$interval}s · Press <fg=yellow>Ctrl+C</> to stop");
        $this->line('');

        // Initial analysis
        $this->reanalyze($connection);
        $lastRelationships = $this->detector->detect();
        $lastMtimes        = $this->getMtimes($migDir);

        $this->line("  <fg=green>✔</> Initial scan complete — " . array_sum(array_map('count', $lastRelationships)) . " relationships detected");
        $this->line('');

        while (true) {
            sleep($interval);

            $currentMtimes = $this->getMtimes($migDir);
            $changed       = $this->detectChanges($lastMtimes, $currentMtimes);

            if (empty($changed)) continue;

            $time = date('H:i:s');

            foreach ($changed['modified'] as $file) {
                $this->line("  <fg=yellow>[{$time}]</> Modified: <fg=white>" . basename($file) . "</>");
            }
            foreach ($changed['added'] as $file) {
                $this->line("  <fg=green>[{$time}]</> New migration: <fg=white>" . basename($file) . "</>");
            }

            // Re-analyze schema
            try {
                $this->reanalyze($connection);
            } catch (\Throwable $e) {
                $this->line("  <fg=red>[{$time}]</> Schema read failed: {$e->getMessage()}");
                $lastMtimes = $currentMtimes;
                continue;
            }

            $newRelationships = $this->detector->detect();
            $diff             = $this->diffRelationships($lastRelationships, $newRelationships);

            if (empty($diff)) {
                $this->line("  <fg=gray>[{$time}]</> No relationship changes detected");
            } else {
                foreach ($diff as $modelName => $addedRels) {
                    $path = $this->modelWriter->findModelPath($modelName);
                    if (!$path) {
                        $this->line("  <fg=yellow>⚠</> {$modelName}: model file not found — skipped");
                        continue;
                    }

                    $added = $this->modelWriter->write($path, $addedRels);
                    if ($added > 0) {
                        $this->line("  <fg=green>✔ [{$time}]</> {$modelName}: {$added} new relationship(s) injected");
                        foreach ($addedRels as $rel) {
                            $this->line("    <fg=gray>+ {$rel['type']}({$rel['related']}) via {$rel['via']}</>");
                        }
                    }
                }
            }

            $this->line('');
            $lastRelationships = $newRelationships;
            $lastMtimes        = $currentMtimes;
        }

        return self::SUCCESS;
    }

    private function reanalyze(string $connection): void
    {
        // Reset the SchemaAnalyzer state by re-calling analyze
        $this->analyzer->analyze($connection);
    }

    private function getMtimes(string $dir): array
    {
        $mtimes = [];
        foreach (glob("{$dir}/*.php") as $file) {
            $mtimes[$file] = filemtime($file);
        }
        return $mtimes;
    }

    private function detectChanges(array $old, array $new): array
    {
        $modified = [];
        $added    = [];

        foreach ($new as $file => $mtime) {
            if (!isset($old[$file])) {
                $added[] = $file;
            } elseif ($old[$file] !== $mtime) {
                $modified[] = $file;
            }
        }

        return ['modified' => $modified, 'added' => $added];
    }

    /**
     * Compare old and new relationship maps, return only newly added relationships per model.
     */
    private function diffRelationships(array $old, array $new): array
    {
        $diff = [];

        foreach ($new as $modelName => $rels) {
            $oldMethods = collect($old[$modelName] ?? [])->pluck('method')->toArray();
            $newRels    = array_filter($rels, fn($r) => !in_array($r['method'], $oldMethods));

            if (!empty($newRels)) {
                $diff[$modelName] = array_values($newRels);
            }
        }

        return $diff;
    }
}
