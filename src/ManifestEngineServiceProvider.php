<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Console\Commands\ManifestInstallCommand;
use AlexKassel\ManifestEngine\Console\Commands\ManifestStatusCommand;
use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Services\ManifestInstaller;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class ManifestEngineServiceProvider extends ServiceProvider
{
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

        $this->app->singleton('manifest.engine', function ($app) {
            $registry = $app->make(ManifestRegistry::class);
            $files = $app->make(Filesystem::class);

            return new class($files, $registry)
            {
                public function __construct(
                    protected Filesystem $files,
                    protected ManifestRegistry $registry,
                ) {}

                public function open(string $path, ?ManifestSchema $schema = null): Manifest
                {
                    return Manifest::open($path, $schema, $this->files);
                }

                public function register(
                    string $name,
                    string $filename,
                    string|ManifestSchema $schema,
                    ?string $runnerPath = null,
                    ?string $description = null,
                ): ManifestRegistry {
                    return $this->registry->register($name, $filename, $schema, $runnerPath, $description);
                }

                public function registry(): ManifestRegistry
                {
                    return $this->registry;
                }
            };
        });
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
