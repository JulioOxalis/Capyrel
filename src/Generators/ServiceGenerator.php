<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\UploadColumnDetector;

/**
 * Generates App\Services\{Model}Service.
 *
 * The service layer sits between controllers and the repository.
 * It owns business rules: file processing, event dispatching,
 * pivot sync, audit, and orchestration across multiple repositories.
 */
class ServiceGenerator
{
    public function generate(string $modelName, array $columns, array $relationships): string
    {
        $variable    = Str::camel($modelName);
        $variables   = Str::camel(Str::plural($modelName));
        $uploadCols  = UploadColumnDetector::collectFrom($columns);
        $hasBtm      = collect($relationships)->contains('type', 'belongsToMany');
        $hasSoft     = in_array('deleted_at', array_column($columns, 'name'));
        $hasAudit    = !empty(array_intersect(array_column($columns, 'name'), ['created_by','updated_by','deleted_by']));

        $uploadHandling = $this->buildUploadHandling($uploadCols);
        $pivotSync      = $this->buildPivotSync($relationships, $variable, $hasBtm);
        $softMethods    = $hasSoft ? $this->buildSoftMethods($modelName, $variable) : '';
        $auditNote      = $hasAudit ? "\n        // Audit columns (created_by/updated_by) auto-filled via Model::booted() hooks." : '';

        $useStorage = !empty($uploadCols) ? "\nuse Illuminate\\Support\\Facades\\Storage;" : '';
        $useStr     = !empty($uploadCols) ? "\nuse Illuminate\\Support\\Str;" : '';
        $useDb      = $hasBtm ? "\nuse Illuminate\\Support\\Facades\\DB;" : '';

        $disk = config('capyrel.storage_disk', 'public');

        return <<<PHP
<?php

namespace App\Services;

use App\Models\\{$modelName};
use App\Repositories\Contracts\\{$modelName}RepositoryInterface;
use Illuminate\Http\UploadedFile;{$useStorage}{$useStr}{$useDb}

class {$modelName}Service
{
    public function __construct(
        private readonly {$modelName}RepositoryInterface \$repository,
    ) {}

    public function create(array \$data, array \$files = [], array \$relations = []): {$modelName}
    {
{$auditNote}
{$uploadHandling}
        \${$variable} = \$this->repository->create(\$data);
{$pivotSync}
        event(new \App\Events\\{$modelName}Created(\${$variable}));

        return \${$variable};
    }

    public function update({$modelName} \${$variable}, array \$data, array \$files = [], array \$relations = []): {$modelName}
    {
{$this->buildUpdateUploadHandling($uploadCols, $variable)}
        \${$variable} = \$this->repository->update(\${$variable}, \$data);
{$pivotSync}
        event(new \App\Events\\{$modelName}Updated(\${$variable}));

        return \${$variable};
    }

    public function delete({$modelName} \${$variable}): bool
    {
        event(new \App\Events\\{$modelName}Deleted(\${$variable}));
{$this->buildDeleteFileHandling($uploadCols, $variable, $hasSoft)}
        return \$this->repository->delete(\${$variable});
    }

    public function paginate(array \$filters = [], int \$perPage = 15)
    {
        return \$this->repository->paginate(\$filters, \$perPage);
    }

    public function findOrFail(int|string \$id): {$modelName}
    {
        return \$this->repository->findOrFail(\$id);
    }
{$softMethods}}
PHP;
    }

    // ── Upload handling ───────────────────────────────────────────────────────

    private function buildUploadHandling(array $uploadCols): string
    {
        if (empty($uploadCols)) return '';

        $disk    = config('capyrel.storage_disk', 'public');
        $hasIv   = $this->hasInterventionImage();
        $maxW    = (int) config('capyrel.images.max_width', 1200);
        $maxH    = (int) config('capyrel.images.max_height', 1200);
        $quality = (int) config('capyrel.images.quality', 80);
        $lines   = ["        // Process uploaded files"];

        foreach ($uploadCols as $col) {
            $storagePath = UploadColumnDetector::storagePath($col);
            $isImage     = UploadColumnDetector::isImageColumn($col);

            $lines[] = "        if (isset(\$files['{$col}']) && \$files['{$col}'] instanceof UploadedFile) {";
            if ($isImage && $hasIv) {
                $lines[] = "            \$mgr = new \\Intervention\\Image\\ImageManager(new \\Intervention\\Image\\Drivers\\Gd\\Driver());";
                $lines[] = "            \$img = \$mgr->read(\$files['{$col}']->getPathname())->scaleDown({$maxW}, {$maxH});";
                $lines[] = "            \$path = '{$storagePath}/' . Str::uuid() . '.webp';";
                $lines[] = "            Storage::disk('{$disk}')->put(\$path, (string) \$img->toWebp({$quality}));";
                $lines[] = "            \$data['{$col}'] = \$path;";
            } else {
                $lines[] = "            \$data['{$col}'] = \$files['{$col}']->store('{$storagePath}', '{$disk}');";
            }
            $lines[] = "        }";
        }

        return implode("\n", $lines);
    }

