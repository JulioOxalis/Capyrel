<?php

namespace Julio\Capyrel\Generators;

use Julio\Capyrel\Detectors\UploadColumnDetector;

/**
 * Generates Pest HTTP feature tests for CRUD controllers.
 * Covers: index, store (valid + invalid), show, update, destroy.
 * File upload columns get UploadedFile::fake() assertions.
 */
class FeatureTestGenerator
{
    public function generate(string $modelName, array $columns, array $relationships = []): string
    {
        $route       = Str::kebab(Str::plural($modelName));
        $variable    = Str::camel($modelName);
        $hasFactory  = $this->factoryExists($modelName);
        $uploadCols  = UploadColumnDetector::collectFrom($columns);
        $hasUploads  = !empty($uploadCols);

        $useStorage  = $hasUploads ? "\nuse Illuminate\\Support\\Facades\\Storage;" : '';
        $useFakeFile = $hasUploads ? "\nuse Illuminate\\Http\\UploadedFile;" : '';

        $storePayload  = $this->buildPayload($columns, $relationships, 'store');
        $updatePayload = $this->buildPayload($columns, $relationships, 'update');
        $invalidField  = $this->firstRequiredField($columns);

        $storeFakeFiles  = $this->buildFakeFiles($uploadCols, 'store');
        $updateFakeFiles = $this->buildFakeFiles($uploadCols, 'update');
        $storageFake     = $hasUploads ? "\n    Storage::fake('public');" : '';

        $modelCreate = $hasFactory
            ? "{$modelName}::factory()->create()"
            : "/* {$modelName}::factory()->create() — add factory first */";

        $authLine = "actingAs(\$user = \\App\\Models\\User::factory()->create())";

        return <<<PHP
<?php

use App\Models\\{$modelName};
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;{$useStorage}{$useFakeFile}

uses(RefreshDatabase::class);

// ── Index ─────────────────────────────────────────────────────────────────────

it('renders the {$modelName} index page', function () {
    \$this->{$authLine}
         ->get(route('{$route}.index'))
         ->assertOk();
});

it('returns JSON for {$modelName} index when requested', function () {
    {$modelCreate};
    \$this->{$authLine}
         ->getJson(route('{$route}.index'))
         ->assertOk()
         ->assertJsonStructure(['data']);
});

// ── Store ─────────────────────────────────────────────────────────────────────

it('creates a {$modelName} via JSON', function () {
{$storageFake}
    \$payload = [{$storePayload}
    ];
{$storeFakeFiles}
    \$this->{$authLine}
         ->postJson(route('{$route}.store'), \$payload)
         ->assertCreated()
         ->assertJsonFragment(['message' => '{$modelName} created successfully.']);

    expect({$modelName}::count())->toBe(1);
});

it('validates required fields on store', function () {
    \$this->{$authLine}
         ->postJson(route('{$route}.store'), [])
         ->assertUnprocessable()
         ->assertJsonValidationErrors([{$invalidField}]);
});

// ── Show ──────────────────────────────────────────────────────────────────────

it('returns {$modelName} JSON for show', function () {
    \${$variable} = {$modelCreate};

    \$this->{$authLine}
         ->getJson(route('{$route}.show', \${$variable}))
         ->assertOk()
         ->assertJsonFragment(['id' => \${$variable}->id]);
});

it('returns 404 for missing {$modelName}', function () {
    \$this->{$authLine}
         ->getJson(route('{$route}.show', 99999))
         ->assertNotFound();
});

// ── Update ────────────────────────────────────────────────────────────────────

it('updates a {$modelName} via JSON', function () {
{$storageFake}
    \${$variable} = {$modelCreate};
    \$payload = [{$updatePayload}
    ];
{$updateFakeFiles}
    \$this->{$authLine}
         ->putJson(route('{$route}.update', \${$variable}), \$payload)
         ->assertOk()
         ->assertJsonFragment(['message' => '{$modelName} updated successfully.']);
});

// ── Destroy ───────────────────────────────────────────────────────────────────

it('deletes a {$modelName}', function () {
    \${$variable} = {$modelCreate};

    \$this->{$authLine}
         ->deleteJson(route('{$route}.destroy', \${$variable}))
         ->assertOk()
         ->assertJsonFragment(['message' => '{$modelName} deleted.']);

    expect({$modelName}::count())->toBe(0);
});

it('returns 404 when deleting a missing {$modelName}', function () {
    \$this->{$authLine}
         ->deleteJson(route('{$route}.destroy', 99999))
         ->assertNotFound();
});
PHP;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildPayload(array $columns, array $relationships, string $mode): string
    {
        $skip = ['id', '_id', 'created_at', 'updated_at', 'deleted_at',
                 'remember_token', 'email_verified_at', 'two_factor_secret'];
        $lines = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;
            if (UploadColumnDetector::isUploadColumn($name)) continue;

            $faker = $this->fakerFor($name, $col['type_name'] ?? 'string', $relationships);
            $lines[] = "\n        '{$name}' => {$faker},";
        }

        return implode('', $lines);
    }

