<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

class FactoryGenerator
{
    /** Column name → Faker call */
    private array $nameMap = [
        'name'           => 'fake()->name()',
        'first_name'     => 'fake()->firstName()',
        'last_name'      => 'fake()->lastName()',
        'full_name'      => 'fake()->name()',
        'username'       => 'fake()->unique()->userName()',
        'email'          => 'fake()->unique()->safeEmail()',
        'password'       => 'bcrypt(\'password\')',
        'phone'          => 'fake()->phoneNumber()',
        'phone_number'   => 'fake()->phoneNumber()',
        'mobile'         => 'fake()->phoneNumber()',
        'address'        => 'fake()->streetAddress()',
        'street'         => 'fake()->streetAddress()',
        'city'           => 'fake()->city()',
        'state'          => 'fake()->state()',
        'country'        => 'fake()->country()',
        'zip'            => 'fake()->postcode()',
        'postcode'       => 'fake()->postcode()',
        'postal_code'    => 'fake()->postcode()',
        'title'          => 'fake()->sentence(4)',
        'subject'        => 'fake()->sentence(4)',
        'headline'       => 'fake()->sentence(6)',
        'name'           => 'fake()->words(3, true)',
        'body'           => 'fake()->paragraphs(2, true)',
        'content'        => 'fake()->paragraphs(3, true)',
        'description'    => 'fake()->paragraph()',
        'summary'        => 'fake()->sentences(2, true)',
        'bio'            => 'fake()->paragraph()',
        'excerpt'        => 'fake()->sentences(2, true)',
        'notes'          => 'fake()->sentences(3, true)',
        'slug'           => 'fake()->unique()->slug()',
        'url'            => 'fake()->url()',
        'website'        => 'fake()->url()',
        'link'           => 'fake()->url()',
        'image'          => 'fake()->imageUrl(640, 480)',
        'avatar'         => 'fake()->imageUrl(200, 200)',
        'photo'          => 'fake()->imageUrl(800, 600)',
        'thumbnail'      => 'fake()->imageUrl(150, 150)',
        'price'          => 'fake()->randomFloat(2, 1, 999)',
        'amount'         => 'fake()->randomFloat(2, 1, 9999)',
        'total'          => 'fake()->randomFloat(2, 10, 9999)',
        'cost'           => 'fake()->randomFloat(2, 1, 500)',
        'salary'         => 'fake()->randomFloat(2, 1000, 100000)',
        'discount'       => 'fake()->randomFloat(2, 0, 100)',
        'tax'            => 'fake()->randomFloat(2, 0, 30)',
        'quantity'       => 'fake()->numberBetween(1, 100)',
        'stock'          => 'fake()->numberBetween(0, 1000)',
        'count'          => 'fake()->numberBetween(0, 500)',
        'views'          => 'fake()->numberBetween(0, 10000)',
        'likes'          => 'fake()->numberBetween(0, 5000)',
        'rating'         => 'fake()->randomFloat(1, 1, 5)',
        'score'          => 'fake()->numberBetween(0, 100)',
        'age'            => 'fake()->numberBetween(18, 80)',
        'year'           => 'fake()->year()',
        'color'          => 'fake()->hexColor()',
        'colour'         => 'fake()->hexColor()',
        'ip'             => 'fake()->ipv4()',
        'ip_address'     => 'fake()->ipv4()',
        'mac_address'    => 'fake()->macAddress()',
        'uuid'           => 'fake()->uuid()',
        'token'          => 'Str::random(64)',
        'code'           => 'Str::upper(Str::random(8))',
        'sku'            => 'strtoupper(fake()->bothify(\'??-####\'))',
        'lat'            => 'fake()->latitude()',
        'lng'            => 'fake()->longitude()',
        'latitude'       => 'fake()->latitude()',
        'longitude'      => 'fake()->longitude()',
        'status'         => 'fake()->randomElement([\'active\', \'inactive\', \'pending\'])',
        'type'           => 'fake()->randomElement([\'type_a\', \'type_b\', \'type_c\'])',
        'role'           => 'fake()->randomElement([\'admin\', \'editor\', \'viewer\'])',
        'gender'         => 'fake()->randomElement([\'male\', \'female\', \'other\'])',
        'language'       => 'fake()->languageCode()',
        'locale'         => 'fake()->locale()',
        'currency'       => 'fake()->currencyCode()',
        'company'        => 'fake()->company()',
        'job_title'      => 'fake()->jobTitle()',
        'department'     => 'fake()->word()',
        'category'       => 'fake()->word()',
        'tag'            => 'fake()->word()',
        'label'          => 'fake()->word()',
        'key'            => 'Str::random(32)',
        'secret'         => 'Str::random(64)',
        'api_key'        => 'Str::random(40)',
    ];

