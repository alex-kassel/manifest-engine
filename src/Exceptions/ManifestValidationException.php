<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Exceptions;

class ManifestValidationException extends ManifestException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly string $path,
        public readonly array $errors,
    ) {
        $errorStrings = [];
        foreach ($errors as $key => $val) {
            $msg = is_array($val) ? implode(', ', $val) : (string) $val;
            $errorStrings[] = "{$key}: {$msg}";
        }
        $errorSummary = ! empty($errorStrings) ? implode('; ', $errorStrings) : 'Unknown validation error';
        parent::__construct("Manifest [{$path}] failed schema validation: {$errorSummary}");
    }
}
