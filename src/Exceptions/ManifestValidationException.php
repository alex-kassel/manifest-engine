<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Exceptions;

class ManifestValidationException extends ManifestException
{
    public const DEFAULT_ERROR_SUMMARY = 'Unknown validation error';

    public const ERROR_DELIMITER = '; ';

    public const FIELD_ERROR_DELIMITER = ', ';

    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly string $path,
        public readonly array $errors,
    ) {
        $errorStrings = [];
        foreach ($errors as $key => $val) {
            $msg = is_array($val) ? implode(self::FIELD_ERROR_DELIMITER, $val) : (string) $val;
            $errorStrings[] = "{$key}: {$msg}";
        }
        $errorSummary = ! empty($errorStrings) ? implode(self::ERROR_DELIMITER, $errorStrings) : self::DEFAULT_ERROR_SUMMARY;
        parent::__construct("Manifest [{$path}] failed schema validation: {$errorSummary}");
    }
}
