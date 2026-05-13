<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

class EnumGenerator
{
    private array $knownEnumColumns = [
        'status'     => ['active', 'inactive', 'pending', 'archived'],
        'type'       => ['standard', 'premium', 'enterprise'],
        'role'       => ['admin', 'editor', 'viewer', 'guest'],
        'priority'   => ['low', 'medium', 'high', 'critical'],
        'visibility' => ['public', 'private', 'draft'],
        'state'      => ['open', 'closed', 'resolved'],
        'gender'     => ['male', 'female', 'other', 'prefer_not_to_say'],
        'difficulty' => ['easy', 'medium', 'hard', 'expert'],
    ];

    private array $colorMap = [
        'active'    => 'green',   'inactive'  => 'gray',   'pending'   => 'yellow',
        'archived'  => 'gray',    'admin'     => 'red',    'editor'    => 'blue',
        'viewer'    => 'gray',    'guest'     => 'gray',   'low'       => 'green',
        'medium'    => 'yellow',  'high'      => 'orange', 'critical'  => 'red',
        'public'    => 'green',   'private'   => 'gray',   'draft'     => 'yellow',
        'open'      => 'blue',    'closed'    => 'gray',   'resolved'  => 'green',
        'easy'      => 'green',   'hard'      => 'orange', 'expert'    => 'red',
    ];

    public function generate(string $modelName, string $columnName, array $values = []): string
    {
        $enumName = Str::studly($modelName) . Str::studly($columnName);
        $cases    = empty($values) ? ($this->knownEnumColumns[$columnName] ?? ['option_a', 'option_b', 'option_c']) : $values;
        $caseDefs = $this->buildCases($cases);
        $labels   = $this->buildLabels($cases);
        $colors   = $this->buildColors($cases);
        $badges   = $this->buildBadges($cases);

        return <<<PHP
<?php

namespace App\Enums;

enum {$enumName}: string
{
{$caseDefs}

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function label(): string
    {
        return match(\$this) {
{$labels}
        };
    }

    /** Tailwind color class */
    public function color(): string
    {
        return match(\$this) {
{$colors}
        };
    }

    /** Bootstrap badge class */
    public function badge(): string
    {
        return match(\$this) {
{$badges}
        };
    }

    public static function options(): array
    {
        return array_map(fn(\$case) => [
            'value' => \$case->value,
            'label' => \$case->label(),
        ], self::cases());
    }
}
PHP;
    }

    private function buildCases(array $values): string
    {
        return collect($values)
            ->map(fn($v) => "    case " . Str::studly($v) . " = '{$v}';")
            ->implode("\n");
    }

    private function buildLabels(array $values): string
    {
        return collect($values)
            ->map(fn($v) => "            self::" . Str::studly($v) . " => '" . Str::headline($v) . "',")
            ->implode("\n");
    }

    private function buildColors(array $values): string
    {
        return collect($values)
            ->map(fn($v) => "            self::" . Str::studly($v) . " => '" . ($this->colorMap[$v] ?? 'gray') . "',")
            ->implode("\n");
    }

    private function buildBadges(array $values): string
    {
        $bsMap = [
            'green'  => 'success',
            'red'    => 'danger',
            'yellow' => 'warning',
            'blue'   => 'primary',
            'gray'   => 'secondary',
            'orange' => 'warning',
        ];

        return collect($values)
            ->map(function ($v) use ($bsMap) {
                $color = $this->colorMap[$v] ?? 'gray';
                $bs    = $bsMap[$color] ?? 'secondary';
                return "            self::" . Str::studly($v) . " => 'badge bg-{$bs}',";
            })
            ->implode("\n");
    }

    public function detectEnumColumns(array $columns): array
    {
        $enumCols = [];
        foreach ($columns as $col) {
            $name = $col['name'];
            if (isset($this->knownEnumColumns[$name])) {
                $enumCols[] = $name;
            }
        }
        return $enumCols;
    }
}
