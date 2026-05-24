<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Wizard\EntityParser;
use Julio\Capyrel\Wizard\FieldInferrer;
use Julio\Capyrel\Wizard\InteractiveEditor;
use Julio\Capyrel\Wizard\LaravelAwareness;
use Julio\Capyrel\Wizard\ModelPlanBuilder;
use Julio\Capyrel\Writers\MigrationWriter;

/**
 * php artisan capyrel:new
 *
 * Interactive project-setup wizard.
 * Generates app/Models/*.php + database/migrations/*.php for every model the
 * user defines. No Blade views, no controllers — just the schema layer.
 *
 * Flow
 * ────
 * 1. Banner + Laravel-awareness context line
 * 2. Optional: detect existing models and offer to enhance them
 * 3. Ask for a project description → Python NLP or heuristic parse → entity list
 * 4. For each entity: show proposed fields → interactive review/edit loop
 * 5. "Any more models?" loop
 * 6. Final plan summary → confirm → write Model + Migration files
 */
class NewProjectCommand extends Command
{
    protected $signature = 'capyrel:new
                            {--force       : Skip all confirmation prompts, write everything}
                            {--skip-python : Skip Python NLP and use PHP heuristic parsing only}';

    protected $description = 'Interactive wizard — define models and migrations for your new project';

    private string $pythonScript;

    public function __construct(
        private LaravelAwareness $awareness,
        private EntityParser     $parser,
        private FieldInferrer    $inferrer,
        private ModelPlanBuilder $planBuilder,
        private InteractiveEditor $editor,
        private MigrationWriter  $migrationWriter,
    ) {
        parent::__construct();
        $this->pythonScript = __DIR__ . '/../../python/capyrel_nlp.py';
    }

    public function handle(): int
    {
        $this->banner();

        // ── Environment context ───────────────────────────────────────────────
        $this->line('  <fg=gray>' . $this->awareness->contextLine() . '</>');

        foreach ($this->awareness->suggestions() as $hint) {
            $this->line("  <fg=yellow>⚡</> {$hint}");
        }

        $this->line('');

        // ── Existing models ───────────────────────────────────────────────────
        $existing = $this->awareness->existingModels();
        if (!empty($existing)) {
            $this->line('  <fg=yellow>Existing models found:</> ' . implode(', ', $existing));
            $this->line('  <fg=gray>These will be skipped automatically to avoid overwriting.</>');
            $this->line('');
        }

        // ── Collect entities ──────────────────────────────────────────────────
        $plans = $this->collectPlans($existing);

        if (empty($plans)) {
            $this->line('  <fg=gray>No models to generate. Exiting.</>');
            return self::SUCCESS;
        }

        // ── Final summary ─────────────────────────────────────────────────────
        $this->showFinalSummary($plans);

        if (!$this->option('force') && !$this->confirm('  Write these ' . count($plans) . ' model(s) and migration(s)?', true)) {
            $this->line('  <fg=gray>Aborted. Nothing was written.</>');
            return self::SUCCESS;
        }

        // ── Write files ───────────────────────────────────────────────────────
        $this->writeAll($plans);

        // ── Next steps ────────────────────────────────────────────────────────
        $this->nextSteps($plans);

        return self::SUCCESS;
    }

    // ── Entity collection ─────────────────────────────────────────────────────

