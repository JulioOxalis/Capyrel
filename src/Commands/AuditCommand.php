<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Analyzers\DiagnosticsRunner;
use Julio\Capyrel\Analyzers\Diagnostic;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;

class AuditCommand extends Command
{
    protected $signature = 'capyrel:audit
                            {--connection= : Database connection to use}
                            {--output=     : Save report to file (default: CAPYREL_AUDIT.md)}
                            {--json        : Output as JSON instead of markdown}
                            {--ci          : Exit with non-zero code if errors found (for CI/CD)}';

    protected $description = 'Generate a full health report of your Laravel app schema and relationships';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private DiagnosticsRunner    $diagnostics,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->option('connection') ?? '';

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Running Capyrel Audit...</>');

        try {
            $this->analyzer->analyze($connection);
        } catch (\Throwable $e) {
            $this->error("Cannot read schema: {$e->getMessage()}");
            return self::FAILURE;
        }

        $relationships  = $this->detector->detect();
        $diagnostics    = $this->diagnostics->run($relationships, $this->analyzer);
        $tables         = $this->analyzer->getTables();
        $errors         = array_filter($diagnostics, fn($d) => $d->level === Diagnostic::ERROR);
        $warnings       = array_filter($diagnostics, fn($d) => $d->level === Diagnostic::WARNING);
        $infos          = array_filter($diagnostics, fn($d) => $d->level === Diagnostic::INFO);
        $relCount       = array_sum(array_map('count', $relationships));

        if ($this->option('json')) {
            $this->outputJson($relationships, $diagnostics, $tables);
            return count($errors) > 0 && $this->option('ci') ? self::FAILURE : self::SUCCESS;
        }

        $report  = $this->buildMarkdownReport($relationships, $diagnostics, $tables);
        $outPath = $this->option('output') ?? base_path('CAPYREL_AUDIT.md');

        file_put_contents($outPath, $report);

        // Terminal summary
        $this->line('');
        $this->line("  <fg=white;options=bold>Summary</>");
        $tableCount = count($tables);
        $this->line("  <fg=gray>  Tables:        </>{$tableCount}");
        $this->line("  <fg=gray>  Models:        </>" . count($relationships));
        $this->line("  <fg=gray>  Relationships: </>{$relCount}");
        $this->line("  <fg=red>  Errors:        </>" . count($errors));
        $this->line("  <fg=yellow>  Warnings:      </>" . count($warnings));
        $this->line("  <fg=gray>  Info:          </>" . count($infos));
        $this->line('');
        $this->line("  <fg=green>✔</> Report saved to <fg=white>{$outPath}</>");
        $this->line('');

        if (count($errors) > 0) {
            $this->line("  <fg=red>⚠ {$count} critical issue(s) found. Review " . basename($outPath) . " immediately.</>");
        } elseif (count($warnings) > 0) {
            $this->line("  <fg=yellow>⚠ " . count($warnings) . " warning(s) found. Review " . basename($outPath) . ".</>");
        } else {
            $this->line("  <fg=green>✔ No issues found. Schema looks healthy.</>") ;
        }

        $this->line('');

        return (count($errors) > 0 && $this->option('ci')) ? self::FAILURE : self::SUCCESS;
    }

    private function buildMarkdownReport(array $relationships, array $diagnostics, array $tables): string
    {
        $now        = now()->format('Y-m-d H:i:s');
        $relCount   = array_sum(array_map('count', $relationships));
        $errors     = array_filter($diagnostics, fn($d) => $d->level === Diagnostic::ERROR);
        $warnings   = array_filter($diagnostics, fn($d) => $d->level === Diagnostic::WARNING);
        $infos      = array_filter($diagnostics, fn($d) => $d->level === Diagnostic::INFO);

        $md  = "# Capyrel Audit Report\n\n";
        $md .= "> Generated: {$now}\n\n";
        $md .= "---\n\n";

        // Summary
        $md .= "## Summary\n\n";
        $md .= "| Metric | Count |\n|---|---|\n";
        $md .= "| Tables | " . count($tables) . " |\n";
        $md .= "| Models with relationships | " . count($relationships) . " |\n";
        $md .= "| Total relationships | {$relCount} |\n";
        $md .= "| 🔴 Errors | " . count($errors) . " |\n";
        $md .= "| 🟡 Warnings | " . count($warnings) . " |\n";
        $md .= "| 🔵 Info | " . count($infos) . " |\n\n";

        // Health status
        if (empty($errors) && empty($warnings)) {
            $md .= "✅ **Schema is healthy. No issues found.**\n\n";
        } elseif (!empty($errors)) {
            $md .= "🚨 **Critical issues found — review errors below immediately.**\n\n";
        } else {
            $md .= "⚠️ **Warnings found — review below.**\n\n";
        }

        // Issues by severity
        foreach ([
            ['label' => '🔴 Errors', 'items' => $errors],
            ['label' => '🟡 Warnings', 'items' => $warnings],
            ['label' => '🔵 Info', 'items' => $infos],
        ] as $section) {
            if (empty($section['items'])) continue;

            $md .= "---\n\n## {$section['label']}\n\n";
            foreach ($section['items'] as $d) {
                $model = $d->model ? "**[{$d->model}]** " : '';
                $md   .= "### {$model}{$d->message}\n\n";
                $md   .= "> **Fix:** {$d->suggestion}\n\n";
            }
        }

        // Relationship map
        $md .= "---\n\n## Relationship Map\n\n";
        foreach ($relationships as $modelName => $rels) {
            $md .= "### {$modelName}\n\n";
            foreach ($rels as $rel) {
                $via   = $rel['via'] ?? '';
                $rel2  = $rel['related'] ?: '(polymorphic)';
                $md   .= "- `{$rel['type']}` → **{$rel2}** `[{$via}]`\n";
            }
            $md .= "\n";
        }

        // Recommendations
        $md .= "---\n\n## Recommended Next Steps\n\n";
        if (!empty($errors)) {
            $md .= "1. **Fix all errors above** — these will cause runtime failures or data loss\n";
        }
        if (!empty($warnings)) {
            $md .= "2. **Review warnings** — these are risks that may not fail immediately\n";
        }
        $md .= "3. Run `php artisan model:factory` — generate Faker factories for all models\n";
        $md .= "4. Run `php artisan model:policy` — generate authorization policies\n";
        $md .= "5. Run `php artisan model:optimize` — add missing \$fillable, \$casts, scopes\n";
        $md .= "6. Run `php artisan model:tests` — generate relationship tests\n\n";

        $md .= "---\n\n*Generated by [Capyrel](https://github.com/JulioOxalis/Capyrel)*\n";

        return $md;
    }

    private function outputJson(array $relationships, array $diagnostics, array $tables): void
    {
        echo json_encode([
            'generated_at'  => now()->toISOString(),
            'tables'        => count($tables),
            'models'        => count($relationships),
            'relationships' => array_sum(array_map('count', $relationships)),
            'errors'        => count(array_filter($diagnostics, fn($d) => $d->level === 'error')),
            'warnings'      => count(array_filter($diagnostics, fn($d) => $d->level === 'warning')),
            'diagnostics'   => array_map(fn($d) => [
                'level'      => $d->level,
                'rule'       => $d->rule,
                'model'      => $d->model,
                'message'    => $d->message,
                'suggestion' => $d->suggestion,
            ], $diagnostics),
        ], JSON_PRETTY_PRINT);
    }
}
