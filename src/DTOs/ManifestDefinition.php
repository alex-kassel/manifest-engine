<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\DTOs;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Manifest;
use Illuminate\Container\Container;

class ManifestDefinition
{
    public readonly string $filename;

    /**
     * @param  class-string<ManifestSchema>|ManifestSchema  $schema
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $name,
        string $filename,
        public readonly string|ManifestSchema $schema,
        public readonly ?string $description = null,
        public readonly array $metadata = [],
    ) {
        $this->filename = Manifest::normalizeFilename($filename);
    }

    /**
     * Resolve the full filesystem path for the manifest file.
     */
    public function fullPath(?string $basePath = null): string
    {
        return Manifest::resolvePath($this->filename, $basePath);
    }

    /**
     * Resolve the underlying ManifestSchema instance.
     */
    public function resolveSchema(): ManifestSchema
    {
        if ($this->schema instanceof ManifestSchema) {
            return $this->schema;
        }

        if (class_exists(Container::class)) {
            $container = Container::getInstance();
            if ($container !== null) {
                /** @var ManifestSchema */
                return $container->make($this->schema);
            }
        }

        /** @var ManifestSchema */
        return new $this->schema;
    }
}
