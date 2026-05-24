<?php

namespace Julio\Capyrel;

use Illuminate\Support\ServiceProvider;

// ── Analyzers ─────────────────────────────────────────────────────────────────
use Julio\Capyrel\Analyzers\CircularRelationshipAnalyzer;
use Julio\Capyrel\Analyzers\CascadeRiskAnalyzer;
use Julio\Capyrel\Analyzers\DeadRelationshipAnalyzer;
use Julio\Capyrel\Analyzers\DiagnosticsRunner;
use Julio\Capyrel\Analyzers\EagerLoadDepthAnalyzer;
use Julio\Capyrel\Analyzers\InverseRelationshipAnalyzer;
use Julio\Capyrel\Analyzers\MigrationSafetyAnalyzer;
use Julio\Capyrel\Analyzers\MissingIndexAnalyzer;
use Julio\Capyrel\Analyzers\MorphTypeRegistryAnalyzer;
use Julio\Capyrel\Analyzers\N1QueryAnalyzer;
use Julio\Capyrel\Analyzers\NamingConflictAnalyzer;
use Julio\Capyrel\Analyzers\OrphanForeignKeyAnalyzer;
use Julio\Capyrel\Analyzers\SchemaFillableDriftAnalyzer;
use Julio\Capyrel\Analyzers\SoftDeleteAnalyzer;

// ── Commands ──────────────────────────────────────────────────────────────────
use Julio\Capyrel\Commands\ArchitectureCommand;
use Julio\Capyrel\Commands\AuditCommand;
use Julio\Capyrel\Commands\BroadcastingCommand;
use Julio\Capyrel\Commands\CleanCommand;
use Julio\Capyrel\Commands\DemoCommand;
use Julio\Capyrel\Commands\EnumCommand;
use Julio\Capyrel\Commands\EventsCommand;
use Julio\Capyrel\Commands\FactoryCommand;
use Julio\Capyrel\Commands\FullstackCommand;
use Julio\Capyrel\Commands\GraphQLCommand;
use Julio\Capyrel\Commands\HealthCheckCommand;
use Julio\Capyrel\Commands\InstallExtensionCommand;
use Julio\Capyrel\Commands\LivewireCommand;
use Julio\Capyrel\Commands\MapCommand;
use Julio\Capyrel\Commands\NewProjectCommand;
use Julio\Capyrel\Commands\NotificationsCommand;
use Julio\Capyrel\Commands\OpenApiCommand;
use Julio\Capyrel\Commands\OptimizeCommand;
use Julio\Capyrel\Commands\PermissionsCommand;
use Julio\Capyrel\Commands\PolicyCommand;
use Julio\Capyrel\Commands\PostmanCommand;
use Julio\Capyrel\Commands\RequestsCommand;
use Julio\Capyrel\Commands\ResourcesCommand;
use Julio\Capyrel\Commands\SafeMigrateCommand;
use Julio\Capyrel\Commands\ScaffoldCommand;
use Julio\Capyrel\Commands\SeedCommand;
use Julio\Capyrel\Commands\StateMachineCommand;
use Julio\Capyrel\Commands\StubsCommand;
use Julio\Capyrel\Commands\TestsCommand;
use Julio\Capyrel\Commands\TypeScriptCommand;
use Julio\Capyrel\Commands\WatchCommand;
use Julio\Capyrel\Commands\WebhookCommand;

// ── Detectors ─────────────────────────────────────────────────────────────────
use Julio\Capyrel\Detectors\ColumnTypeDetector;
use Julio\Capyrel\Detectors\FrameworkDetector;
use Julio\Capyrel\Detectors\ModelContextClassifier;

