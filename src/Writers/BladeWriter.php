<?php

namespace Julio\Capyrel\Writers;

use Illuminate\Support\Str;

class BladeWriter
{
    /**
     * Generate blade usage comment blocks for all relationships on a model.
     */
    public function generateComments(string $modelName, array $relationships): string
    {
        $variable = Str::camel($modelName);
        $lines    = ["{{-- capyrel: relationship usage guide for {$modelName} --}}\n"];

        foreach ($relationships as $rel) {
            $lines[] = $this->blockFor($variable, $rel);
        }

        return implode("\n", $lines);
    }

    private function blockFor(string $variable, array $rel): string
    {
        $method  = $rel['method'];
        $related = Str::camel(Str::singular($rel['related'] ?: 'item'));
        $via     = $rel['via'];
        $type    = $rel['type'];

        $header = <<<BLADE
{{-- ═══════════════════════════════════════════════════════
     CAPYREL  {$type}  →  {$rel['related']}
     detected via: {$via}
     ═══════════════════════════════════════════════════════ --}}
BLADE;

        $body = match ($type) {
            'hasOne', 'belongsTo' => <<<BLADE
@if(\${$variable}->{$method})
    {{-- \${$variable}->{$method}->id --}}
    {{-- \${$variable}->{$method}->name --}}
@endif
BLADE,

            'hasMany', 'hasManyThrough' => <<<BLADE
@forelse(\${$variable}->{$method} as \${$related})
    {{-- \${$related}->id --}}
    {{-- \${$related}->name --}}
@empty
    <p>No {$method}.</p>
@endforelse
{{-- paginated: \${$variable}->{$method}()->paginate(10) --}}
BLADE,

            'belongsToMany' => <<<BLADE
@foreach(\${$variable}->{$method} as \${$related})
    {{-- \${$related}->id --}}
    {{-- \${$related}->name --}}
@endforeach
{{-- attach:  \${$variable}->{$method}()->attach(\$id) --}}
{{-- detach:  \${$variable}->{$method}()->detach(\$id) --}}
{{-- sync:    \${$variable}->{$method}()->sync([1, 2, 3]) --}}
BLADE,

            'morphTo' => <<<BLADE
@if(\${$variable}->{$method})
    {{-- \${$variable}->{$method} resolves to the polymorphic parent --}}
    {{-- \${$variable}->{$method}->id --}}
@endif
BLADE,

            default => "{{-- \${$variable}->{$method} --}}",
        };

        return $header . "\n" . $body . "\n";
    }

    /**
     * Write comments to a blade file.
     * Creates the file if it doesn't exist; appends if it does and isn't already annotated.
     */
    public function writeToView(string $path, string $content): bool
    {
        if (!file_exists($path)) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $content);
            return true;
        }

        $existing = file_get_contents($path);

        if (str_contains($existing, 'capyrel:')) {
            return false; // already annotated
        }

        file_put_contents($path, $existing . "\n\n" . $content);
        return true;
    }

    public function resolveViewPath(string $modelName): string
    {
        $folder = Str::kebab(Str::plural($modelName));
        return resource_path("views/{$folder}/show.blade.php");
    }
}
