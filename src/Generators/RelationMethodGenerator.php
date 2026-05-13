<?php

namespace Julio\Capyrel\Generators;

class RelationMethodGenerator
{
    public function generate(array $rel): string
    {
        return match ($rel['type']) {
            'hasOne'         => $this->hasOne($rel),
            'hasMany'        => $this->hasMany($rel),
            'belongsTo'      => $this->belongsTo($rel),
            'belongsToMany'  => $this->belongsToMany($rel),
            'hasManyThrough' => $this->hasManyThrough($rel),
            'hasOneThrough'  => $this->hasOneThrough($rel),
            'morphTo'        => $this->morphTo($rel),
            'morphMany'      => $this->morphMany($rel),
            default          => '',
        };
    }

    private function hasOne(array $r): string
    {
        $related = $r['related'];
        $method  = $r['method'];
        $via     = $r['via'];

        return <<<PHP

    // capyrel: {$via}
    public function {$method}(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return \$this->hasOne({$related}::class);
    }
PHP;
    }

    private function hasMany(array $r): string
    {
        $related = $r['related'];
        $method  = $r['method'];
        $via     = $r['via'];

        return <<<PHP

    // capyrel: {$via}
    public function {$method}(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return \$this->hasMany({$related}::class);
    }
PHP;
    }

    private function belongsTo(array $r): string
    {
        $related = $r['related'];
        $method  = $r['method'];
        $via     = $r['via'];

        return <<<PHP

    // capyrel: {$via}
    public function {$method}(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return \$this->belongsTo({$related}::class);
    }
PHP;
    }

    private function belongsToMany(array $r): string
    {
        $related = $r['related'];
        $method  = $r['method'];
        $via     = $r['via'];

        return <<<PHP

    // capyrel: {$via}
    public function {$method}(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return \$this->belongsToMany({$related}::class);
    }
PHP;
    }

    private function hasManyThrough(array $r): string
    {
        $related  = $r['related'];
        $through  = $r['through'];
        $method   = $r['method'];
        $via      = $r['via'];

        return <<<PHP

    // capyrel: {$via}
    public function {$method}(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return \$this->hasManyThrough({$related}::class, {$through}::class);
    }
PHP;
    }

    private function hasOneThrough(array $r): string
    {
        $related  = $r['related'];
        $through  = $r['through'];
        $method   = $r['method'];
        $via      = $r['via'];

        return <<<PHP

    // capyrel: {$via}
    public function {$method}(): \Illuminate\Database\Eloquent\Relations\HasOneThrough
    {
        return \$this->hasOneThrough({$related}::class, {$through}::class);
    }
PHP;
    }

    private function morphTo(array $r): string
    {
        $method = $r['method'];
        $via    = $r['via'];

        return <<<PHP

    // capyrel: {$via}
    public function {$method}(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return \$this->morphTo();
    }
PHP;
    }

    private function morphMany(array $r): string
    {
        $related = $r['related'];
        $method  = $r['method'];
        $name    = $r['name'];
        $via     = $r['via'];

        return <<<PHP

    // capyrel: {$via}
    public function {$method}(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return \$this->morphMany({$related}::class, '{$name}');
    }
PHP;
    }
}
