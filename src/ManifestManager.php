<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;

class ManifestManager
{
    public function __construct(
        public readonly ManifestRegistry $registry,
    ) {}

    /**
     * Open a manifest document handler for given file path and optional schema.
     */
    public function open(string $path, ?ManifestSchema $schema = null): Manifest
    {
        return new Manifest(
            path: $path,
            schema: $schema,
        );
    }

    /**
     * Retrieve and open a registered manifest by its alias name.
     *
     * @throws ManifestNotFoundException
     */
    public function get(string $name): Manifest
    {
        $definition = $this->registry->get($name);

        if ($definition === null) {
            throw new ManifestNotFoundException("No manifest registered with alias [{$name}].");
        }

        return $this->open($definition->fullPath(), $definition->resolveSchema());
    }
}
