<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Exceptions;

use Throwable;

class ManifestLockTimeoutException extends ManifestException
{
    public function __construct(string $path, int $timeoutSeconds, ?Throwable $previous = null)
    {
        parent::__construct(
            "Timed out after [{$timeoutSeconds}] seconds waiting for lock on manifest [{$path}].",
            0,
            $previous
        );
    }
}
