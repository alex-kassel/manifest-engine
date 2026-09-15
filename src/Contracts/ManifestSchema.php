<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Contracts;

use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;

interface ManifestSchema
{
    /**
     * Default state when a new manifest file is initialized.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * Validate manifest data against schema rules.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ManifestValidationException
     */
    public function validate(array $data, string $path): void;
}
