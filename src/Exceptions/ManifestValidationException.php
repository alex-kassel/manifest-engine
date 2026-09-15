<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Exceptions;

class ManifestValidationException extends ManifestException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly string $path,
        public readonly array $errors,
    ) {
        $errorSummary = implode('; ', $errors);
        parent::__construct("Manifest [{$path}] failed schema validation: {$errorSummary}");
    }
}
