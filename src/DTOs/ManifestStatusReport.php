<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\DTOs;

final readonly class ManifestStatusReport
{
    public function __construct(
        public string $name,
        public string $filename,
        public string $path,
        public bool $exists,
        public ?int $sizeBytes = null,
        public ?string $humanSize = null,
        public ?string $lastModified = null,
        public ?string $description = null,
    ) {}
}
