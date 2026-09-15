<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Contracts\StorageDriver;
use AlexKassel\ManifestEngine\Hydration\DtoHydrator;
use AlexKassel\ManifestEngine\Storage\AtomicFileStorage;
use AlexKassel\ManifestEngine\Validation\ManifestValidator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Filesystem\Filesystem;

class ManifestFactory
{
    /**
     * Create a fully configured Manifest instance.
     */
    public static function create(
        string $path,
        ?ManifestSchema $schema = null,
        StorageDriver|Filesystem|null $storage = null,
        ?ManifestValidator $validator = null,
        ?DtoHydrator $hydrator = null,
        ?Dispatcher $events = null,
        ?Filesystem $files = null,
        ?ValidationFactory $validationFactory = null,
    ): Manifest {
        $storageInstance = $storage instanceof StorageDriver
            ? $storage
            : new AtomicFileStorage($storage instanceof Filesystem ? $storage : ($files ?? new Filesystem));

        $validatorInstance = $validator ?? ($validationFactory !== null
            ? new ManifestValidator($validationFactory, $events)
            : ManifestValidator::createStandalone($events));

        $hydratorInstance = $hydrator ?? new DtoHydrator;

        return new Manifest(
            path: $path,
            schema: $schema,
            storage: $storageInstance,
            validator: $validatorInstance,
            hydrator: $hydratorInstance,
            events: $events,
        );
    }
}
