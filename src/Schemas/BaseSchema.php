<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Schemas;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;

abstract class BaseSchema implements ManifestSchema
{
    /**
     * {@inheritdoc}
     */
    abstract public function defaults(): array;

    /**
     * {@inheritdoc}
     */
    public function jsonSchema(): array
    {
        return [];
    }
}
