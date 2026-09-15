<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Console\Commands\ManifestStatusCommand;
use AlexKassel\ManifestEngine\Contracts\StorageDriver;
use AlexKassel\ManifestEngine\Hydration\DtoHydrator;
use AlexKassel\ManifestEngine\Storage\AtomicFileStorage;
use AlexKassel\ManifestEngine\Validation\ManifestValidator;
use Illuminate\Contracts\Events\Dispatcher;
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
        $this->app->singleton(StorageDriver::class, function ($app) {
            return new AtomicFileStorage($app->make(Filesystem::class));
        });

        $this->app->singleton(ManifestValidator::class, function ($app) {
            $validationFactory = $app->bound('validator')
                ? $app->make(ValidationFactory::class)
                : null;
            $events = $app->bound('events')
                ? $app->make(Dispatcher::class)
                : null;

            return $validationFactory !== null
                ? new ManifestValidator($validationFactory, $events)
                : ManifestValidator::createStandalone($events);
        });

        $this->app->singleton(DtoHydrator::class, function () {
            return new DtoHydrator;
        });

        $this->app->singleton(ManifestRegistry::class, function () {
            return new ManifestRegistry;
        });

        $this->app->singleton(ManifestManager::class, function ($app) {
            return new ManifestManager(
                storage: $app->make(StorageDriver::class),
                registry: $app->make(ManifestRegistry::class),
                validator: $app->make(ManifestValidator::class),
                hydrator: $app->make(DtoHydrator::class),
                events: $app->bound('events') ? $app->make(Dispatcher::class) : null,
                basePath: function_exists('base_path') ? base_path() : null,
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
            ]);
        }
    }
}
