<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;

final readonly class ManifestManager
{
    public function __construct(
        public ManifestRegistry $registry,
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
     * Open a registered manifest by its alias name.
     *
     * @throws ManifestNotFoundException
     */
    public function open(string $name): Manifest
    {
        $definition = $this->registry->get($name);

        if ($definition === null) {
            throw new ManifestNotFoundException("No manifest registered with alias [{$name}].");
        }

        return new Manifest(
            path: $definition->fullPath(),
        );
    }
}