    private function buildFakeFiles(array $uploadCols, string $mode): string
    {
        if (empty($uploadCols)) return '';

        $lines = [''];
        foreach ($uploadCols as $col) {
            $isImage = UploadColumnDetector::isImageColumn($col);
            if ($isImage) {
                $lines[] = "    \$payload['{$col}'] = UploadedFile::fake()->image('{$col}.jpg');";
            } else {
                $lines[] = "    \$payload['{$col}'] = UploadedFile::fake()->create('{$col}.pdf', 100, 'application/pdf');";
            }
        }
        return implode("\n", $lines) . "\n";
    }

    private function firstRequiredField(array $columns): string
    {
        $skip = ['id', '_id', 'created_at', 'updated_at', 'deleted_at',
                 'remember_token', 'email_verified_at', 'password'];
        foreach ($columns as $col) {
            if (in_array($col['name'], $skip)) continue;
            if (!($col['nullable'] ?? false) && !UploadColumnDetector::isUploadColumn($col['name'])) {
                return "'{$col['name']}'";
            }
        }
        return "'id'";
    }

    private function fakerFor(string $name, string $type, array $relationships): string
    {
        if (str_ends_with($name, '_id') && $name !== 'id') {
            return '1';
        }
        if (str_contains($name, 'email')) return 'fake()->safeEmail()';
        if ($name === 'password')          return "'Password1!'";
        if (str_contains($name, 'name'))  return 'fake()->name()';
        if (str_contains($name, 'title')) return 'fake()->sentence(4)';
        if (str_contains($name, 'body') || str_contains($name, 'content') || str_contains($name, 'description')) {
            return 'fake()->paragraph()';
        }
        if (str_contains($name, 'url') || str_contains($name, 'website')) return 'fake()->url()';
        if (str_contains($name, 'phone')) return 'fake()->phoneNumber()';
        if (str_contains($name, 'slug'))  return 'fake()->slug()';

        return match (true) {
            in_array($type, ['int', 'integer', 'bigint', 'smallint']) => 'fake()->numberBetween(1, 100)',
            in_array($type, ['decimal', 'float', 'double', 'numeric']) => 'fake()->randomFloat(2, 1, 100)',
            in_array($type, ['boolean', 'bool', 'tinyint'])           => 'true',
            in_array($type, ['date'])                                   => 'fake()->date()',
            in_array($type, ['datetime', 'timestamp'])                  => 'now()->toDateTimeString()',
            in_array($type, ['text', 'longtext', 'mediumtext'])        => 'fake()->paragraph()',
            default                                                     => 'fake()->words(3, true)',
        };
    }

    private function factoryExists(string $modelName): bool
    {
        return file_exists(database_path("factories/{$modelName}Factory.php"));
    }
}
