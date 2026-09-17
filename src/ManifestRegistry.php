<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;

class ManifestRegistry
{
    /**
     * @var array<string, ManifestDefinition>
     */
    protected array $manifests = [];

    /**
     * Register a new manifest definition.
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
    ): self {
        $this->manifests[$name] = new ManifestDefinition(
            name: $name,
            filename: $filename,
            schema: $schema,
            description: $description,
            metadata: $metadata,
        );

        return $this;
    }

    /**
     * Determine if a manifest is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->manifests[$name]);
    }

    /**
     * Get a registered manifest definition.
     */
    public function get(string $name): ?ManifestDefinition
    {
        return $this->manifests[$name] ?? null;
    }

    /**
     * Get all registered manifest definitions.
     *
     * @return array<string, ManifestDefinition>
     */
    public function all(): array
    {
        return $this->manifests;
    }

    /**
     * Clear all registrations.
     */
    public function clear(): self
    {
        $this->manifests = [];

        return $this;
    }
}
