<?php

namespace Julio\Capyrel;

use Illuminate\Support\ServiceProvider;
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
use Julio\Capyrel\Commands\DemoCommand;
use Julio\Capyrel\Commands\MapCommand;
use Julio\Capyrel\Commands\RequestsCommand;
use Julio\Capyrel\Commands\ResourcesCommand;
use Julio\Capyrel\Commands\SafeMigrateCommand;
use Julio\Capyrel\Commands\ScaffoldCommand;
use Julio\Capyrel\Commands\TestsCommand;
use Julio\Capyrel\Commands\WatchCommand;
use Julio\Capyrel\Generators\ApiResourceGenerator;
use Julio\Capyrel\Generators\FormRequestGenerator;
use Julio\Capyrel\Generators\RelationMethodGenerator;
use Julio\Capyrel\Generators\RelationshipTestGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Writers\BladeWriter;
use Julio\Capyrel\Writers\ControllerWriter;
use Julio\Capyrel\Writers\ModelWriter;

class CapyrelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Schema
        $this->app->singleton(SchemaAnalyzer::class);
        $this->app->singleton(RelationshipDetector::class);

        // Generators
        $this->app->singleton(RelationMethodGenerator::class);
        $this->app->singleton(ApiResourceGenerator::class);
        $this->app->singleton(FormRequestGenerator::class);
        $this->app->singleton(RelationshipTestGenerator::class);

        // Writers
        $this->app->singleton(ModelWriter::class);
        $this->app->singleton(ControllerWriter::class);
        $this->app->singleton(BladeWriter::class);

        // Health check analyzers
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

        // Migration safety
        $this->app->singleton(MigrationSafetyAnalyzer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Core scaffolding
                ScaffoldCommand::class,

                // Generators
                MapCommand::class,
                ResourcesCommand::class,
                RequestsCommand::class,
                TestsCommand::class,

                // Safety + watch
                SafeMigrateCommand::class,
                WatchCommand::class,

                // Demo
                DemoCommand::class,
            ]);
        }
    }
}
