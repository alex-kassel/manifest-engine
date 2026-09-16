<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\DTOs;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use Illuminate\Container\Container;

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
