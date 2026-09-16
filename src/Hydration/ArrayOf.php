<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Hydration;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ArrayOf
{
    /**
     * @param  class-string  $class
     */
    public function __construct(
        public readonly string $class,
    ) {}
}
