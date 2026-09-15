<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Console\Commands\ManifestMakeCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestSchemaCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestStatusCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestValidateCommand;
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

    public const CONFIG_NAME = 'manifest-engine';

    public const CONFIG_PUBLISH_TAG = 'manifest-engine-config';

    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_EMPTY_MANIFESTS = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/manifest-engine.php',
            self::CONFIG_NAME
        );

        $this->app->singleton(StorageDriver::class, function ($app) {
            $locksDir = config('manifest-engine.locks_directory');

            return new AtomicFileStorage(
                files: $app->make(Filesystem::class),
                locksDirectory: is_string($locksDir) ? $locksDir : null
            );
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
            $this->publishes([
                __DIR__.'/../config/manifest-engine.php' => config_path('manifest-engine.php'),
            ], self::CONFIG_PUBLISH_TAG);

            $this->commands([
                ManifestStatusCommand::class,
                ManifestValidateCommand::class,
                ManifestSchemaCommand::class,
                ManifestMakeCommand::class,
            ]);
        }

        $this->bootConfiguredManifests();
    }

    /**
     * Automatically register manifests configured in config/manifest-engine.php.
     */
    protected function bootConfiguredManifests(): void
    {
        /** @var array<string, array<string, mixed>> $manifests */
        $manifests = config('manifest-engine.manifests', self::DEFAULT_EMPTY_MANIFESTS);
        if (empty($manifests) || ! is_array($manifests)) {
            return;
        }

        /** @var ManifestRegistry $registry */
        $registry = $this->app->make(ManifestRegistry::class);

        foreach ($manifests as $name => $config) {
            $filename = (string) ($config['filename'] ?? '');
            $schema = $config['schema'] ?? null;
            $description = isset($config['description']) ? (string) $config['description'] : null;
            $metadata = isset($config['metadata']) && is_array($config['metadata']) ? $config['metadata'] : [];

            if ($filename !== '' && $schema !== null) {
                $registry->register(
                    name: $name,
                    filename: $filename,
                    schema: $schema,
                    description: $description,
                    metadata: $metadata
                );
            }
        }
    }
}
