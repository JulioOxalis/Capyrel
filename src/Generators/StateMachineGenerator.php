<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

/**
 * Generates a complete State Machine for models with status/state ENUM columns.
 * Produces:
 *   app/StateMachines/{Model}StateMachine.php  — transitions, guards, dispatches events
 *   app/Exceptions/InvalidStateTransitionException.php — typed exception
 * Controller transition methods are also generated for injection into the controller.
 */
class StateMachineGenerator
{
    public function generate(string $modelName, string $statusColumn, array $enumValues): string
    {
        $transitions = $this->inferTransitions($enumValues);
        $transMap    = $this->buildTransitionMap($transitions);
        $methods     = $this->buildTransitionMethods($modelName, $statusColumn, $transitions);
        $variable    = Str::camel($modelName);

        return <<<PHP
<?php

namespace App\StateMachines;

use App\Models\\{$modelName};
use App\Exceptions\InvalidStateTransitionException;

/**
 * State machine for {$modelName}::{$statusColumn}.
 *
 * Allowed transitions:
{$this->buildTransitionDocs($transitions)}
 */
class {$modelName}StateMachine
{
    /**
     * Map of: current_state => [allowed_next_states]
     */
    private const TRANSITIONS = {$transMap};

    public function __construct(private readonly {$modelName} \${$variable}) {}

    /**
     * Attempt a state transition. Throws if the transition is not allowed.
     */
    public function transition(string \$to): void
    {
        if (! \$this->canTransition(\$to)) {
            throw new InvalidStateTransitionException(
                \$this->{$variable}->{$statusColumn},
                \$to,
                self::TRANSITIONS[\$this->{$variable}->{$statusColumn}] ?? []
            );
        }

        \$previous = \$this->{$variable}->{$statusColumn};

        \$this->{$variable}->update(['{$statusColumn}' => \$to]);

        // Dispatch a state-change event for listeners / audit trail
        event(new \App\Events\\{$modelName}StatusChanged(\$this->{$variable}, \$previous, \$to));
    }

    public function canTransition(string \$to): bool
    {
        \$allowed = self::TRANSITIONS[\$this->{$variable}->{$statusColumn}] ?? [];
        return in_array(\$to, \$allowed, true);
    }

    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[\$this->{$variable}->{$statusColumn}] ?? [];
    }

    public function is(string \$state): bool
    {
        return \$this->{$variable}->{$statusColumn} === \$state;
    }
{$methods}
    /** Factory — inject via controller: app({$modelName}StateMachine::class, ['{$variable}' => \${$variable}]) */
    public static function for({$modelName} \${$variable}): static
    {
        return new static(\${$variable});
    }
}
PHP;
    }

    public function generateException(): string
    {
        return <<<PHP
<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidStateTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string \$from,
        public readonly string \$to,
        public readonly array  \$allowed,
    ) {
        parent::__construct(
            "Cannot transition from '\$from' to '\$to'. Allowed: [" . implode(', ', \$allowed) . "]."
        );
    }
}
PHP;
    }

    public function generateStatusChangedEvent(string $modelName): string
    {
        $variable = Str::camel($modelName);

        return <<<PHP
<?php

namespace App\Events;

use App\Models\\{$modelName};
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class {$modelName}StatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly {$modelName} \${$variable},
        public readonly string       \$from,
        public readonly string       \$to,
    ) {}
}
PHP;
    }

    /** Generate controller methods for each possible target state. */
    public function generateControllerMethods(string $modelName, string $statusColumn, array $enumValues): string
    {
        $variable    = Str::camel($modelName);
        $viewPrefix  = Str::kebab(Str::plural($modelName));
        $transitions = $this->inferTransitions($enumValues);
        $targets     = array_unique(array_merge(...array_values($transitions)));
        $methods     = '';

        foreach ($targets as $state) {
            $methodName = Str::camel($state); // e.g. publish, archive, restore

            $methods .= <<<PHP


    public function {$methodName}(\Illuminate\Http\Request \$request, {$modelName} \${$variable}): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        app(\App\StateMachines\\{$modelName}StateMachine::class, ['{$variable}' => \${$variable}])->transition('{$state}');

        if (\$request->wantsJson()) {
            return response()->json(['message' => '{$modelName} {$state}.', 'status' => \${$variable}->{$statusColumn}]);
        }
        return redirect()->route('{$viewPrefix}.index')->with('success', '{$modelName} {$state}.');
    }
PHP;
        }

        return $methods;
    }

    // ── Inference helpers ─────────────────────────────────────────────────────

    /** Infer sensible transitions from enum value names. */
    private function inferTransitions(array $values): array
    {
        $map = [];

        // Common patterns
        $patterns = [
            'draft'      => ['published', 'archived'],
            'pending'    => ['approved', 'rejected', 'cancelled'],
            'active'     => ['inactive', 'suspended', 'archived'],
            'published'  => ['draft', 'archived', 'unpublished'],
            'approved'   => ['rejected', 'cancelled'],
            'rejected'   => ['pending'],
            'inactive'   => ['active'],
            'suspended'  => ['active'],
            'archived'   => ['draft', 'active'],
            'unpublished' => ['published'],
            'cancelled'  => ['pending'],
            'processing' => ['completed', 'failed', 'cancelled'],
            'completed'  => ['refunded'],
            'failed'     => ['processing'],
            'open'       => ['closed', 'resolved'],
            'closed'     => ['open', 'reopened'],
            'resolved'   => ['open'],
            'new'        => ['in_progress', 'closed'],
            'in_progress' => ['completed', 'cancelled'],
        ];

        foreach ($values as $from) {
            $known = $patterns[$from] ?? [];
            // Filter to only transitions that exist in our enum values
            $allowed = array_values(array_intersect($known, $values));
            // If no known pattern, allow transition to all other states
            if (empty($allowed)) {
                $allowed = array_values(array_diff($values, [$from]));
            }
            $map[$from] = $allowed;
        }

        return $map;
    }

    private function buildTransitionMap(array $transitions): string
    {
        $lines = ['['];
        foreach ($transitions as $from => $tos) {
            $toList = implode("', '", $tos);
            $lines[] = "        '{$from}' => ['{$toList}'],";
        }
        $lines[] = '    ]';
        return implode("\n", $lines);
    }

    private function buildTransitionDocs(array $transitions): string
    {
        $lines = [];
        foreach ($transitions as $from => $tos) {
            $lines[] = " *   {$from} → " . implode(' | ', $tos);
        }
        return implode("\n", $lines);
    }

    private function buildTransitionMethods(string $modelName, string $statusColumn, array $transitions): string
    {
        $variable = Str::camel($modelName);
        $targets  = array_unique(array_merge(...array_values($transitions)));
        $methods  = '';

        foreach ($targets as $state) {
            $methodName = Str::camel($state);
            $methods .= <<<PHP

    public function {$methodName}(): void
    {
        \$this->transition('{$state}');
    }

PHP;
        }

        return $methods;
    }
}
