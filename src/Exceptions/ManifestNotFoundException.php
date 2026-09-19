<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Exceptions;

use Throwable;

class ManifestNotFoundException extends ManifestException
{
    public function __construct(
        public readonly string $path,
        ?string $message = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? "Manifest file [{$path}] does not exist on disk.",
            $code,
            $previous,
        );
    }
}