    private function collectPlans(array $existing): array
    {
        $plans   = [];
        $isFirst = true;

        while (true) {
            if ($isFirst) {
                $plans   = $this->collectFromDescription($existing);
                $isFirst = false;
            }

            // "Any more models?" loop
            $this->line('');

            if ($this->option('force')) break;

            $more = $this->ask('  Add another model? (enter a name, or leave blank to finish)');

            if (empty(trim($more ?? ''))) {
                break;
            }

            $name = Str::studly(Str::singular(trim($more)));

            if (in_array($name, $existing, true)) {
                $this->line("  <fg=yellow>⚠</> {$name} already exists — skipped.");
                continue;
            }

            if (in_array($name, array_column($plans, 'entity'), true)) {
                $this->line("  <fg=yellow>⚠</> {$name} is already in this session — skipped.");
                continue;
            }

            $plan = $this->buildEntityPlan($name);

            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    private function collectFromDescription(array $existing): array
    {
        $this->line('  <fg=white;options=bold>Step 1 — Describe your project</>');
        $this->line('  <fg=gray>Examples: "a blog with posts, tags, and authors"</>');
        $this->line('  <fg=gray>          "Post, Comment, User, Tag"</>');
        $this->line('  <fg=gray>          "e-commerce: Product, Order, Customer, Review"</>');
        $this->line('');

        $description = $this->ask('  Describe your project (or list your models)');

        if (empty(trim($description ?? ''))) {
            return [];
        }

        $entities = $this->parseDescription($description);

        if (empty($entities)) {
            $this->line('  <fg=yellow>Could not detect any entities. Enter them manually below.</>');
            return [];
        }

        // Filter out already-existing models
        $entities = array_filter($entities, fn($e) => !in_array($e, $existing, true));
        $entities = array_values($entities);

        if (empty($entities)) {
            $this->line('  <fg=gray>All detected models already exist.</>');
            return [];
        }

        $this->line('');
        $this->line('  <fg=green;options=bold>Detected entities: </>' . implode(', ', array_map(fn($e) => "<fg=cyan>{$e}</>", $entities)));
        $this->line('');

        $plans = [];

        foreach ($entities as $entity) {
            $plan = $this->buildEntityPlan($entity);

            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    private function buildEntityPlan(string $entity): ?array
    {
        $this->line("  ── <fg=white;options=bold>{$entity}</> ──────────────────────────");
        $this->line('');

        // Get archetype fields from Python or heuristic fallback
        $defaultFields = $this->suggestFields($entity);

        if (!empty($defaultFields)) {
            $this->line('  <fg=gray>Suggested fields (from archetype): ' . implode(', ', $defaultFields) . '</>');
        }

        $fieldInput = $this->ask(
            "  Fields for {$entity} (comma-separated, or press Enter to use suggestions)",
            empty($defaultFields) ? 'name, description:text:null' : implode(', ', $defaultFields),
        );

        $fieldSpecs = array_map('trim', explode(',', $fieldInput ?? ''));
        $fieldSpecs = array_filter($fieldSpecs);

        $plan = $this->planBuilder->build($entity, array_values($fieldSpecs));

        // Interactive review/edit loop
        $confirmed = $this->editor->review($this, $plan);

        return $confirmed ? $plan : null;
    }

    // ── Description parsing (PHP heuristic or Python NLP) ─────────────────────

    private function parseDescription(string $description): array
    {
        if (!$this->option('skip-python')) {
            $entities = $this->pythonDescribe($description);

            if (!empty($entities)) {
                return $entities;
            }
        }

        // PHP fallback
        return $this->parser->parse($description);
    }

    private function suggestFields(string $entity): array
    {
        if (!$this->option('skip-python')) {
            $result = $this->runPython('fields', $entity);

            if (isset($result['fields']) && is_array($result['fields'])) {
                if (isset($result['archetype']) && $result['archetype'] !== 'generic') {
                    $this->line("  <fg=gray>Archetype matched: <fg=white>{$result['archetype']}</></>");
                }
                return $result['fields'];
            }
        }

        return [];
    }

    private function pythonDescribe(string $text): array
    {
        $result = $this->runPython('describe', $text);

        if (isset($result['entities']) && is_array($result['entities'])) {
            // Show detected relations as hints
            if (!empty($result['relations'])) {
                $this->line('  <fg=gray>Detected relations:</>');
                foreach ($result['relations'] as $rel) {
                    $this->line("    <fg=gray>{$rel['from']} —[{$rel['type']}]→ {$rel['to']}</>");
                }
                $this->line('');
            }

            return $result['entities'];
        }

        return [];
    }

    // ── Python bridge ─────────────────────────────────────────────────────────

    private function runPython(string $command, string $arg): array
    {
        if (!file_exists($this->pythonScript)) {
            return [];
        }

        $python = $this->resolvePythonBin();

        if ($python === null) {
            return [];
        }

        $escaped = escapeshellarg($arg);
        $script  = escapeshellarg($this->pythonScript);
        $cmd     = "{$python} {$script} {$command} {$escaped} 2>/dev/null";

        // Windows compatibility
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "{$python} {$script} {$command} {$escaped} 2>NUL";
        }

        $output = shell_exec($cmd);

        if (empty($output)) {
            return [];
        }

        $decoded = json_decode(trim($output), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolvePythonBin(): ?string
    {
        foreach (['python3', 'python'] as $bin) {
            $test = shell_exec("{$bin} --version 2>&1");
            if ($test && str_contains($test, 'Python 3')) {
                return $bin;
            }
        }

        return null;
    }

    // ── Summary & writing ─────────────────────────────────────────────────────

    private function showFinalSummary(array $plans): void
    {
        $this->line('');
        $this->line('  <fg=white;options=bold>Final Plan</>');
        $this->line('');

        $rows = [];

        foreach ($plans as $plan) {
            $fieldNames = implode(', ', array_column($plan['fields'], 'name'));
            $rels       = implode(', ', array_map(
                fn($r) => "→{$r['related']}",
                $plan['relations'],
            ));

            $rows[] = [
                $plan['entity'],
                $plan['table'],
                $fieldNames ?: '—',
                $rels ?: '—',
                $plan['soft_deletes'] ? 'yes' : 'no',
            ];
        }

        $this->table(
            ['Model', 'Table', 'Fields', 'Relations', 'SoftDel'],
            $rows,
        );
    }

    private function writeAll(array $plans): void
    {
        $this->line('');
        $this->line('  <fg=gray>Writing files...</>');
        $this->line('');

        $offset = 0;

        foreach ($plans as $plan) {
            $entity = $plan['entity'];

            // Model
            $modelPath = $this->writeModel($plan);
            $modelLabel = 'app/Models/' . $entity . '.php';
            $this->line("  <fg=green>✔</> {$modelLabel}");

            // Migration
            $migPath   = $this->migrationWriter->write($plan, $offset++);
            $migLabel  = 'database/migrations/' . basename($migPath);
            $this->line("  <fg=green>✔</> {$migLabel}");

            $this->line('');
        }
    }

    private function writeModel(array $plan): string
    {
        $entity   = $plan['entity'];
        $table    = $plan['table'];
        $fillable = $this->renderFillable($plan['fillable']);
        $casts    = $this->renderCasts($plan['casts']);
        $traits   = $this->renderTraits($plan);
        $uses     = $this->renderUses($plan);
        $relations = $this->renderRelationMethods($plan['relations']);
        $softDel  = $plan['soft_deletes'] ? "\n    use SoftDeletes;" : '';
        $sdUse    = $plan['soft_deletes'] ? "\nuse Illuminate\Database\Eloquent\SoftDeletes;" : '';

        $code = <<<PHP
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;{$sdUse}{$uses}

        class {$entity} extends Model
        {{$softDel}
            protected \$table = '{$table}';

            protected \$fillable = [{$fillable}
            ];

            protected function casts(): array
            {
                return [{$casts}
                ];
            }
        {$relations}}
        PHP;

        $path = app_path("Models/{$entity}.php");

        if (!is_dir(app_path('Models'))) {
            mkdir(app_path('Models'), 0755, true);
        }

        file_put_contents($path, $code);

        return $path;
    }

    // ── Model code renderers ──────────────────────────────────────────────────

    private function renderFillable(array $fillable): string
    {
        if (empty($fillable)) return '';

        return "\n        '" . implode("',\n        '", $fillable) . "',";
    }

    private function renderCasts(array $casts): string
    {
        if (empty($casts)) return '';

        $lines = [];
        foreach ($casts as $field => $cast) {
            $lines[] = "        '{$field}' => '{$cast}',";
        }

        return "\n" . implode("\n", $lines) . "\n    ";
    }

    private function renderTraits(array $plan): string
    {
        if (empty($plan['traits'])) return '';

        $lines = [];
        foreach ($plan['traits'] as $trait) {
            $lines[] = "    use {$trait};";
        }

        return "\n" . implode("\n", $lines);
    }

    private function renderUses(array $plan): string
    {
        $uses = [];

        foreach ($plan['traits'] as $trait) {
            $uses[] = match ($trait) {
                'Searchable'          => 'use Laravel\Scout\Searchable;',
                'InteractsWithMedia'  => 'use Spatie\MediaLibrary\InteractsWithMedia;',
                default               => '',
            };
        }

        $uses = array_filter($uses);

        return empty($uses) ? '' : "\n" . implode("\n", $uses);
    }

    private function renderRelationMethods(array $relations): string
    {
        if (empty($relations)) return '';

        $methods = '';

        foreach ($relations as $rel) {
            $related  = $rel['related'];
            $method   = Str::camel($related);
            $fk       = $rel['foreign_key'];

            $methods .= <<<PHP

                public function {$method}(): BelongsTo
                {
                    return \$this->belongsTo({$related}::class, '{$fk}');
                }

            PHP;
        }

        return $methods;
    }

    // ── Next-step tips ────────────────────────────────────────────────────────

    private function nextSteps(array $plans): void
    {
        $modelList = implode(', ', array_column($plans, 'entity'));

        $this->line('');
        $this->line('  <fg=green;options=bold>✔ Done!</>');
        $this->line('');
        $this->line("  <fg=white>Models created:</> <fg=cyan>{$modelList}</>");
        $this->line('');
        $this->line('  <fg=yellow>Next steps:</>');
        $this->line('  <fg=gray>  1.</> Run <fg=cyan>php artisan migrate</> to create the tables.');
        $this->line('  <fg=gray>  2.</> Run <fg=cyan>php artisan model:scaffold</> to add relationships + controllers.');
        $this->line('  <fg=gray>  3.</> Run <fg=cyan>php artisan capyrel:factory</> to generate factories + seeders.');
        $this->line('  <fg=gray>  4.</> Run <fg=cyan>php artisan capyrel:openapi</> to generate an OpenAPI spec.');

        if ($this->awareness->hasLivewire()) {
            $this->line('  <fg=gray>  5.</> Run <fg=cyan>php artisan capyrel:livewire</> to generate reactive components.');
        }

        $this->line('');
    }

    // ── Banner ────────────────────────────────────────────────────────────────

    private function banner(): void
    {
        $this->line('');
        $this->line('  <fg=cyan;options=bold> ██████╗ █████╗ ██████╗ ██╗   ██╗██████╗ ███████╗██╗</>');
        $this->line('  <fg=cyan;options=bold>██╔════╝██╔══██╗██╔══██╗╚██╗ ██╔╝██╔══██╗██╔════╝██║</>');
        $this->line('  <fg=cyan;options=bold>██║     ███████║██████╔╝ ╚████╔╝ ██████╔╝█████╗  ██║</>');
        $this->line('  <fg=cyan;options=bold>██║     ██╔══██║██╔═══╝   ╚██╔╝  ██╔══██╗██╔══╝  ██║</>');
        $this->line('  <fg=cyan;options=bold>╚██████╗██║  ██║██║        ██║   ██║  ██║███████╗███████╗</>');
        $this->line('  <fg=cyan;options=bold> ╚═════╝╚═╝  ╚═╝╚═╝        ╚═╝   ╚═╝  ╚═╝╚══════╝╚══════╝</>');
        $this->line('  <fg=gray>  New Project Wizard — models + migrations, zero friction</>');
        $this->line('');
    }
}
