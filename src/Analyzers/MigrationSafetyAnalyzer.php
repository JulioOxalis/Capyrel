<?php

namespace Julio\Capyrel\Analyzers;

/**
 * Scans pending migration files for patterns that are dangerous in production.
 * Each finding has a severity level and a concrete fix suggestion.
 */
class MigrationSafetyAnalyzer
{
    public function analyze(string $file): array
    {
        $content     = file_get_contents($file);
        $filename    = basename($file);
        $diagnostics = [];

        $checks = [
            [$this, 'checkNotNullWithoutDefault'],
            [$this, 'checkDropColumn'],
            [$this, 'checkDropTable'],
            [$this, 'checkUniqueOnChange'],
            [$this, 'checkColumnChange'],
            [$this, 'checkRename'],
            [$this, 'checkFkWithoutIndex'],
            [$this, 'checkTruncate'],
            [$this, 'checkTypeNarrowing'],
        ];

        foreach ($checks as $check) {
            $found = $check($content, $filename);
            $diagnostics = array_merge($diagnostics, $found);
        }

        return $diagnostics;
    }

    // ── Checks ────────────────────────────────────────────────────────────────

    private function checkNotNullWithoutDefault(string $c, string $f): array
    {
        $d = [];
        // Matches $table->string/integer/text('col') without ->nullable() or ->default()
        preg_match_all('/\$table->(string|integer|bigInteger|text|boolean|decimal)\s*\(\s*[\'"](\w+)[\'"]/', $c, $m, PREG_OFFSET_CAPTURE);

        foreach ($m[2] as $i => $match) {
            $col     = $match[0];
            $offset  = $match[1];
            // Check 120 chars after the column definition for nullable/default
            $snippet = substr($c, $offset, 120);

            if (!str_contains($snippet, '->nullable()') && !str_contains($snippet, '->default(')) {
                // Only risky in an alter table (->table), not create
                if (str_contains($c, 'Schema::table(')) {
                    $d[] = Diagnostic::error(
                        'migration_safety',
                        '',
                        "{$f}: Adding NOT NULL column '{$col}' to existing table without a default",
                        "Add ->default('value') or ->nullable() — otherwise this migration will FAIL on non-empty tables"
                    );
                }
            }
        }

        return $d;
    }

    private function checkDropColumn(string $c, string $f): array
    {
        $d = [];
        preg_match_all('/->dropColumn\s*\(\s*[\'"](\w+)[\'"]/', $c, $m);

        foreach ($m[1] as $col) {
            $d[] = Diagnostic::warning(
                'migration_safety',
                '',
                "{$f}: dropColumn('{$col}') — permanent data loss",
                "Ensure '{$col}' is not referenced in any model \$fillable, \$casts, or existing queries. Consider a soft-remove pattern first."
            );
        }

        return $d;
    }

    private function checkDropTable(string $c, string $f): array
    {
        $d = [];
        preg_match_all('/Schema::(drop|dropIfExists)\s*\(\s*[\'"](\w+)[\'"]/', $c, $m);

        foreach ($m[2] as $table) {
            $d[] = Diagnostic::error(
                'migration_safety',
                '',
                "{$f}: Schema::drop('{$table}') — entire table will be deleted",
                "Verify no active code references '{$table}'. Run on staging first. Ensure backups exist."
            );
        }

        return $d;
    }

    private function checkUniqueOnChange(string $c, string $f): array
    {
        $d = [];
        if (str_contains($c, 'Schema::table(') && str_contains($c, '->unique()')) {
            preg_match_all('/[\'"](\w+)[\'"]\s*\)\s*->unique\(\)/', $c, $m);
            foreach ($m[1] as $col) {
                $d[] = Diagnostic::warning(
                    'migration_safety',
                    '',
                    "{$f}: Adding unique constraint to '{$col}' on existing table",
                    "Will FAIL if duplicate values exist. Run: SELECT {$col}, COUNT(*) FROM table GROUP BY {$col} HAVING COUNT(*) > 1 — to check first."
                );
            }
        }
        return $d;
    }

    private function checkColumnChange(string $c, string $f): array
    {
        $d = [];
        if (str_contains($c, '->change()')) {
            $d[] = Diagnostic::warning(
                'migration_safety',
                '',
                "{$f}: ->change() modifies an existing column",
                "Column changes can truncate data if the new type is narrower. Test on a copy of production data first. Requires doctrine/dbal."
            );
        }
        return $d;
    }

    private function checkRename(string $c, string $f): array
    {
        $d = [];
        if (str_contains($c, '->renameColumn(') || str_contains($c, 'Schema::rename(')) {
            $d[] = Diagnostic::warning(
                'migration_safety',
                '',
                "{$f}: Column or table rename detected",
                "Any code still using the old name will break immediately. Search your codebase for all usages before deploying."
            );
        }
        return $d;
    }

    private function checkFkWithoutIndex(string $c, string $f): array
    {
        $d = [];
        preg_match_all('/->foreignId\s*\(\s*[\'"](\w+)[\'"]/', $c, $m);

        foreach ($m[1] as $col) {
            // foreignId() in Laravel auto-creates an index, so skip
        }

        // Manual foreign key without index
        preg_match_all('/->foreign\s*\(\s*[\'"](\w+)[\'"]/', $c, $m);
        foreach ($m[1] as $col) {
            if (!str_contains($c, "->index('{$col}')") && !str_contains($c, "\"{$col}\")->index")) {
                $d[] = Diagnostic::info(
                    'migration_safety',
                    '',
                    "{$f}: Manual foreign key on '{$col}' — verify an index exists",
                    "Add \$table->index('{$col}') if you haven't used foreignId() — unindexed FKs cause full table scans on every eager load"
                );
            }
        }

        return $d;
    }

    private function checkTruncate(string $c, string $f): array
    {
        $d = [];
        if (str_contains($c, '->truncate()') || preg_match('/DB::statement\s*\(\s*[\'"]TRUNCATE/', $c)) {
            $d[] = Diagnostic::error(
                'migration_safety',
                '',
                "{$f}: TRUNCATE detected inside a migration",
                "TRUNCATE cannot be rolled back in many databases. Use DELETE instead if you need transactional safety."
            );
        }
        return $d;
    }

    private function checkTypeNarrowing(string $c, string $f): array
    {
        $d = [];
        // Detect change from bigInteger to integer (narrowing)
        if (str_contains($c, '->change()') && preg_match('/->integer\(/', $c) && !preg_match('/->bigInteger\(/', $c)) {
            $d[] = Diagnostic::warning(
                'migration_safety',
                '',
                "{$f}: Possible type narrowing (bigInteger → integer) with ->change()",
                "Values above 2,147,483,647 will be corrupted silently. Verify max values before changing."
            );
        }
        return $d;
    }
}
