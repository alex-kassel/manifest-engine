<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Events;

class ManifestMutated
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly string $path,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
