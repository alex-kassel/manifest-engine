<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\DTOs;

class ManifestValidationReport
{
    public const DEFAULT_EMPTY_ERROR_MESSAGE = '';

    public const GLUE_NEWLINE = "\n";

    public const GLUE_ERROR_LIST = ', ';

    public const GLUE_FIELD_PREFIX = ': ';

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
                $lines[] = $field.self::GLUE_FIELD_PREFIX.implode(self::GLUE_ERROR_LIST, $messages);
            }

            return implode(self::GLUE_NEWLINE, $lines);
        }

        return $this->errorMessage ?? self::DEFAULT_EMPTY_ERROR_MESSAGE;
    }
}