// ── Generators ────────────────────────────────────────────────────────────────
use Julio\Capyrel\Generators\ApiResourceGenerator;
use Julio\Capyrel\Generators\ArtisanCommandGenerator;
use Julio\Capyrel\Generators\AxiosClientGenerator;
use Julio\Capyrel\Generators\BroadcastingGenerator;
use Julio\Capyrel\Generators\DtoGenerator;
use Julio\Capyrel\Generators\EnumGenerator;
use Julio\Capyrel\Generators\EventGenerator;
use Julio\Capyrel\Generators\FactoryGenerator;
use Julio\Capyrel\Generators\FeatureTestGenerator;
use Julio\Capyrel\Generators\FormRequestGenerator;
use Julio\Capyrel\Generators\FullBladeGenerator;
use Julio\Capyrel\Generators\GraphQLGenerator;
use Julio\Capyrel\Generators\HealthCheckGenerator;
use Julio\Capyrel\Generators\LivewireGenerator;
use Julio\Capyrel\Generators\NotificationGenerator;
use Julio\Capyrel\Generators\OpenApiGenerator;
use Julio\Capyrel\Generators\PermissionMatrixGenerator;
use Julio\Capyrel\Generators\PolicyGenerator;
use Julio\Capyrel\Generators\PostmanGenerator;
use Julio\Capyrel\Generators\RelationMethodGenerator;
use Julio\Capyrel\Generators\RelationshipTestGenerator;
use Julio\Capyrel\Generators\RepositoryGenerator;
use Julio\Capyrel\Generators\SeederGenerator;
use Julio\Capyrel\Generators\ServiceGenerator;
use Julio\Capyrel\Generators\StateMachineGenerator;
use Julio\Capyrel\Generators\TypeScriptGenerator;
use Julio\Capyrel\Generators\WebhookGenerator;

// ── Schema ────────────────────────────────────────────────────────────────────
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

// ── Wizard ────────────────────────────────────────────────────────────────────
use Julio\Capyrel\Wizard\LaravelAwareness;
use Julio\Capyrel\Wizard\EntityParser;
use Julio\Capyrel\Wizard\FieldInferrer;
use Julio\Capyrel\Wizard\ModelPlanBuilder;

// ── Writers ───────────────────────────────────────────────────────────────────
use Julio\Capyrel\Writers\BladeWriter;
use Julio\Capyrel\Writers\ControllerWriter;
use Julio\Capyrel\Writers\MigrationWriter;
use Julio\Capyrel\Writers\ModelEnhancer;
use Julio\Capyrel\Writers\ModelWriter;
use Julio\Capyrel\Writers\RouteWriter;

class CapyrelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Schema ────────────────────────────────────────────────────────────
        $this->app->singleton(SchemaAnalyzer::class);
        $this->app->singleton(RelationshipDetector::class);

        // ── Wizard ────────────────────────────────────────────────────────────
        $this->app->singleton(LaravelAwareness::class);
        $this->app->singleton(EntityParser::class);
        $this->app->singleton(FieldInferrer::class);
        $this->app->singleton(ModelPlanBuilder::class);
        $this->app->singleton(MigrationWriter::class);

        // ── Detectors ─────────────────────────────────────────────────────────
        $this->app->singleton(FrameworkDetector::class);
        $this->app->singleton(ModelContextClassifier::class);
        $this->app->singleton(ColumnTypeDetector::class);

        // ── Writers ───────────────────────────────────────────────────────────
        $this->app->singleton(ModelEnhancer::class);

        $this->app->singleton(ModelWriter::class, fn($app) =>
            new ModelWriter(
                $app->make(RelationMethodGenerator::class),
                $app->make(ModelEnhancer::class),
            )
        );

        $this->app->singleton(ControllerWriter::class, fn($app) =>
            new ControllerWriter($app->make(FrameworkDetector::class))
        );

        $this->app->singleton(BladeWriter::class);
        $this->app->singleton(RouteWriter::class);

        // ── Core generators ───────────────────────────────────────────────────
        $this->app->singleton(RelationMethodGenerator::class);
        $this->app->singleton(ApiResourceGenerator::class);
        $this->app->singleton(FormRequestGenerator::class);
        $this->app->singleton(FullBladeGenerator::class);
        $this->app->singleton(RelationshipTestGenerator::class);
        $this->app->singleton(FeatureTestGenerator::class);
        $this->app->singleton(FactoryGenerator::class);
        $this->app->singleton(PolicyGenerator::class);
        $this->app->singleton(SeederGenerator::class);
        $this->app->singleton(EnumGenerator::class);
        $this->app->singleton(EventGenerator::class);
        $this->app->singleton(LivewireGenerator::class);

        // ── Architecture generators ───────────────────────────────────────────
        $this->app->singleton(StateMachineGenerator::class);
        $this->app->singleton(RepositoryGenerator::class);
        $this->app->singleton(ServiceGenerator::class);
        $this->app->singleton(DtoGenerator::class);

        // ── Contract / API generators ─────────────────────────────────────────
        $this->app->singleton(OpenApiGenerator::class);
        $this->app->singleton(TypeScriptGenerator::class);
        $this->app->singleton(AxiosClientGenerator::class);
        $this->app->singleton(PostmanGenerator::class);
        $this->app->singleton(GraphQLGenerator::class);

        // ── Realtime / integration generators ────────────────────────────────
        $this->app->singleton(BroadcastingGenerator::class);
        $this->app->singleton(WebhookGenerator::class);

        // ── Ops / security generators ─────────────────────────────────────────
        $this->app->singleton(ArtisanCommandGenerator::class);
        $this->app->singleton(HealthCheckGenerator::class);
        $this->app->singleton(NotificationGenerator::class);
        $this->app->singleton(PermissionMatrixGenerator::class);

        // ── Health check analyzers ────────────────────────────────────────────
        $this->app->singleton(N1QueryAnalyzer::class);
        $this->app->singleton(MissingIndexAnalyzer::class);
        $this->app->singleton(OrphanForeignKeyAnalyzer::class);
        $this->app->singleton(InverseRelationshipAnalyzer::class);
        $this->app->singleton(NamingConflictAnalyzer::class);
        $this->app->singleton(SoftDeleteAnalyzer::class);
        $this->app->singleton(EagerLoadDepthAnalyzer::class);
        $this->app->singleton(CircularRelationshipAnalyzer::class);
        $this->app->singleton(CascadeRiskAnalyzer::class);
        $this->app->singleton(DeadRelationshipAnalyzer::class);
        $this->app->singleton(SchemaFillableDriftAnalyzer::class);
        $this->app->singleton(MorphTypeRegistryAnalyzer::class);
        $this->app->singleton(DiagnosticsRunner::class);
        $this->app->singleton(MigrationSafetyAnalyzer::class);
    }

    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/capyrel.php', 'capyrel');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/capyrel.php' => config_path('capyrel.php'),
            ], 'capyrel-config');

            $this->commands([
                // ── Project wizard ────────────────────────────────────────────
                NewProjectCommand::class,          // php artisan capyrel:new

                // ── Core scaffolding ──────────────────────────────────────────
                ScaffoldCommand::class,

                // ── Per-model generators ──────────────────────────────────────
                MapCommand::class,
                ResourcesCommand::class,
                RequestsCommand::class,
                TestsCommand::class,
                FactoryCommand::class,
                PolicyCommand::class,
                SeedCommand::class,
                OptimizeCommand::class,
                EnumCommand::class,
                EventsCommand::class,
                LivewireCommand::class,

                // ── Architecture layer ────────────────────────────────────────
                StateMachineCommand::class,
                ArchitectureCommand::class,

                // ── Real-time / integration ───────────────────────────────────
                BroadcastingCommand::class,
                WebhookCommand::class,
                NotificationsCommand::class,

                // ── Contract generation ───────────────────────────────────────
                OpenApiCommand::class,
                TypeScriptCommand::class,
                PostmanCommand::class,
                GraphQLCommand::class,

                // ── Security / permissions ────────────────────────────────────
                PermissionsCommand::class,

                // ── Ops ───────────────────────────────────────────────────────
                HealthCheckCommand::class,

                // ── Safety + watch ────────────────────────────────────────────
                SafeMigrateCommand::class,
                WatchCommand::class,

                // ── Stubs ─────────────────────────────────────────────────────
                StubsCommand::class,

                // ── One-command fullstack ─────────────────────────────────────
                FullstackCommand::class,

                // ── Audit + clean ─────────────────────────────────────────────
                AuditCommand::class,
                CleanCommand::class,

                // ── Demo + extension ──────────────────────────────────────────
                DemoCommand::class,
                InstallExtensionCommand::class,
            ]);
        }
    }
}
