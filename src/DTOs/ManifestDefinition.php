<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\DTOs;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;

final readonly class ManifestDefinition
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public string $path,
        public ManifestSchema $schema,
        public ?string $description = null,
        public array $metadata = [],
    ) {}
}
