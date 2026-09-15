<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\DTOs;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;

class ManifestDefinition
{
    /**
     * @param  class-string<ManifestSchema>|ManifestSchema  $schema
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly string $filename,
        public readonly string|ManifestSchema $schema,
        public readonly ?string $description = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Resolve the underlying ManifestSchema instance.
     */
    public function resolveSchema(): ManifestSchema
    {
        if ($this->schema instanceof ManifestSchema) {
            return $this->schema;
        }

        return new $this->schema;
    }
}