    public function generate(string $modelName, string $table, array $columns, array $relationships): string
    {
        $fields   = $this->buildFields($columns, $relationships, $modelName);
        $states   = $this->buildStates($columns);
        $hasStr   = collect($columns)->pluck('name')->contains(fn($n) => str_contains($n, 'slug') || str_contains($n, 'token'));

        $strUse = $hasStr ? "\nuse Illuminate\\Support\\Str;" : '';

        return <<<PHP
<?php

namespace Database\Factories;

use App\Models\\{$modelName};
use Illuminate\Database\Eloquent\Factories\Factory;{$strUse}

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\\{$modelName}>
 */
class {$modelName}Factory extends Factory
{
    protected \$model = {$modelName}::class;

    public function definition(): array
    {
        return [
{$fields}
        ];
    }
{$states}}
PHP;
    }

    private function buildFields(array $columns, array $relationships, string $modelName): string
    {
        $skip  = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at', 'two_factor_secret', 'two_factor_recovery_codes'];
        $lines = [];

        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array($name, $skip)) continue;

            $faker = $this->fakerFor($name, $col['type_name'] ?? 'string', $relationships);
            $lines[] = "            '{$name}' => {$faker},";
        }

        return implode("\n", $lines);
    }

    private function fakerFor(string $name, string $type, array $relationships): string
    {
        // FK column — use factory or random ID from related model
        if (str_ends_with($name, '_id') && $name !== 'id') {
            $guessedModel = Str::studly(Str::singular(Str::beforeLast($name, '_id')));
            $rel = collect($relationships)->firstWhere('related', $guessedModel);
            if ($rel) {
                return "\\App\\Models\\{$guessedModel}::factory()";
            }
            return "fake()->numberBetween(1, 10)";
        }

        // Exact column name match
        if (isset($this->nameMap[$name])) {
            return $this->nameMap[$name];
        }

        // Partial name match
        foreach ($this->nameMap as $keyword => $faker) {
            if (str_contains($name, $keyword)) return $faker;
        }

        // Type-based fallback
        return match (true) {
            str_contains($type, 'bool')                               => 'fake()->boolean()',
            in_array($type, ['int', 'integer', 'bigint', 'smallint']) => 'fake()->numberBetween(1, 1000)',
            in_array($type, ['decimal', 'float', 'double', 'numeric']) => 'fake()->randomFloat(2, 0, 1000)',
            in_array($type, ['date'])                                  => 'fake()->date()',
            in_array($type, ['datetime', 'timestamp'])                 => 'fake()->dateTimeBetween(\'-1 year\', \'now\')',
            in_array($type, ['json', 'jsonb'])                        => '[]',
            in_array($type, ['text', 'longtext', 'mediumtext'])       => 'fake()->paragraphs(2, true)',
            default                                                    => 'fake()->words(3, true)',
        };
    }

    private function buildStates(array $columns): string
    {
        $states = '';
        $names  = collect($columns)->pluck('name')->toArray();

        // Common state patterns
        if (in_array('deleted_at', $names)) {
            $states .= <<<PHP

    /** Indicate the model has been soft-deleted. */
    public function deleted(): static
    {
        return \$this->state(fn(array \$attr) => ['deleted_at' => now()]);
    }

PHP;
        }

        if (in_array('email_verified_at', $names)) {
            $states .= <<<PHP

    /** Indicate the model's email address is unverified. */
    public function unverified(): static
    {
        return \$this->state(fn(array \$attr) => ['email_verified_at' => null]);
    }

PHP;
        }

        if (in_array('status', $names)) {
            $states .= <<<PHP

    public function active(): static
    {
        return \$this->state(fn(array \$attr) => ['status' => 'active']);
    }

    public function inactive(): static
    {
        return \$this->state(fn(array \$attr) => ['status' => 'inactive']);
    }

PHP;
        }

        if (in_array('published_at', $names)) {
            $states .= <<<PHP

    public function published(): static
    {
        return \$this->state(fn(array \$attr) => ['published_at' => now()]);
    }

    public function draft(): static
    {
        return \$this->state(fn(array \$attr) => ['published_at' => null]);
    }

PHP;
        }

        return $states;
    }
}
