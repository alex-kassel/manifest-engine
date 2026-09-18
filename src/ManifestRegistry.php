<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;

class ManifestRegistry
{
    /**
     * @var array<string, ManifestDefinition>
     */
    protected array $manifests = [];

    /**
     * Register a new manifest definition.
     */
    public function register(ManifestDefinition $definition): self
    {
        $this->manifests[$definition->name] = $definition;

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
     * Get a registered manifest definition by its alias name.
     */
    public function get(string $name): ?ManifestDefinition
    {
        return $this->manifests[$name] ?? null;
    }

    /**
     * Find a registered manifest definition by its file path.
     */
    public function findByPath(string $path): ?ManifestDefinition
    {
        $resolved = Manifest::resolvePath($path);

        foreach ($this->manifests as $definition) {
            if ($definition->fullPath() === $resolved) {
                return $definition;
            }
        }

        return null;
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
     * Get all registered manifest alias names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->manifests);
    }

    /**
     * Forget a registered manifest definition by name.
     */
    public function forget(string $name): self
    {
        unset($this->manifests[$name]);

        return $this;
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
