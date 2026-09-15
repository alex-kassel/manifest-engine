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
    abstract public function rules(): array;

    /**
     * {@inheritdoc}
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSchema(): ?array
    {
        return null;
    }
}
