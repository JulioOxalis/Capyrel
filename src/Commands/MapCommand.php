<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;

class MapCommand extends Command
{
    protected $signature = 'model:map
                            {--format=ascii     : Output format: ascii | mermaid | both}
                            {--connection=      : Database connection to use}
                            {--save=            : Save output to file path}';

    protected $description = 'Generate a visual map of all model relationships';

    private array $typeColors = [
        'hasOne'         => 'green',
        'hasMany'        => 'green',
        'belongsTo'      => 'blue',
        'belongsToMany'  => 'magenta',
        'hasManyThrough' => 'cyan',
        'hasOneThrough'  => 'cyan',
        'morphTo'        => 'yellow',
        'morphMany'      => 'yellow',
    ];

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->option('connection') ?? '';
        $format     = strtolower($this->option('format'));

        try {
            $this->analyzer->analyze($connection);
        } catch (\Throwable $e) {
            $this->error("Cannot read schema: {$e->getMessage()}");
            return self::FAILURE;
        }

        $relationships = $this->detector->detect();

        if (empty($relationships)) {
            $this->warn('No relationships detected.');
            return self::SUCCESS;
        }

        $modelCount    = count($relationships);
        $relCount      = array_sum(array_map('count', $relationships));

        $output = '';

        if (in_array($format, ['ascii', 'both'])) {
            $this->renderAscii($relationships, $modelCount, $relCount);
        }

        if (in_array($format, ['mermaid', 'both'])) {
            $mermaid = $this->buildMermaid($relationships);
            $this->line('');
            $this->line('  <fg=yellow;options=bold>Mermaid Diagram</>  <fg=gray>(paste into GitHub markdown or mermaid.live)</>');
            $this->line('');
            $this->line('  ```mermaid');
            foreach (explode("\n", $mermaid) as $line) {
                $this->line("  {$line}");
            }
            $this->line('  ```');
            $output = $mermaid;
        }

        if ($save = $this->option('save')) {
            $content = $format === 'mermaid'
                ? "```mermaid\n{$this->buildMermaid($relationships)}\n```"
                : $this->buildAsciiText($relationships, $modelCount, $relCount);

            file_put_contents($save, $content);
            $this->line('');
            $this->line("  <fg=green>✔</> Saved to {$save}");
        }

        return self::SUCCESS;
    }

    // ── ASCII ─────────────────────────────────────────────────────────────────

    private function renderAscii(array $relationships, int $modelCount, int $relCount): void
    {
        $this->line('');
        $this->line('  <fg=cyan;options=bold>╔══════════════════════════════════════════╗</>');
        $this->line('  <fg=cyan;options=bold>║    CAPYREL — MODEL RELATIONSHIP MAP      ║</>');
        $this->line('  <fg=cyan;options=bold>╚══════════════════════════════════════════╝</>');
        $this->line("  <fg=gray>{$modelCount} models · {$relCount} relationships</>");
        $this->line('');

        foreach ($relationships as $modelName => $rels) {
            $this->line("  <fg=white;options=bold>{$modelName}</>");
            $last = array_key_last($rels);

            foreach ($rels as $i => $rel) {
                $tree    = ($i === $last) ? '  └── ' : '  ├── ';
                $color   = $this->typeColors[$rel['type']] ?? 'white';
                $type    = str_pad($rel['type'], 16);
                $related = $rel['related'] ?: '(polymorphic)';
                $extra   = isset($rel['through']) ? " <fg=gray>(via {$rel['through']})</>" : '';

                $this->line("  {$tree}<fg={$color}>{$type}</> ──▶ <fg=white>{$related}</>{$extra}");
            }

            $this->line('');
        }
    }

    private function buildAsciiText(array $relationships, int $modelCount, int $relCount): string
    {
        $lines = [
            "CAPYREL — MODEL RELATIONSHIP MAP",
            "{$modelCount} models · {$relCount} relationships",
            '',
        ];

        foreach ($relationships as $modelName => $rels) {
            $lines[] = $modelName;
            $last    = array_key_last($rels);

            foreach ($rels as $i => $rel) {
                $tree    = ($i === $last) ? '  └── ' : '  ├── ';
                $type    = str_pad($rel['type'], 16);
                $related = $rel['related'] ?: '(polymorphic)';
                $extra   = isset($rel['through']) ? " (via {$rel['through']})" : '';
                $lines[] = "{$tree}{$type} ──▶ {$related}{$extra}";
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ── Mermaid ───────────────────────────────────────────────────────────────

    private function buildMermaid(array $relationships): string
    {
        $lines = ['erDiagram'];
        $seen  = [];

        $cardinality = [
            'hasOne'         => '||--||',
            'hasMany'        => '||--o{',
            'belongsTo'      => '}o--||',
            'belongsToMany'  => '}o--o{',
            'hasManyThrough' => '||--o{',
            'hasOneThrough'  => '||--||',
            'morphTo'        => '}o--o|',
            'morphMany'      => '||--o{',
        ];

        foreach ($relationships as $modelName => $rels) {
            foreach ($rels as $rel) {
                if (empty($rel['related'])) continue;

                // Deduplicate: A→B and B→A both exist, only emit once
                $key     = implode('_', array_unique([
                    min($modelName, $rel['related']),
                    max($modelName, $rel['related']),
                    $rel['type'],
                ]));

                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $card  = $cardinality[$rel['type']] ?? '||--o{';
                $label = $rel['type'];
                $lines[] = "    {$modelName} {$card} {$rel['related']} : \"{$label}\"";
            }
        }

        return implode("\n", $lines);
    }
}
