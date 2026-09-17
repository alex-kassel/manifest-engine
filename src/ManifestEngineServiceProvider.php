<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Console\Commands\ManifestMakeCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestSchemaCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestStatusCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestValidateCommand;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class ManifestEngineServiceProvider extends ServiceProvider
{
    public const FACADE_ACCESSOR = 'manifest.engine';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ManifestRegistry::class, function () {
            return new ManifestRegistry;
        });

        $this->app->singleton(ManifestManager::class, function ($app) {
            return new ManifestManager(
                files: $app->make(Filesystem::class),
                registry: $app->make(ManifestRegistry::class),
                events: $app->make(Dispatcher::class),
                basePath: function_exists('base_path') ? base_path() : null,
            );
        });

        $this->app->singleton(Services\ManifestInspectionService::class, function ($app) {
            return new Services\ManifestInspectionService(
                manager: $app->make(ManifestManager::class),
                files: $app->make(Filesystem::class),
            );
        });

        $this->app->alias(ManifestManager::class, self::FACADE_ACCESSOR);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ManifestStatusCommand::class,
                ManifestValidateCommand::class,
                ManifestSchemaCommand::class,
                ManifestMakeCommand::class,
            ]);
        }
    }
}
