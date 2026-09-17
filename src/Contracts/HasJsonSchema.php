<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Contracts;

interface HasJsonSchema
{
    /**
     * Get the JSON Schema (Draft-07 fragment) for this validation rule.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array;
}
