<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\DTOs;

class ManifestStatusReport
{
    public function __construct(
        public readonly string $name,
        public readonly string $filename,
        public readonly string $path,
        public readonly bool $exists,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $humanSize = null,
        public readonly ?string $lastModified = null,
        public readonly ?string $description = null,
    ) {}
}
