<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use Illuminate\Filesystem\Filesystem;

class ManifestManager
{
    protected Filesystem $files;

    public function __construct(
        ?Filesystem $files = null,
        protected readonly ManifestRegistry $registry = new ManifestRegistry,
        protected readonly ?string $basePath = null,
    ) {
        $this->files = $files ?? new Filesystem;
    }

    /**
     * Open a manifest document handler for given file path and optional schema.
     */
    public function open(string $path, ?ManifestSchema $schema = null): Manifest
    {
        return new Manifest(
            path: $path,
            schema: $schema,
            files: $this->files,
        );
    }

    /**
     * Retrieve and open a registered manifest by its alias name.
     *
     * @throws ManifestException
     */
    public function get(string $name, ?string $basePath = null): Manifest
    {
        $definition = $this->registry->get($name);
        if ($definition === null) {
            throw new ManifestException("No manifest registered with alias [{$name}].");
        }

        $fullPath = $definition->fullPath($basePath ?? $this->basePath);

        return $this->open($fullPath, $definition->resolveSchema());
    }

    /**
     * Determine if a manifest alias is registered in the registry.
     */
    public function has(string $name): bool
    {
        return $this->registry->has($name);
    }

    /**
     * Register a manifest definition in the application registry.
     */
    public function register(ManifestDefinition $definition): self
    {
        $this->registry->register($definition);

        return $this;
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
