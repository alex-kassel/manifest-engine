<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use JsonException;

class Manifest
{
    protected ?ValidationFactory $validatorFactory = null;

    public function __construct(
        public readonly string $path,
        public readonly ?ManifestSchema $schema = null,
        protected Filesystem $files = new Filesystem,
        ?ValidationFactory $validatorFactory = null,
    ) {
        $this->validatorFactory = $validatorFactory;
    }

    /**
     * Open a manifest document.
     */
    public static function open(
        string $path,
        ?ManifestSchema $schema = null,
        ?Filesystem $files = null,
        ?ValidationFactory $validatorFactory = null,
    ): self {
        return new self($path, $schema, $files ?? new Filesystem, $validatorFactory);
    }

    /**
     * Set explicit validation factory.
     */
    public function setValidatorFactory(ValidationFactory $factory): self
    {
        $this->validatorFactory = $factory;

        return $this;
    }

    /**
     * Get or create validation factory.
     */
    public function getValidatorFactory(): ValidationFactory
    {
        if ($this->validatorFactory !== null) {
            return $this->validatorFactory;
        }

        if (class_exists(Container::class) && Container::getInstance()?->bound('validator')) {
            /** @var ValidationFactory */
            return Container::getInstance()->make('validator');
        }

        $loader = new ArrayLoader;
        $translator = new Translator($loader, 'en');

        return $this->validatorFactory = new Factory($translator);
    }

    /**
     * Validate data against the schema rules using Laravel's validator.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ManifestValidationException
     */
    public function validate(array $data): void
    {
        if ($this->schema === null) {
            return;
        }

        $validator = $this->getValidatorFactory()->make(
            $data,
            $this->schema->rules(),
            $this->schema->messages(),
            $this->schema->attributes()
        );

        if ($validator->fails()) {
            throw new ManifestValidationException($this->path, $validator->errors()->toArray());
        }
    }

    /**
     * Check if the manifest file exists on disk.
     */
    public function exists(): bool
    {
        return $this->files->exists($this->path);
    }

    /**
     * Initialize the manifest file with default schema state if it doesn't exist.
     */
    public function init(): self
    {
        if (! $this->exists()) {
            $initialData = $this->schema !== null ? $this->schema->defaults() : [];
            $this->save($initialData);
        }

        return $this;
    }

    /**
     * Load manifest content as an array.
     *
     * @return array<string, mixed>
     *
     * @throws ManifestNotFoundException
     * @throws ManifestException
     */
    public function load(): array
    {
        if (! $this->exists()) {
            if ($this->schema !== null) {
                return $this->schema->defaults();
            }

            throw new ManifestNotFoundException($this->path);
        }

        $content = (string) $this->files->get($this->path);
        if (trim($content) === '') {
            return $this->schema !== null ? $this->schema->defaults() : [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ManifestException("Malformed JSON in manifest [{$this->path}]: {$e->getMessage()}", 0, $e);
        }

        if ($this->schema !== null) {
            $this->validate($data);
        }

        return $data;
    }

    /**
     * Save data directly into manifest file atomically.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ManifestException
     */
    public function save(array $data): void
    {
        if ($this->schema !== null) {
            $this->validate($data);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ManifestException("Failed to serialize manifest JSON for [{$this->path}].");
        }

        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->put($this->path, $json."\n", true);
    }

    /**
     * Export the schema's JSON Schema to a file or return as an array.
     *
     * @return array<string, mixed>|null
     *
     * @throws ManifestException
     */
    public function exportJsonSchema(?string $outputPath = null): ?array
    {
        if ($this->schema === null) {
            return null;
        }

        $jsonSchema = $this->schema->jsonSchema();
        if ($jsonSchema === null) {
            return null;
        }

        if ($outputPath !== null) {
            $encoded = json_encode($jsonSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new ManifestException('Failed to serialize JSON Schema for export.');
            }

            $this->files->ensureDirectoryExists(dirname($outputPath));
            $this->files->put($outputPath, $encoded."\n", true);
        }

        return $jsonSchema;
    }

    /**
     * Get a value from the manifest using dot-notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->load(), $key, $default);
    }

    /**
     * Determine if a key exists in the manifest using dot-notation.
     */
    public function has(string $key): bool
    {
        $data = $this->load();

        return Arr::has($data, $key);
    }

    /**
     * Set a value in the manifest using dot-notation and persist atomically.
     */
    public function set(string $key, mixed $value): self
    {
        $this->mutate(function (array $data) use ($key, $value): array {
            data_set($data, $key, $value);

            return $data;
        });

        return $this;
    }

    /**
     * Append a value to an array at given key and persist atomically.
     */
    public function append(string $key, mixed $value): self
    {
        $this->mutate(function (array $data) use ($key, $value): array {
            $current = data_get($data, $key, []);
            if (! is_array($current)) {
                $current = [$current];
            }

            $current[] = $value;
            data_set($data, $key, $current);

            return $data;
        });

        return $this;
    }

    /**
     * Mutate manifest data inside an exclusive file lock transaction.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     * @return array<string, mixed>
     *
     * @throws ManifestException
     */
    public function mutate(callable $callback): array
    {
        $this->files->ensureDirectoryExists(dirname($this->path));

        $fp = fopen($this->path, 'c+');
        if (! $fp) {
            throw new ManifestException("Failed to open manifest file [{$this->path}] for mutation.");
        }

        try {
            if (! flock($fp, LOCK_EX)) {
                throw new ManifestException("Failed to acquire exclusive lock on manifest [{$this->path}].");
            }

            $stat = fstat($fp);
            $size = $stat['size'] ?? 0;
            $content = $size > 0 ? fread($fp, $size) : '';

            $data = [];
            if ($content !== false && trim($content) !== '') {
                try {
                    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new ManifestException("Malformed JSON in manifest [{$this->path}]: {$e->getMessage()}", 0, $e);
                }
            } elseif ($this->schema !== null) {
                $data = $this->schema->defaults();
            }

            $mutated = $callback($data);
            if (! is_array($mutated)) {
                throw new ManifestException('Mutation callback must return an array.');
            }

            if ($this->schema !== null) {
                $this->validate($mutated);
            }

            $json = json_encode($mutated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new ManifestException("Failed to serialize manifest JSON for [{$this->path}].");
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json."\n");
            fflush($fp);

            return $mutated;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
