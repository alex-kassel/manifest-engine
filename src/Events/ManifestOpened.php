<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Events;

class ManifestOpened
{
    public function __construct(
        public readonly string $path,
    ) {}
}
