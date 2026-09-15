<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Validation;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Events\ManifestValidationFailed;
use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidationFactoryContract;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;

class ManifestValidator
{
    public const DEFAULT_TRANSLATOR_LOCALE = 'en';

    public function __construct(
        protected readonly ValidationFactoryContract $validatorFactory,
        protected readonly ?Dispatcher $events = null,
    ) {}

    /**
     * Create a standalone validator instance without Laravel container bindings.
     */
    public static function createStandalone(?Dispatcher $events = null): self
    {
        $loader = new ArrayLoader;
        $translator = new Translator($loader, self::DEFAULT_TRANSLATOR_LOCALE);
        $factory = new ValidationFactory($translator);

        return new self($factory, $events);
    }

    /**
     * Validate data against the schema rules.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ManifestValidationException
     */
    public function validate(string $path, array $data, ManifestSchema $schema): void
    {
        $validator = $this->validatorFactory->make(
            $data,
            $schema->rules(),
            $schema->messages(),
            $schema->attributes()
        );

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();

            $this->events?->dispatch(new ManifestValidationFailed($path, $errors));

            throw new ManifestValidationException($path, $errors);
        }
    }

    /**
     * Get the underlying validation factory instance.
     */
    public function getFactory(): ValidationFactoryContract
    {
        return $this->validatorFactory;
    }
}
