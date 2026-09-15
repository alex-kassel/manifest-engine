<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Console\Commands\ManifestInstallCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestStatusCommand;
use AlexKassel\ManifestEngine\Services\ManifestInstaller;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
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

        $this->app->singleton(ManifestInstaller::class, function ($app) {
            return new ManifestInstaller($app->make(Filesystem::class));
        });

        $this->app->singleton(ManifestManager::class, function ($app) {
            $files = $app->make(Filesystem::class);
            $registry = $app->make(ManifestRegistry::class);
            $validatorFactory = $app->bound('validator') ? $app->make(ValidationFactory::class) : null;

            return new ManifestManager($files, $registry, $validatorFactory);
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
                ManifestInstallCommand::class,
                ManifestStatusCommand::class,
            ]);
        }
    }
}
