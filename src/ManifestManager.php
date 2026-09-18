<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;

class ManifestManager
{
    public function __construct(
        public readonly ManifestRegistry $registry,
    ) {}

    /**
     * Register a manifest definition and return self for method chaining.
     */
    public function register(ManifestDefinition $definition): self
    {
        $this->registry->register($definition);

        return $this;
    }

    /**
     * Open a manifest document handler for a registered alias or file path.
     */
    public function open(string $target, ?ManifestSchema $schema = null): Manifest
    {
        if ($definition = $this->registry->get($target)) {
            return new Manifest(
                path: $definition->fullPath(),
                schema: $schema ?? $definition->resolveSchema(),
            );
        }

        return new Manifest(
            path: $target,
            schema: $schema,
        );
    }
}
