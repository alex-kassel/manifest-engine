<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Contracts\StorageDriver;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Hydration\DtoHydrator;
use AlexKassel\ManifestEngine\Storage\AtomicFileStorage;
use AlexKassel\ManifestEngine\Validation\ManifestValidator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Filesystem\Filesystem;

class ManifestManager
{
    protected StorageDriver $storage;

    protected ManifestValidator $validator;

    protected DtoHydrator $hydrator;

    public function __construct(
        StorageDriver|Filesystem|null $storage = null,
        protected readonly ManifestRegistry $registry = new ManifestRegistry,
        ?ManifestValidator $validator = null,
        ?DtoHydrator $hydrator = null,
        protected readonly ?Dispatcher $events = null,
        protected readonly ?string $basePath = null,
        ?ValidationFactory $validatorFactory = null,
    ) {
        $this->storage = $storage instanceof StorageDriver
            ? $storage
            : new AtomicFileStorage($storage instanceof Filesystem ? $storage : new Filesystem);

        $this->validator = $validator ?? ($validatorFactory !== null
            ? new ManifestValidator($validatorFactory, $this->events)
            : ManifestValidator::createStandalone($this->events));

        $this->hydrator = $hydrator ?? new DtoHydrator;
    }

    /**
     * Open a manifest document handler for given file path and optional schema.
     */
    public function open(string $path, ?ManifestSchema $schema = null): Manifest
    {
        return new Manifest(
            path: $path,
            schema: $schema,
            storage: $this->storage,
            validator: $this->validator,
            hydrator: $this->hydrator,
            events: $this->events,
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

        $root = $basePath ?? $this->basePath ?? (function_exists('base_path') ? base_path() : (string) getcwd());
        $fullPath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$definition->filename;

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
     * Get underlying storage driver instance.
     */
    public function storage(): StorageDriver
    {
        return $this->storage;
    }
}
