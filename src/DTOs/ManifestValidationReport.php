<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\DTOs;

class ManifestValidationReport
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        public readonly string $name,
        public readonly string $filename,
        public readonly string $path,
        public readonly bool $exists,
        public readonly bool $isValid,
        public readonly array $errors = [],
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * Format validation error messages for display.
     */
    public function formattedErrors(): string
    {
        if (! empty($this->errors)) {
            $lines = [];
            foreach ($this->errors as $field => $messages) {
                $lines[] = "{$field}: ".implode(', ', $messages);
            }

            return implode("\n", $lines);
        }

        return $this->errorMessage ?? '';
    }
}
