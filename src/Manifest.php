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
    public const JSON_ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    public const JSON_DECODE_DEPTH = 512;

    public const DEFAULT_TRANSLATOR_LOCALE = 'en';

    public const DEFAULT_STAT_SIZE = 0;

    public const DEFAULT_EMPTY_CONTENT = '';

    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_EMPTY_DATA = [];

    /**
     * @var array<int, mixed>
     */
    public const DEFAULT_APPEND_EMPTY_ARRAY = [];

    public const TEMP_FILE_PREFIX = '.tmp.';

    /**
     * In-memory cache of loaded manifest data.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $data = null;

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
        $translator = new Translator($loader, self::DEFAULT_TRANSLATOR_LOCALE);

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
            $initialData = $this->schema !== null ? $this->schema->defaults() : self::DEFAULT_EMPTY_DATA;
            $this->save($initialData);
        }

        return $this;
    }

    /**
     * Invalidate in-memory cache and re-read fresh data from disk.
     */
    public function fresh(): self
    {
        $this->data = null;
        $this->load(forceFresh: true);

        return $this;
    }

    /**
     * Alias for fresh().
     */
    public function reload(): self
    {
        return $this->fresh();
    }

    /**
     * Load manifest content as an array with concurrency shared lock protection.
     *
     * @return array<string, mixed>
     *
     * @throws ManifestNotFoundException
     * @throws ManifestException
     */
    public function load(bool $forceFresh = false): array
    {
        if (! $forceFresh && $this->data !== null) {
            return $this->data;
        }

        if (! $this->exists()) {
            if ($this->schema !== null) {
                return $this->data = $this->schema->defaults();
            }

            throw new ManifestNotFoundException($this->path);
        }

        $fp = fopen($this->path, 'r');
        if (! $fp) {
            throw new ManifestException("Failed to open manifest file [{$this->path}] for reading.");
        }

        try {
            if (! flock($fp, LOCK_SH)) {
                throw new ManifestException("Failed to acquire shared lock on manifest [{$this->path}].");
            }

            $stat = fstat($fp);
            $size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : self::DEFAULT_STAT_SIZE;
            $content = $size > self::DEFAULT_STAT_SIZE ? (string) fread($fp, $size) : self::DEFAULT_EMPTY_CONTENT;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        if (trim($content) === self::DEFAULT_EMPTY_CONTENT) {
            $data = $this->schema !== null ? $this->schema->defaults() : self::DEFAULT_EMPTY_DATA;

            return $this->data = $data;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($content, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ManifestException("Malformed JSON in manifest [{$this->path}]: {$e->getMessage()}", 0, $e);
        }

        if ($this->schema !== null) {
            $this->validate($data);
        }

        return $this->data = $data;
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

        $json = json_encode($data, self::JSON_ENCODE_FLAGS);
        if ($json === false) {
            throw new ManifestException("Failed to serialize manifest JSON for [{$this->path}].");
        }

        $directory = dirname($this->path);
        $this->files->ensureDirectoryExists($directory);

        $tempPath = $directory.DIRECTORY_SEPARATOR.basename($this->path).self::TEMP_FILE_PREFIX.uniqid('', true);

        $fp = fopen($tempPath, 'w');
        if (! $fp) {
            throw new ManifestException("Failed to open temporary manifest file [{$tempPath}] for writing.");
        }

        try {
            fwrite($fp, $json."\n");
            fflush($fp);
        } finally {
            fclose($fp);
        }

        if (! @rename($tempPath, $this->path)) {
            @unlink($tempPath);
            throw new ManifestException("Failed to atomically rename temporary file [{$tempPath}] to [{$this->path}].");
        }

        $this->data = $data;
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
            $encoded = json_encode($jsonSchema, self::JSON_ENCODE_FLAGS);
            if ($encoded === false) {
                throw new ManifestException('Failed to serialize JSON Schema for export.');
            }

            $this->files->ensureDirectoryExists(dirname($outputPath));
            $this->files->put($outputPath, $encoded."\n", true);
        }

        return $jsonSchema;
    }

    /**
     * Get all manifest data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->load();
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
        return Arr::has($this->load(), $key);
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
            $current = data_get($data, $key, self::DEFAULT_APPEND_EMPTY_ARRAY);
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
     * Forget/remove a nested key from the manifest and persist atomically.
     */
    public function forget(string $key): self
    {
        $this->mutate(function (array $data) use ($key): array {
            Arr::forget($data, $key);

            return $data;
        });

        return $this;
    }

    /**
     * Execute multiple modifications in a single locked transaction.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     */
    public function batch(callable $callback): self
    {
        $this->mutate($callback);

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
            $size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : self::DEFAULT_STAT_SIZE;
            $content = $size > self::DEFAULT_STAT_SIZE ? (string) fread($fp, $size) : self::DEFAULT_EMPTY_CONTENT;

            $data = self::DEFAULT_EMPTY_DATA;
            if (trim($content) !== self::DEFAULT_EMPTY_CONTENT) {
                try {
                    /** @var array<string, mixed> $data */
                    $data = json_decode($content, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
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

            $json = json_encode($mutated, self::JSON_ENCODE_FLAGS);
            if ($json === false) {
                throw new ManifestException("Failed to serialize manifest JSON for [{$this->path}].");
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json."\n");
            fflush($fp);

            $this->data = $mutated;

            return $mutated;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
