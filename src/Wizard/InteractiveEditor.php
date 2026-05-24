<?php

namespace Julio\Capyrel\Wizard;

use Illuminate\Console\Command;

/**
 * Reusable interactive field-editor for the capyrel:new wizard.
 *
 * Wraps Laravel's console ask/choice/table/confirm into a clean
 * review-and-edit loop that the command delegates to.
 */
class InteractiveEditor
{
    public function __construct(
        private ModelPlanBuilder $planBuilder,
    ) {}

    /**
     * Present the plan for a single entity and let the user review/edit it.
     * Loops until the user confirms or skips.
     *
     * @param  Command  $cmd      The calling command (for I/O)
     * @param  array    &$plan    The plan array (mutated in-place)
     * @return bool  true = confirmed, false = skip/discard
     */
    public function review(Command $cmd, array &$plan): bool
    {
        while (true) {
            $this->showPlan($cmd, $plan);

            $action = $cmd->choice(
                "  What would you like to do with <fg=cyan>{$plan['entity']}</>?",
                [
                    'confirm'  => 'Confirm — looks good',
                    'add'      => 'Add a field',
                    'remove'   => 'Remove a field',
                    'rename'   => 'Rename the model',
                    'toggle_sd'=> 'Toggle soft deletes',
                    'skip'     => 'Skip this model',
                ],
                'confirm',
            );

            match ($action) {
                'confirm'   => null,
                'add'       => $this->addField($cmd, $plan),
                'remove'    => $this->removeField($cmd, $plan),
                'rename'    => $this->renameModel($cmd, $plan),
                'toggle_sd' => $this->toggleSoftDeletes($cmd, $plan),
                'skip'      => (fn() => null)(),
            };

            if ($action === 'confirm') return true;
            if ($action === 'skip')    return false;
        }
    }

    // ── Display ───────────────────────────────────────────────────────────────

    private function showPlan(Command $cmd, array $plan): void
    {
        $cmd->line('');
        $cmd->line("  <fg=white;options=bold>{$plan['entity']}</> <fg=gray>→ {$plan['table']}</>");

        if (!empty($plan['soft_deletes'])) {
            $cmd->line('  <fg=gray>+ SoftDeletes</>');
        }

        if (!empty($plan['traits'])) {
            $cmd->line('  <fg=gray>Traits: ' . implode(', ', $plan['traits']) . '</>');
        }

        $cmd->line('');

        $rows = [];
        foreach ($plan['fields'] as $f) {
            $typeLabel = $f['type'];
            if ($typeLabel === 'foreignId') {
                $typeLabel = "foreignId → {$f['references']}";
            }
            if ($typeLabel === 'decimal' && isset($f['precision'])) {
                $typeLabel = "decimal({$f['precision']},{$f['scale']})";
            }

            $flags = [];
            if (!empty($f['nullable'])) $flags[] = 'nullable';
            if (!empty($f['unique']))   $flags[] = 'unique';
            if (array_key_exists('default', $f) && $f['default'] !== null) {
                $flags[] = 'default=' . json_encode($f['default']);
            }
            if (isset($f['hint'])) $flags[] = $f['hint'];

            $rows[] = [
                $f['name'],
                $typeLabel,
                implode(', ', $flags) ?: '—',
            ];
        }

        $cmd->table(
            ['Field', 'Type', 'Flags'],
            $rows,
        );

        if (!empty($plan['relations'])) {
            $cmd->line('  <fg=yellow>Relations:</>');
            foreach ($plan['relations'] as $rel) {
                $cmd->line("    <fg=gray>{$rel['type']}</> → <fg=cyan>{$rel['related']}</> <fg=gray>(via {$rel['foreign_key']})</>");
            }
            $cmd->line('');
        }
    }

    // ── Editing actions ───────────────────────────────────────────────────────

    private function addField(Command $cmd, array &$plan): void
    {
        $cmd->line('');
        $cmd->line('  <fg=gray>Enter a field spec: e.g.  title  /  price:decimal  /  user_id  /  is_active:boolean</>');
        $spec = $cmd->ask('  Field spec');

        if (empty(trim($spec ?? ''))) {
            $cmd->line('  <fg=gray>Nothing added.</>');
            return;
        }

        $this->planBuilder->addField($plan, trim($spec));
        $cmd->line('  <fg=green>✔</> Field added.');
    }

    private function removeField(Command $cmd, array &$plan): void
    {
        if (empty($plan['fields'])) {
            $cmd->line('  <fg=yellow>No fields to remove.</>');
            return;
        }

        $names = array_column($plan['fields'], 'name');
        $choice = $cmd->choice('  Which field to remove?', $names);
        $this->planBuilder->removeField($plan, $choice);
        $cmd->line("  <fg=green>✔</> Field <fg=cyan>{$choice}</> removed.");
    }

    private function renameModel(Command $cmd, array &$plan): void
    {
        $newName = $cmd->ask("  New model name", $plan['entity']);
        if (!empty($newName)) {
            $plan['entity'] = \Illuminate\Support\Str::studly($newName);
            $plan['table']  = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::plural($plan['entity']));
            $cmd->line("  <fg=green>✔</> Renamed to <fg=cyan>{$plan['entity']}</> (table: {$plan['table']}).");
        }
    }

    private function toggleSoftDeletes(Command $cmd, array &$plan): void
    {
        $plan['soft_deletes'] = !($plan['soft_deletes'] ?? false);
        $state = $plan['soft_deletes'] ? 'enabled' : 'disabled';
        $cmd->line("  <fg=green>✔</> Soft deletes {$state}.");
    }
}
