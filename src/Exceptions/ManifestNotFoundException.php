<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Exceptions;

class ManifestNotFoundException extends ManifestException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("Manifest file [{$path}] does not exist on disk.");
    }
}
