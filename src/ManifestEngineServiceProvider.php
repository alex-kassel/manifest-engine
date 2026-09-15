<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class ManifestEngineServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('manifest.engine', function ($app) {
            return new class($app->make(Filesystem::class))
            {
                public function __construct(protected Filesystem $files) {}

                public function open(string $path, ?ManifestSchema $schema = null): Manifest
                {
                    return Manifest::open($path, $schema, $this->files);
                }
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
