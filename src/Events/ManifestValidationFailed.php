<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Events;

class ManifestValidationFailed
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly string $path,
        public readonly array $errors,
    ) {}
}
