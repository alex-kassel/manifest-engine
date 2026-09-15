<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Events;

class ManifestSaving
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $path,
        public readonly array $data,
    ) {}
}
