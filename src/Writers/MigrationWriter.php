<?php

namespace Julio\Capyrel\Writers;

use Illuminate\Support\Str;

/**
 * Renders a complete Laravel migration file from a field plan.
 *
 * Produces standard Blueprint method calls and handles:
 *   - All common column types
 *   - Nullable, unique, default modifiers
 *   - Decimal precision/scale
 *   - Foreign key constraints via foreignId()->constrained()
 *   - Soft deletes
 *   - Timestamps (always added)
 */
class MigrationWriter
{
    /**
     * Render a full migration file string.
     *
     * @param  string  $table       e.g. 'blog_posts'
     * @param  array   $fields      FieldInferrer output
     * @param  bool    $softDeletes Add deleted_at column
     * @return string  Full PHP file content
     */
    public function render(string $table, array $fields, bool $softDeletes = false): string
    {
        $columns   = $this->renderColumns($fields, $softDeletes);
        $className = 'Create' . Str::studly($table) . 'Table';

        return <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$table}', function (Blueprint \$table) {
                    \$table->id();
        {$columns}
                    \$table->timestamps();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };
        PHP;
    }

    /**
     * Generate the migration filename (without path).
     * Format: YYYY_MM_DD_HHMMSS_create_{table}_table.php
     */
    public function filename(string $table, int $offsetSeconds = 0): string
    {
        $ts = now()->addSeconds($offsetSeconds);
        return $ts->format('Y_m_d_His') . "_create_{$table}_table.php";
    }

    /**
     * Write the migration to disk and return the absolute path.
     *
     * @param  array  $plan  ModelPlanBuilder output
     * @param  int    $offsetSeconds  Offset so multiple migrations don't clash
     * @return string  Absolute path of the written file
     */
    public function write(array $plan, int $offsetSeconds = 0): string
    {
        $table    = $plan['table'];
        $fields   = $plan['fields'];
        $soft     = $plan['soft_deletes'] ?? false;

        $content  = $this->render($table, $fields, $soft);
        $filename = $this->filename($table, $offsetSeconds);
        $path     = database_path("migrations/{$filename}");

        if (!is_dir(database_path('migrations'))) {
            mkdir(database_path('migrations'), 0755, true);
        }

        file_put_contents($path, $content);

        return $path;
    }

    // ── Column rendering ──────────────────────────────────────────────────────

    private function renderColumns(array $fields, bool $softDeletes): string
    {
        $lines = [];

        // Skip id — Blueprint::id() is already added above timestamps()
        $skip = ['id', 'created_at', 'updated_at'];

        foreach ($fields as $f) {
            if (in_array($f['name'], $skip, true)) continue;
            if ($f['name'] === 'deleted_at') continue; // handled by softDeletes()

            $line = $this->renderColumn($f);
            if ($line !== null) {
                $lines[] = '            ' . $line . ';';
            }
        }

        if ($softDeletes) {
            $lines[] = '            $table->softDeletes();';
        }

        return implode("\n", $lines);
    }

    private function renderColumn(array $f): ?string
    {
        $name = $f['name'];
        $type = $f['type'];

        $col = match ($type) {
            'string'        => "\$table->string('{$name}')",
            'text'          => "\$table->text('{$name}')",
            'longText'      => "\$table->longText('{$name}')",
            'mediumText'    => "\$table->mediumText('{$name}')",
            'integer'       => "\$table->integer('{$name}')",
            'bigInteger'    => "\$table->bigInteger('{$name}')",
            'unsignedInteger' => "\$table->unsignedInteger('{$name}')",
            'tinyInteger'   => "\$table->tinyInteger('{$name}')",
            'smallInteger'  => "\$table->smallInteger('{$name}')",
            'boolean'       => "\$table->boolean('{$name}')",
            'float'         => "\$table->float('{$name}')",
            'double'        => "\$table->double('{$name}')",
            'decimal'       => $this->renderDecimal($f),
            'date'          => "\$table->date('{$name}')",
            'time'          => "\$table->time('{$name}')",
            'datetime'      => "\$table->dateTime('{$name}')",
            'timestamp'     => "\$table->timestamp('{$name}')",
            'json', 'jsonb' => "\$table->json('{$name}')",
            'uuid'          => "\$table->uuid('{$name}')",
            'ulid'          => "\$table->ulid('{$name}')",
            'binary'        => "\$table->binary('{$name}')",
            'foreignId'     => $this->renderForeignId($f),
            'enum'          => "\$table->string('{$name}')",  // plain string, user adds enum cast
            default         => "\$table->string('{$name}')",
        };

        // Append modifiers
        if (!empty($f['nullable']))  $col .= '->nullable()';
        if (!empty($f['unique']))    $col .= '->unique()';

        if (array_key_exists('default', $f) && $f['default'] !== null) {
            $default = is_string($f['default']) ? "'{$f['default']}'" : json_encode($f['default']);
            $col .= "->default({$default})";
        }

        return $col;
    }

    private function renderDecimal(array $f): string
    {
        $name      = $f['name'];
        $precision = $f['precision'] ?? 8;
        $scale     = $f['scale']     ?? 2;
        return "\$table->decimal('{$name}', {$precision}, {$scale})";
    }

    private function renderForeignId(array $f): string
    {
        $name       = $f['name'];
        $references = $f['references'] ?? null;

        if ($references) {
            return "\$table->foreignId('{$name}')->constrained('{$references}')->cascadeOnDelete()";
        }

        return "\$table->foreignId('{$name}')";
    }
}
