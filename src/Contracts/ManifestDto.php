<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Contracts;

interface ManifestDto
{
    /**
     * Convert the DTO to an array for manifest storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Create a DTO instance from raw manifest data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static;
}
