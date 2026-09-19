<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Console\Commands\ManifestInitCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestSchemaCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestStatusCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestValidateCommand;
use AlexKassel\ManifestEngine\Services\ManifestInspectionService;
use Illuminate\Support\ServiceProvider;

class ManifestEngineServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ManifestRegistry::class);
        $this->app->singleton(ManifestManager::class);
        $this->app->singleton(ManifestInspectionService::class);
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
                ManifestInitCommand::class,
            ]);
        }
    }
}
