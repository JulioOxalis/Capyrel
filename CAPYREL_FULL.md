# julio/capyrel — Full Operations Guide

> How the package reads your database and turns it into a complete Laravel application.

---

## Table of Contents

1. [What Capyrel Does](#1-what-capyrel-does)
2. [How It Works Internally](#2-how-it-works-internally)
3. [Database Drivers](#3-database-drivers)
4. [Schema Analysis Pipeline](#4-schema-analysis-pipeline)
5. [Relationship Detection](#5-relationship-detection)
6. [Detectors](#6-detectors)
7. [Analyzers — 12 Health Checks](#7-analyzers--12-health-checks)
8. [Writers — What Gets Written](#8-writers--what-gets-written)
9. [Generators — Every Generator Explained](#9-generators--every-generator-explained)
10. [Commands — Every Command Explained](#10-commands--every-command-explained)
11. [The Full Stack Pipeline](#11-the-full-stack-pipeline)
12. [Blade UI — Modal-First Design](#12-blade-ui--modal-first-design)
13. [Auth Owner Stamping](#13-auth-owner-stamping)
14. [Configuration Reference](#14-configuration-reference)
15. [Global Flags](#15-global-flags)
16. [Workflows](#16-workflows)
17. [Removing Capyrel](#17-removing-capyrel)

---

## 1. What Capyrel Does

Capyrel connects to your database, reads every table, column, foreign key, index, and enum — then generates your entire Laravel codebase from that schema. No manual model mapping, no hand-written boilerplate.

**One command on a fresh database:**

```bash
php artisan capyrel:fullstack
```

**What you get:**

| Layer | Generated |
|---|---|
| Models | Relationships, `$fillable`, `$casts`, `$hidden`, scopes |
| Controllers | CRUD + search + JSON + pivot sync + auth owner check |
| Blade views | Modal-first index, create offcanvas, edit page, show page |
| Routes | `Route::resource()` + nested routes for dependent models |
| API Resources | `whenLoaded()` on all relations — N+1 structurally impossible |
| Form Requests | Column-typed validation rules, unique ignore-self on update |
| Factories | 60+ smart Faker mappings by column name and type |
| Policies | Ownership-aware, detects `user_id` / `owner_id` automatically |
| Seeders | FK-safe topological order — parents always before children |
| Enums | PHP 8.1 backed enums with `label()`, `color()`, `badge()` |
| Events + Observers | Created/Updated/Deleted events + Observer stubs |
| Livewire | Searchable table + live-validated form per model |
| Tests | Pest relationship type assertions + integration stubs |
| TypeScript | Interfaces + typed Axios service classes |
| OpenAPI | Full OpenAPI 3.1 YAML from schema + validation + routes |
| GraphQL | Lighthouse schema types, queries, mutations |
| Postman | Postman/Insomnia collection JSON |
| Webhooks | Subscription model + signing job + dispatchers |
| Broadcasting | ShouldBroadcast events + Echo channel classes |
| Notifications | Mail + database notifications per model lifecycle |
| Permissions | Permission matrix seeder + Spatie/custom policies |
| Architecture | Repository interface + Eloquent implementation + Service class |
| Health report | 12 analyzers — N+1, orphan FKs, missing indexes, more |

---

## 2. How It Works Internally

Every command follows the same pipeline:

```
1. Boot DriverFactory
       ↓
2. Load schema via SqlDriver or MongoDriver
       ↓
3. SchemaAnalyzer normalizes tables → columns → FKs → indexes
       ↓
4. RelationshipDetector maps FKs to all 8 Eloquent types
       ↓
5. ModelContextClassifier tags each model as standalone or dependent
       ↓
6. ColumnTypeDetector + UploadColumnDetector tag each column
       ↓
7. Generator(s) produce code strings
       ↓
8. Writer(s) write to disk (or --dry-run shows preview)
       ↓
9. DiagnosticsRunner fires all 12 health analyzers
       ↓
10. Output summary to console
```

No code is written until step 8. Every step before that is pure analysis — which is why `--dry-run` is a perfect preview with zero side effects.

---

## 3. Database Drivers

`DriverFactory` picks the right driver based on your `DB_CONNECTION` env or `--connection` flag.

### SqlDriver

Used for MySQL, PostgreSQL, SQLite.

Reads:
- `information_schema.COLUMNS` (MySQL) / `pg_catalog` (PostgreSQL) / `PRAGMA table_info` (SQLite)
- Foreign key constraints from `information_schema.KEY_COLUMN_USAGE` (MySQL) / `pg_constraint` (PostgreSQL) / `PRAGMA foreign_key_list` (SQLite)
- Indexes from `information_schema.STATISTICS` (MySQL) / `pg_indexes` (PostgreSQL) / `PRAGMA index_list` (SQLite)
- ENUM values parsed from column type strings

### MongoDriver

Used when `DB_CONNECTION=mongodb`.

Since MongoDB has no schema, it uses two strategies:

1. **Document sampling** — queries each collection and unions all field names across the first 100 documents
2. **Migration fallback** — if a collection is empty, reads `database/migrations/*.php` and parses `$table->` calls to derive field names

Relationships are detected by convention: a field named `user_id` in `posts` collection means `Post belongsTo User`.

---

## 4. Schema Analysis Pipeline

`SchemaAnalyzer` normalizes the raw driver output into a consistent structure:

```php
[
    'table'   => 'posts',
    'columns' => [
        ['name' => 'id',         'type' => 'bigint unsigned', 'type_name' => 'bigint', 'nullable' => false, 'length' => null],
        ['name' => 'title',      'type' => 'varchar(255)',    'type_name' => 'varchar', 'nullable' => false, 'length' => 255],
        ['name' => 'user_id',    'type' => 'bigint unsigned', 'type_name' => 'bigint',  'nullable' => false, 'length' => null],
        ['name' => 'deleted_at', 'type' => 'timestamp',       'type_name' => 'timestamp', 'nullable' => true, 'length' => null],
    ],
    'foreign_keys' => [
        ['foreign_key' => 'user_id', 'references_table' => 'users', 'references_column' => 'id'],
    ],
    'indexes' => [
        ['name' => 'posts_user_id_index', 'columns' => ['user_id'], 'unique' => false],
    ],
    'has_soft_deletes' => true,
]
```

This normalized form is what every generator receives — no generator talks to the DB directly.

---

## 5. Relationship Detection

`RelationshipDetector` maps the normalized schema to all 8 Eloquent relationship types:

| Detected pattern | Eloquent type |
|---|---|
| `posts.user_id` → `users.id` | `Post belongsTo User` + `User hasMany Post` |
| `profiles.user_id UNIQUE` | `Profile belongsTo User` + `User hasOne Profile` |
| `post_tag` pivot table | `Post belongsToMany Tag` + `Tag belongsToMany Post` |
| `comments.commentable_type` + `comments.commentable_id` | `Comment morphTo` + polymorphic `morphMany` on targets |
| `post_tag` with extra columns | `Post hasMany PostTag` + `PostTag belongsTo Post` |
| Through FK chain: `users → posts → comments` | `User hasManyThrough Comment via Post` |
| Through unique FK chain | `User hasOneThrough` |

Both sides of every relationship are registered — so `User hasMany Post` automatically generates `Post belongsTo User`. You never get a one-sided relationship.

---

## 6. Detectors

### ColumnTypeDetector

Classifies each column by name for smart form rendering:

| Method | Detects |
|---|---|
| `isColorColumn($name)` | `color`, `colour`, `hex_color`, `bg_color`, `text_color` |
| `isCoordinateColumn($name)` | `lat`, `lng`, `latitude`, `longitude` |
| `isSlugColumn($name)` | `slug`, `handle`, `permalink`, `url_key` |
| `findSlugSource($columns)` | Finds `title`, `name`, `label` as the source for auto-slug generation |

### UploadColumnDetector

Detects file/image columns by name convention:

| Method | Column names matched |
|---|---|
| `isUploadColumn($name)` | `avatar`, `photo`, `image`, `cover`, `banner`, `thumbnail`, `attachment`, `document`, `file`, `pdf`, `logo`, `picture`, `portrait`, `headshot`, `media`, `icon` |
| `isImageColumn($name)` | All of the above except `document`, `file`, `pdf`, `attachment` |
| `validationRules($name)` | Returns `'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'` for images or generic file rules for documents |

### FrameworkDetector

Detects which CSS framework the project uses:

- Checks `composer.json` and `package.json` for Tailwind CSS, Alpine.js, Bootstrap
- Returns `'tailwind'` or `'bootstrap'`
- All blade generators produce the right classes for the detected framework

### ModelContextClassifier

Tags each model as `standalone` or `dependent`:

- **Standalone**: no `belongsTo` relationships, or the model has its own primary purpose (e.g., `User`, `Post`, `Product`)
- **Dependent**: has `belongsTo` and is semantically a child (e.g., `Comment`, `OrderItem`, `Attachment`)

Dependent models get nested routes (`posts/{post}/comments`) and inline forms on the parent's show page — never a standalone CRUD page.

---

## 7. Analyzers — 12 Health Checks

`DiagnosticsRunner` fires all analyzers on every scaffold. Each returns a `Diagnostic` with severity (`error`, `warning`, `info`) and a message.

| Analyzer | What it finds |
|---|---|
| `N1QueryAnalyzer` | FK columns loaded without eager loading — would cause N+1 |
| `MissingIndexAnalyzer` | FK columns with no database index — full table scans |
| `OrphanForeignKeyAnalyzer` | `_id` columns referencing tables that don't exist |
| `InverseRelationshipAnalyzer` | One-sided relationships — A→B defined, B→A missing |
| `NamingConflictAnalyzer` | Method names Capyrel would generate that clash with existing model methods |
| `SoftDeleteAnalyzer` | Tables with `deleted_at` column where model lacks `SoftDeletes` trait |
| `EagerLoadDepthAnalyzer` | `hasManyThrough` chains 3+ levels deep — memory risk |
| `CircularRelationshipAnalyzer` | Self-referential models that would infinite-loop on eager load |
| `CascadeRiskAnalyzer` | FK constraints missing `ON DELETE CASCADE` where cascade is expected |
| `DeadRelationshipAnalyzer` | Relationships defined in models but never referenced in controllers or views |
| `SchemaFillableDriftAnalyzer` | `$fillable` lists columns that don't exist in the actual table |
| `MorphTypeRegistryAnalyzer` | `morphTo` relationships without `Relation::morphMap()` registered |

---

## 8. Writers — What Gets Written

Writers take a generated code string and write it to disk with smart merge logic.

### ModelWriter

- Injects relationship methods between `// capyrel:start` and `// capyrel:end` markers
- If markers don't exist, appends before the closing `}`
- Never overwrites manually written methods — only touches the marked region
- Adds `$fillable`, `$casts`, `$hidden`, scopes via `ModelEnhancer`

### ControllerWriter

- Writes a full controller PHP file from a template
- Includes CRUD methods: `index`, `store`, `show`, `update`, `destroy`
- Optionally includes: soft-delete restore/forceDelete, bulk destroy, JSON dual responses
- Stamps `Auth::id()` into `user_id` / `owner_id` / `created_by` — never exposes these as form fields
- Ownership check (`abort_if`) injected into `update()` and `destroy()`

### BladeWriter

- Writes `index.blade.php`, `create.blade.php`, `edit.blade.php`, `show.blade.php`
- Detects Bootstrap vs Tailwind and writes matching classes
- Generates modal-first UI (offcanvas create, slide-over view, delete modal)

### RouteWriter

- Appends `Route::resource()` calls to `routes/web.php`
- For dependent models: writes nested `Route::resource()` under the parent
- Detects Oxalis prefix and wraps in correct middleware group if installed
- Never duplicates — checks if route already exists before writing

### ModelEnhancer

- Adds missing `$fillable` from schema columns (excluding auth-owner, timestamps, PK)
- Adds `$casts` for datetime → `'datetime'`, boolean → `'boolean'`, json → `'array'`
- Adds `$hidden` for `password`, `remember_token`, `api_key`, `secret` columns
- Adds named scopes: `scopeActive()`, `scopePublished()`, `scopeSearch()`
- Adds `getFullNameAttribute()` when both `first_name` + `last_name` exist

---

## 9. Generators — Every Generator Explained

### RelationMethodGenerator
Produces the PHP method string for a single Eloquent relationship. All 8 types supported with correct return type hints.

### FullBladeGenerator
The most complex generator. Produces four complete blade files per model:
- `index` — modal-first with offcanvas create, slide-over view, delete modal, live search, `<x-capyrel-flash />`
- `create` — dedicated create page (standalone models only)
- `edit` — edit form with pre-populated fields
- `show` — read-only detail page with relationship panels

Every generated view includes `<x-capyrel-flash />` — Capyrel's zero-dependency toast component. It handles `session('success')`, `session('error')`, `session('warning')`, `session('info')`, the `window.CyToast()` JS API, and the `capyrel-toast` window event dispatched by all AJAX form handlers. No external libraries required.

Skips auth-owner columns (`user_id`, `owner_id`, `created_by`, `author_id`, `assigned_to`, `submitted_by`) from all forms — they are stamped server-side.

Renders smart inputs per column:
- Color columns → color picker + hex text input
- Upload columns → file input with accept filter
- Coordinate columns → number input with `step=any`
- Slug columns → auto-fill JS from title source (create mode)
- FK columns → `<select>` with loaded related records
- ENUM columns → `<select>` from parsed enum values
- Boolean/tinyint → checkbox
- Text/longtext → `<textarea>`
- Everything else → typed `<input>`

### ApiResourceGenerator
Produces `app/Http/Resources/ModelResource.php` with:
- All columns as typed fields
- All relationships wrapped in `whenLoaded()` — impossible to trigger N+1
- `$this->when()` for nullable fields
- Count fields for `hasMany` relations
- Meta block with `created_at`, `updated_at`

### FormRequestGenerator
Produces `StoreModelRequest` and `UpdateModelRequest` with:
- `authorize()` using policy if detected
- Column-typed rules: `required`/`nullable`, `string`/`integer`/`date`/`array`, `max:N`
- FK columns → `exists:table,id`
- Unique columns → `Rule::unique('table', 'column')` + ignore-self on Update
- Email columns → `email:rfc,dns`
- URL columns → `url`
- File/image columns → file validation rules from `UploadColumnDetector`
- ENUM columns → `in:val1,val2,...` from parsed enum values
- Auth-owner columns excluded entirely

### FactoryGenerator
Produces `database/factories/ModelFactory.php` with 60+ smart mappings:

| Column name pattern | Faker call |
|---|---|
| `email` | `fake()->unique()->safeEmail()` |
| `name`, `full_name` | `fake()->name()` |
| `first_name` | `fake()->firstName()` |
| `last_name` | `fake()->lastName()` |
| `password` | `bcrypt('password')` |
| `title` | `fake()->sentence(4)` |
| `body`, `content`, `description` | `fake()->paragraphs(2, true)` |
| `slug` | `fake()->unique()->slug()` |
| `price`, `amount`, `cost`, `total` | `fake()->randomFloat(2, 1, 999)` |
| `status` | `fake()->randomElement(['active','inactive','pending'])` |
| `phone`, `mobile` | `fake()->phoneNumber()` |
| `address` | `fake()->address()` |
| `city` | `fake()->city()` |
| `country` | `fake()->country()` |
| `zip`, `postal_code` | `fake()->postcode()` |
| `lat`, `latitude` | `fake()->latitude()` |
| `lng`, `longitude` | `fake()->longitude()` |
| `url`, `website` | `fake()->url()` |
| `ip`, `ip_address` | `fake()->ipv4()` |
| `color`, `colour` | `fake()->hexColor()` |
| `uuid` | `fake()->uuid()` |
| `*_id` FK | `RelatedModel::factory()` |

Generated states: `->deleted()`, `->unverified()`, `->active()`, `->published()`

### PolicyGenerator
Produces `app/Policies/ModelPolicy.php` with all Gate methods:
- `viewAny`, `view`, `create` — standard
- `update`, `delete`, `restore`, `forceDelete` — ownership-checked
- Detects `user_id`, `author_id`, `owner_id`, `created_by` as the ownership column
- Falls back to admin check if no ownership column found

### SeederGenerator
Produces `database/seeders/ModelSeeder.php` + updates `DatabaseSeeder.php`:
- Topological sort of all models by FK dependency graph
- Parents always seeded before children
- Default 10 records per model (override with `--count=N`)
- Calls `ModelFactory::new()->count(N)->create()`

### EnumGenerator
Produces `app/Enums/ModelStatus.php` (and similar for each status/type column):
```php
enum PostStatus: string {
    case Active   = 'active';
    case Draft    = 'draft';
    case Archived = 'archived';
    public function label(): string { ... }   // human-readable
    public function color(): string { ... }   // green / gray / yellow / red
    public function badge(): string { ... }   // "badge bg-success"
    public static function options(): array { ... }  // for <select>
}
```

### EventGenerator
Produces per model:
- `app/Events/ModelCreated.php`, `ModelUpdated.php`, `ModelDeleted.php`
- `app/Observers/ModelObserver.php` with lifecycle stubs
- `capyrel-observer-registrations.php` — copy into `AppServiceProvider::boot()`

### LivewireGenerator
Produces per model:
- `app/Livewire/ModelTable.php` — `wire:model.live` search, sortable columns, paginated
- `resources/views/livewire/model-table.blade.php`
- `app/Livewire/ModelForm.php` — create/edit with live validation
- `resources/views/livewire/model-form.blade.php`
- Installs Livewire automatically if not found in `composer.json`

### RelationshipTestGenerator
Produces `tests/Models/ModelTest.php`:
- Instant type assertions (no DB): `expect((new User)->posts())->toBeInstanceOf(HasMany::class)`
- Integration tests (factory + DB) marked `->skip()` — remove skip to enable

### FeatureTestGenerator
Produces `tests/Feature/ModelTest.php` with HTTP tests:
- `GET /models` returns 200
- `POST /models` with valid data creates record
- `POST /models` with missing required field returns 422
- `PUT /models/{id}` updates record
- `DELETE /models/{id}` deletes record

### RepositoryGenerator
Produces Repository pattern per model:
- `app/Contracts/ModelRepositoryInterface.php` — interface
- `app/Repositories/EloquentModelRepository.php` — Eloquent implementation
- Bind in `AppServiceProvider`: `$this->app->bind(ModelRepositoryInterface::class, EloquentModelRepository::class)`

### ServiceGenerator
Produces `app/Services/ModelService.php`:
- Wraps repository calls in `DB::transaction()` where needed
- Business logic layer between controller and repository
- Dispatches events on create/update/delete

### DtoGenerator
Produces `app/DTOs/ModelData.php`:
- Typed value object from validated request data
- `static::from(Request $request): self`
- All columns as typed readonly properties

### TypeScriptGenerator
Produces `resources/ts/models/Model.ts`:
- TypeScript interface matching DB schema
- PHP type → TypeScript type mapping
- `ModelPayload` type for create/update requests

### AxiosClientGenerator
Produces `resources/ts/services/ModelService.ts`:
- Typed Axios service class
- `index()`, `show(id)`, `store(payload)`, `update(id, payload)`, `destroy(id)`
- Uses generated TypeScript interfaces

### OpenApiGenerator
Produces `openapi.yaml`:
- OpenAPI 3.1 specification
- Schemas from DB columns + types
- Paths from detected routes
- Request bodies from validation rules
- Response schemas from API resources

### GraphQLGenerator
Produces `graphql/schema.graphql` (Lighthouse PHP):
- `type Model { field: Type }` from columns
- `Query { models: [Model!]! @paginate, model(id: ID!): Model @find }`
- `Mutation { createModel(...): Model @create, updateModel(...): Model @update, deleteModel(id: ID!): Model @delete }`

### PostmanGenerator
Produces `capyrel-postman-collection.json`:
- One folder per model
- Requests for all CRUD endpoints
- Pre-filled example bodies from schema
- Environment variables for `base_url` and `token`

### BroadcastingGenerator
Produces per model:
- `app/Events/ModelUpdatedEvent.php` implementing `ShouldBroadcast`
- `app/Broadcasting/ModelChannel.php`
- Laravel Echo subscription snippet

### WebhookGenerator
Produces:
- `app/Models/WebhookSubscription.php` — subscriber model
- `app/Jobs/DispatchWebhook.php` — signed delivery job
- `app/Http/Controllers/WebhookController.php` — subscription CRUD

### NotificationGenerator
Produces per model:
- `app/Notifications/ModelCreatedNotification.php` — mail + database channels
- `app/Notifications/ModelUpdatedNotification.php`
- Markdown mail templates

### HealthCheckGenerator
Produces:
- `app/Http/Controllers/HealthCheckController.php` — `/health` endpoint
- Checks: DB connectivity, cache, queue, storage disk
- Returns JSON with `status: ok|degraded|down` per service

### PermissionMatrixGenerator
Produces:
- `database/seeders/PermissionSeeder.php` — creates permissions for every model × action
- Spatie permissions if installed, custom gate definitions otherwise
- `model.viewAny`, `model.view`, `model.create`, `model.update`, `model.delete` per model

### StateMachineGenerator
Produces:
- `app/StateMachines/ModelStateMachine.php` — transitions map with guards
- `app/Exceptions/InvalidTransitionException.php`
- Controller methods for triggering transitions

### ArtisanCommandGenerator
Produces per model:
- `app/Console/Commands/ModelListCommand.php` — `php artisan model:list`
- `app/Console/Commands/ModelCreateCommand.php` — `php artisan model:create`
- `app/Console/Commands/ModelDeleteCommand.php` — `php artisan model:delete`
- `app/Console/Commands/ModelStatsCommand.php` — `php artisan model:stats`

---

## 10. Commands — Every Command Explained

### `model:scaffold`
The core command. Runs the full pipeline (schema → detect → classify → generate → write) for models, controllers, blade, and routes.

```bash
php artisan model:scaffold                    # all models, interactive
php artisan model:scaffold User               # one model
php artisan model:scaffold --dry-run          # preview only
php artisan model:scaffold --models           # models only
php artisan model:scaffold --controllers      # controllers only
php artisan model:scaffold --views            # blade only
php artisan model:scaffold --routes           # routes only
php artisan model:scaffold --force
php artisan model:scaffold --connection=pgsql
```

### `model:map`
Prints a visual diagram of all models and relationships.

```bash
php artisan model:map                              # ASCII tree
php artisan model:map --format=mermaid             # Mermaid.js erDiagram
php artisan model:map --format=both
php artisan model:map --save=docs/schema.md
```

### `model:resources`
Generates API Resource classes. Uses `whenLoaded()` everywhere — N+1 impossible.

```bash
php artisan model:resources
php artisan model:resources User
php artisan model:resources --dry-run
php artisan model:resources --force
```

### `model:requests`
Generates StoreModelRequest and UpdateModelRequest with column-derived validation.

```bash
php artisan model:requests
php artisan model:requests Post
php artisan model:requests --dry-run
php artisan model:requests --force
```

### `model:tests`
Generates Pest relationship type tests.

```bash
php artisan model:tests
php artisan model:tests User
php artisan model:tests --dry-run
php artisan model:tests --force
```

### `model:factory`
Generates factories with 60+ smart Faker mappings.

```bash
php artisan model:factory
php artisan model:factory User
php artisan model:factory --dry-run
php artisan model:factory --force
```

### `model:policy`
Generates ownership-aware Policy classes.

```bash
php artisan model:policy
php artisan model:policy Post
php artisan model:policy --dry-run
php artisan model:policy --force
```

### `model:seed`
Generates seeders in FK-correct topological order.

```bash
php artisan model:seed
php artisan model:seed User
php artisan model:seed --count=20
php artisan model:seed --dry-run
php artisan model:seed --force
php artisan model:seed --no-database    # skip updating DatabaseSeeder
```

### `model:optimize`
Adds missing `$fillable`, `$casts`, `$hidden`, and named scopes to existing models.

```bash
php artisan model:optimize
php artisan model:optimize User
php artisan model:optimize --dry-run
php artisan model:optimize --force
```

### `model:livewire`
Generates Livewire table + form components. Installs Livewire if missing.

```bash
php artisan model:livewire
php artisan model:livewire User
php artisan model:livewire --dry-run
php artisan model:livewire --force
```

### `model:enum`
Generates PHP 8.1 backed Enums for status/type/role columns.

```bash
php artisan model:enum
php artisan model:enum Post
php artisan model:enum --dry-run
php artisan model:enum --force
```

### `model:events`
Generates Event classes and Observer stubs.

```bash
php artisan model:events
php artisan model:events Post
php artisan model:events --dry-run
php artisan model:events --force
```

### `model:architecture`
Generates Repository interface + implementation + Service class.

```bash
php artisan model:architecture
php artisan model:architecture User
php artisan model:architecture --dry-run
php artisan model:architecture --force
```

### `model:broadcast`
Generates ShouldBroadcast events + Echo channel classes.

```bash
php artisan model:broadcast
php artisan model:broadcast Post
php artisan model:broadcast --dry-run
php artisan model:broadcast --force
```

### `model:notifications`
Generates mail + database Notification classes per model lifecycle.

```bash
php artisan model:notifications
php artisan model:notifications Post
php artisan model:notifications --dry-run
php artisan model:notifications --force
```

### `model:health-check`
Generates a `/health` endpoint controller.

```bash
php artisan model:health-check
php artisan model:health-check --dry-run
```

### `model:permissions`
Generates Spatie/custom permission matrix seeder + policies.

```bash
php artisan model:permissions
php artisan model:permissions --dry-run
php artisan model:permissions --force
```

### `model:state-machine`
Generates state machine with transitions, guards, and InvalidTransitionException.

```bash
php artisan model:state-machine
php artisan model:state-machine Order
php artisan model:state-machine --dry-run
php artisan model:state-machine --force
```

### `model:prune`
Removes scaffolded code for models that no longer exist in the DB.

```bash
php artisan model:prune
php artisan model:prune --dry-run
php artisan model:prune --force
```

### `model:watch`
Watches migrations folder and auto-injects new relationships on file change.

```bash
php artisan model:watch
php artisan model:watch --interval=5
php artisan model:watch --connection=mysql
```

### `migrate:safe`
Scans pending migrations for destructive patterns before running them.

```bash
php artisan migrate:safe           # scan + confirm + run
php artisan migrate:safe --check   # scan only, never runs
php artisan migrate:safe --force
```

### `capyrel:fullstack`
Runs all 12 scaffold steps in the correct order.

```bash
php artisan capyrel:fullstack
php artisan capyrel:fullstack --dry-run
php artisan capyrel:fullstack --force
php artisan capyrel:fullstack --skip=livewire,events
php artisan capyrel:fullstack --connection=pgsql
```

### `capyrel:audit`
Generates a full health report and saves `CAPYREL_AUDIT.md`.

```bash
php artisan capyrel:audit
php artisan capyrel:audit --output=docs/audit.md
php artisan capyrel:audit --json
php artisan capyrel:audit --ci       # exit non-zero on errors (for CI/CD)
```

### `capyrel:clean`
Removes all code Capyrel generated. Run before `composer remove julio/capyrel`.

```bash
php artisan capyrel:clean              # interactive
php artisan capyrel:clean --dry-run
php artisan capyrel:clean --force
php artisan capyrel:clean --models
php artisan capyrel:clean --controllers
php artisan capyrel:clean --views
php artisan capyrel:clean --routes
```

### `capyrel:openapi`
Generates OpenAPI 3.1 YAML specification.

```bash
php artisan capyrel:openapi
php artisan capyrel:openapi --output=public/openapi.yaml
php artisan capyrel:openapi --dry-run
```

### `capyrel:graphql`
Generates Lighthouse GraphQL schema.

```bash
php artisan capyrel:graphql
php artisan capyrel:graphql --dry-run
php artisan capyrel:graphql --force
```

### `capyrel:postman`
Generates Postman/Insomnia collection JSON.

```bash
php artisan capyrel:postman
php artisan capyrel:postman --output=capyrel-postman.json
php artisan capyrel:postman --dry-run
```

### `capyrel:typescript`
Generates TypeScript interfaces and typed Axios service classes.

```bash
php artisan capyrel:typescript
php artisan capyrel:typescript --output=resources/ts
php artisan capyrel:typescript --dry-run
```

### `capyrel:webhooks`
Generates outbound webhook infrastructure.

```bash
php artisan capyrel:webhooks
php artisan capyrel:webhooks --dry-run
php artisan capyrel:webhooks --force
```

### `capyrel:permissions`
Generates permission matrix (alias of `model:permissions` — runs for all models at once).

```bash
php artisan capyrel:permissions
php artisan capyrel:permissions --dry-run
```

### `capyrel:stubs`
Publishes Capyrel generator stubs to `stubs/capyrel/` so you can customize output.

```bash
php artisan capyrel:stubs
```

### `capyrel:demo`
Builds a temporary SQLite DB, seeds example tables, runs dry-run scaffold, cleans up.

```bash
php artisan capyrel:demo
```

### `capyrel:install-extension`
Installs the pre-built VS Code extension (`.vsix` ships inside the package).

```bash
php artisan capyrel:install-extension
```

---

## 11. The Full Stack Pipeline

`capyrel:fullstack` runs everything in this exact order:

```
Step 1  — model:scaffold       models + controllers + blade + routes
Step 2  — model:resources      API resource classes
Step 3  — model:requests       form request classes
Step 4  — model:factory        Faker factories
Step 5  — model:policy         authorization policies
Step 6  — model:seed           seeders in FK-safe order
Step 7  — model:optimize       fillable + casts + scopes
Step 8  — model:enum           PHP 8.1 enums
Step 9  — model:events         events + observers
Step 10 — model:tests          Pest relationship tests
Step 11 — model:livewire       Livewire components (skipped if not installed)
Step 12 — capyrel:audit        full health report
```

Skip specific steps: `--skip=livewire,events,enum`

---

## 12. Blade UI — Modal-First Design

Every generated index page uses a modal-first layout — no full page loads for create/view/delete.

### Index page elements

| Element | What it does |
|---|---|
| **New button** | Opens right-side offcanvas with the create form |
| **Search bar** | Live client-side filter — no server request |
| **Row hover** | View / Edit / Delete action icons appear |
| **View icon** | Opens a slide-over panel populated from JSON — zero server round-trip |
| **Edit icon** | Navigates to dedicated edit page |
| **Delete icon** | Opens delete confirmation modal with record name |
| **Delete confirm** | Optionally requires typing the name (`CAPYREL_DELETE_TYPING=true`) |
| **Form submit** | Button disables + spinner + "Creating..." text during submission |
| **Toast** | `<x-capyrel-flash />` — slide-in toast with progress bar, 4 types (success/error/warning/info), dark-mode aware, close button. Handles session flashes + `window.CyToast()` API + `capyrel-toast` window events |
| **Unsaved warning** | Warns before closing modal with dirty form (`CAPYREL_UNSAVED_WARNING=true`) |

### Standalone vs dependent models

**Standalone** (`User`, `Post`, `Product`) — full CRUD pages: index, create, edit, show.

**Dependent** (`Comment`, `OrderItem`, `Attachment`) — no standalone CRUD pages:
- Form appears inline on the parent's show page
- Routes are nested: `posts/{post}/comments`
- Controller receives parent model via route model binding

---

## 13. Auth Owner Stamping

Columns named `user_id`, `owner_id`, `created_by`, `author_id`, `assigned_to`, or `submitted_by` are treated as auth-owner columns across the entire generation pipeline:

| Layer | Behaviour |
|---|---|
| **Blade form** | Column is **never rendered** as an input field |
| **Validation rules** | Column is **excluded** from all `$request->validate()` rules |
| **Controller store()** | `$validated['user_id'] = $request->user()->id` stamped automatically |
| **Controller update()** | `abort_if($record->user_id !== $request->user()->id, 403)` |
| **Controller destroy()** | `abort_if($record->user_id !== $request->user()->id, 403)` |
| **Policy** | `update()`/`delete()` check ownership against `$user->id` |

This means a user can never assign records to another user, and can never modify or delete records they don't own — enforced at every layer.

If a `team_id` / `tenant_id` / `organization_id` / `org_id` / `account_id` column is present, multitenancy scoping takes priority: index is scoped to current tenant, store stamps the tenant column, update/destroy checks tenant match.

---

## 14. Configuration Reference

Publish the config file:

```bash
php artisan vendor:publish --tag=capyrel-config
```

File: `config/capyrel.php`

| Key | Default | What it controls |
|---|---|---|
| `storage_disk` | `env('FILESYSTEM_DISK', 'public')` | Disk for uploaded files |
| `images.max_width` | `1200` | Max width for uploaded images (requires `intervention/image`) |
| `images.max_height` | `1200` | Max height |
| `images.quality` | `80` | Compression quality |
| `images.format` | `'webp'` | Output format: `webp`, `jpg`, `original` |
| `images.max_kb` | `5120` | Max upload size in KB |
| `pagination.per_page` | `15` | Rows per page |
| `pagination.type` | `'paginate'` | `paginate` / `cursor` / `simple` |
| `features.transaction_pivots` | `true` | Wrap BTM syncs in `DB::transaction()` |
| `features.json_responses` | `true` | Add `wantsJson()` dual responses |
| `features.search` | `true` | Generate search block in `index()` |
| `features.soft_deletes` | `true` | Generate restore/forceDelete methods |
| `modals.delete_typing` | `true` | Require typing name before delete confirm |
| `modals.unsaved_warning` | `true` | Warn on modal close with dirty form |
| `spatie.media_library` | `true` | Auto-detect spatie/laravel-medialibrary |
| `spatie.permissions` | `true` | Auto-detect spatie/laravel-permission |
| `scout.enabled` | `true` | Auto-detect laravel/scout |

Environment variable overrides:

```env
CAPYREL_STORAGE_DISK=s3
CAPYREL_PER_PAGE=25
CAPYREL_PAGINATION=cursor
CAPYREL_DELETE_TYPING=false
CAPYREL_UNSAVED_WARNING=false
CAPYREL_SCOUT=false
```

---

## 15. Global Flags

Every command accepts these:

```bash
--connection=mysql     # use a specific DB connection
--connection=pgsql
--connection=sqlite
--connection=mongodb

--dry-run              # preview all output, write nothing to disk
--force                # skip all yes/no confirmation prompts
```

---

## 16. Workflows

### New project — zero to full stack

```bash
php artisan migrate                  # run your migrations first
php artisan capyrel:fullstack        # everything in one command
php artisan db:seed                  # seed with generated factories
```

### Existing project — incremental

```bash
php artisan model:scaffold --dry-run      # preview first
php artisan model:scaffold --models       # add relationships to existing models
php artisan model:scaffold --controllers  # generate controllers only
php artisan model:scaffold --views        # generate blade only
php artisan model:scaffold --routes       # append routes only
php artisan model:factory                 # add factories
php artisan model:policy                  # add ownership policies
php artisan model:tests                   # add relationship tests
php artisan model:map --format=mermaid --save=docs/schema.md
php artisan capyrel:audit
```

### Add a new model (after migration)

```bash
php artisan model:scaffold NewModel
php artisan model:factory NewModel
php artisan model:policy NewModel
php artisan model:tests NewModel
```

### CI/CD health gate

```bash
php artisan capyrel:audit --ci     # exits non-zero if errors found
```

Add to GitHub Actions to block merges when schema health errors exist.

### Safe migration workflow

```bash
php artisan migrate:safe --check   # scan without running
php artisan migrate:safe           # scan + confirm + run
```

### Customize generator output

```bash
php artisan capyrel:stubs          # publishes stubs to stubs/capyrel/
# edit stubs/capyrel/controller.stub, model.stub, blade-index.stub ...
php artisan model:scaffold         # now uses your customized stubs
```

---

## 17. Removing Capyrel

**Always clean before removing** — once the package is gone, the clean command is gone too.

```bash
php artisan capyrel:clean --dry-run   # see what will be removed
php artisan capyrel:clean --force     # remove all generated code
composer remove julio/capyrel         # remove the package
```

To remove only specific layers:

```bash
php artisan capyrel:clean --views       # remove blade files only
php artisan capyrel:clean --routes      # undo route entries only
php artisan capyrel:clean --models      # remove injected model methods only
php artisan capyrel:clean --controllers # remove generated controllers only
```
