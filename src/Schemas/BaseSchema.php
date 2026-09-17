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
    public function descriptions(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function types(): array
    {
        return [];
    }

    /**
     * Optional title for JSON Schema.
     */
    public function title(): ?string
    {
        return class_basename(static::class);
    }

    /**
     * Optional description for JSON Schema.
     */
    public function description(): ?string
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSchema(): ?array
    {
        return (new JsonSchemaCompiler)->compile($this);
    }
}
