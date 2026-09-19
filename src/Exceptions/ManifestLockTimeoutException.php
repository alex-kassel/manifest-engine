<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Exceptions;

use Throwable;

class ManifestLockTimeoutException extends ManifestException
{
    public function __construct(
        public readonly string $path,
        public readonly int $timeoutSeconds,
        ?Throwable $previous = null,
        ?string $message = null,
        int $code = 0,
    ) {
        parent::__construct(
            $message ?? "Timed out after [{$timeoutSeconds}] seconds waiting for lock on manifest [{$path}].",
            $code,
            $previous,
        );
    }
}