    private function buildUpdateUploadHandling(array $uploadCols, string $variable): string
    {
        if (empty($uploadCols)) return '';

        $disk  = config('capyrel.storage_disk', 'public');
        $lines = ["        // Process uploaded files and delete replaced ones"];

        foreach ($uploadCols as $col) {
            $storagePath = UploadColumnDetector::storagePath($col);
            $isImage     = UploadColumnDetector::isImageColumn($col);
            $hasIv       = $this->hasInterventionImage();
            $maxW        = (int) config('capyrel.images.max_width', 1200);
            $maxH        = (int) config('capyrel.images.max_height', 1200);
            $quality     = (int) config('capyrel.images.quality', 80);

            $lines[] = "        if (isset(\$files['{$col}']) && \$files['{$col}'] instanceof UploadedFile) {";
            $lines[] = "            \$_old = \${$variable}->{$col};";
            if ($isImage && $hasIv) {
                $lines[] = "            \$mgr = new \\Intervention\\Image\\ImageManager(new \\Intervention\\Image\\Drivers\\Gd\\Driver());";
                $lines[] = "            \$img = \$mgr->read(\$files['{$col}']->getPathname())->scaleDown({$maxW}, {$maxH});";
                $lines[] = "            \$path = '{$storagePath}/' . Str::uuid() . '.webp';";
                $lines[] = "            Storage::disk('{$disk}')->put(\$path, (string) \$img->toWebp({$quality}));";
                $lines[] = "            \$data['{$col}'] = \$path;";
            } else {
                $lines[] = "            \$data['{$col}'] = \$files['{$col}']->store('{$storagePath}', '{$disk}');";
            }
            $lines[] = "            if (\$_old) Storage::disk('{$disk}')->delete(\$_old);";
            $lines[] = "        }";
        }

        return implode("\n", $lines);
    }

    private function buildDeleteFileHandling(array $uploadCols, string $variable, bool $hasSoft): string
    {
        if (empty($uploadCols)) return '';

        $disk  = config('capyrel.storage_disk', 'public');
        $lines = [];

        if ($hasSoft) {
            $lines[] = "        // Files preserved on soft-delete so record can be restored.";
            $lines[] = "        // Call forceDeleteFiles() before forceDelete() if permanent removal needed.";
        } else {
            foreach ($uploadCols as $col) {
                $lines[] = "        if (\${$variable}->{$col}) Storage::disk('{$disk}')->delete(\${$variable}->{$col});";
            }
        }

        return implode("\n", $lines);
    }

    private function buildPivotSync(array $relationships, string $variable, bool $hasBtm): string
    {
        if (!$hasBtm) return '';

        $btm   = collect($relationships)->where('type', 'belongsToMany');
        $lines = ["\n        // Sync many-to-many pivot relations"];

        if ($btm->isNotEmpty()) {
            $lines[] = "        DB::transaction(function () use (\${$variable}, \$relations) {";
            foreach ($btm as $rel) {
                $method = $rel['method'];
                $lines[] = "            if (array_key_exists('{$method}_ids', \$relations)) {";
                $lines[] = "                \${$variable}->{$method}()->sync(\$relations['{$method}_ids'] ?? []);";
                $lines[] = "            }";
            }
            $lines[] = "        });";
        }

        return implode("\n", $lines);
    }

    private function buildSoftMethods(string $modelName, string $variable): string
    {
        return <<<PHP


    public function restore(int|string \$id): {$modelName}
    {
        return \$this->repository->restore(\$id);
    }

    public function forceDelete({$modelName} \${$variable}): bool
    {
        return \$this->repository->forceDelete(\${$variable});
    }

PHP;
    }

    private function hasInterventionImage(): bool
    {
        $path = base_path('composer.json');
        if (!file_exists($path)) return false;
        $c    = json_decode(file_get_contents($path), true) ?? [];
        $deps = array_merge($c['require'] ?? [], $c['require-dev'] ?? []);
        return isset($deps['intervention/image']);
    }
}
