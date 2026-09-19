<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\DTOs;

final readonly class ManifestValidationReport
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        public string $name,
        public string $filename,
        public string $path,
        public bool $exists,
        public bool $isValid,
        public array $errors = [],
        public ?string $errorMessage = null,
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
