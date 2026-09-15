<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Contracts;

interface ManifestSchema
{
    /**
     * Default state when a new manifest file is initialized.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * Standard Laravel validation rules for manifest data.
     * Supports dot-notation, wildcards (*), sometimes, nullable, and custom Rule objects.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array;

    /**
     * Custom attribute names for validation errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array;

    /**
     * Full JSON Schema (Draft-07) representation for IDE autocomplete and static analysis.
     *
     * @return array<string, mixed>|null
     */
    public function jsonSchema(): ?array;
}
