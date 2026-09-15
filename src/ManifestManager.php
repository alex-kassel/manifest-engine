<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Filesystem\Filesystem;

class ManifestManager
{
    public function __construct(
        protected readonly Filesystem $files,
        protected readonly ManifestRegistry $registry,
        protected readonly ?ValidationFactory $validatorFactory = null,
        protected readonly ?Dispatcher $events = null,
    ) {}

    /**
     * Open a manifest document handler for given file path and schema.
     */
    public function open(string $path, ?ManifestSchema $schema = null): Manifest
    {
        return Manifest::open(
            path: $path,
            schema: $schema,
            files: $this->files,
            validatorFactory: $this->validatorFactory,
            events: $this->events,
        );
    }

    /**
     * Register a manifest definition in the application registry.
     *
     * @param  class-string<ManifestSchema>|ManifestSchema  $schema
     * @param  array<string, mixed>  $metadata
     */
    public function register(
        string $name,
        string $filename,
        string|ManifestSchema $schema,
        ?string $description = null,
        array $metadata = [],
    ): ManifestRegistry {
        return $this->registry->register(
            name: $name,
            filename: $filename,
            schema: $schema,
            description: $description,
            metadata: $metadata,
        );
    }

    /**
     * Get underlying manifest registry instance.
     */
    public function registry(): ManifestRegistry
    {
        return $this->registry;
    }

    /**
     * Get underlying filesystem instance.
     */
    public function files(): Filesystem
    {
        return $this->files;
    }
}
