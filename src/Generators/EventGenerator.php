<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

class EventGenerator
{
    public function generateEvent(string $modelName, string $action): string
    {
        $eventName = $modelName . Str::studly($action);
        $variable  = Str::camel($modelName);

        return <<<PHP
<?php

namespace App\Events;

use App\Models\\{$modelName};
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class {$eventName}
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly {$modelName} \${$variable}) {}
}
PHP;
    }

    public function generateObserver(string $modelName, bool $softDeletes = false): string
    {
        $variable  = Str::camel($modelName);
        $namespace = "App\\Events";

        $events = ['Created', 'Updated', 'Deleted'];
        if ($softDeletes) {
            $events[] = 'Restored';
            $events[] = 'ForceDeleted';
        }

        $methods = collect($events)->map(function ($event) use ($modelName, $variable, $namespace) {
            $action    = lcfirst($event);
            $eventName = $modelName . $event;

            return <<<PHP

    public function {$action}({$modelName} \${$variable}): void
    {
        // {$namespace}\\{$eventName}::dispatch(\${$variable});
    }
PHP;
        })->implode("\n");

        return <<<PHP
<?php

namespace App\Observers;

use App\Models\\{$modelName};

class {$modelName}Observer
{
{$methods}
}
PHP;
    }

    public function generateEventServiceProviderRegistration(array $models): string
    {
        $lines = [];
        foreach ($models as $model) {
            $lines[] = "        {$model}::observe(\\App\\Observers\\{$model}Observer::class);";
        }
        $registrations = implode("\n", $lines);

        return <<<PHP
<?php
// Add to App\Providers\AppServiceProvider::boot() or EventServiceProvider::boot():

{$registrations}
PHP;
    }
}
