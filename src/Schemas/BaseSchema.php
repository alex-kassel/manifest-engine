<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Schemas;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;

abstract class BaseSchema implements ManifestSchema
{
    protected ?JsonSchemaCompiler $schemaCompiler = null;

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
        $compiler = $this->schemaCompiler ??= new JsonSchemaCompiler;

        return $compiler->compile($this->rules());
    }
}
