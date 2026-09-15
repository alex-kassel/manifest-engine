<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Exceptions;

class ManifestConcurrentModificationException extends ManifestException
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $actualHash,
        public readonly ?string $expectedHash,
    ) {
        parent::__construct(
            "Manifest [{$path}] was modified concurrently by another process or agent. "
            ."Expected hash [{$expectedHash}], but found [{$actualHash}]."
        );
    }
}
