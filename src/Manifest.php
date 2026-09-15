<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Events\ManifestMutated;
use AlexKassel\ManifestEngine\Events\ManifestOpened;
use AlexKassel\ManifestEngine\Events\ManifestSaved;
use AlexKassel\ManifestEngine\Events\ManifestSaving;
use AlexKassel\ManifestEngine\Events\ManifestValidationFailed;
use AlexKassel\ManifestEngine\Exceptions\ManifestConcurrentModificationException;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use JsonException;
use ReflectionClass;
use Throwable;

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

    /**
     * In-memory snapshot for rollback.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $snapshot = null;

    /**
     * SHA-1 hash of the file content at last load.
     */
    protected ?string $loadedHash = null;

    protected ?ValidationFactory $validatorFactory = null;

    protected ?Dispatcher $events = null;

    public function __construct(
        public readonly string $path,
        public readonly ?ManifestSchema $schema = null,
        protected Filesystem $files = new Filesystem,
        ?ValidationFactory $validatorFactory = null,
        ?Dispatcher $events = null,
    ) {
        $this->validatorFactory = $validatorFactory;
        $this->events = $events;

        $this->dispatch(new ManifestOpened($this->path));
    }

    /**
     * Open a manifest document.
     */
    public static function open(
        string $path,
        ?ManifestSchema $schema = null,
        ?Filesystem $files = null,
        ?ValidationFactory $validatorFactory = null,
        ?Dispatcher $events = null,
    ): self {
        return new self($path, $schema, $files ?? new Filesystem, $validatorFactory, $events);
    }

    /**
     * Set explicit event dispatcher.
     */
    public function setEventDispatcher(Dispatcher $events): self
    {
        $this->events = $events;

        return $this;
    }

    /**
     * Dispatch an event if dispatcher or container is available.
     */
    protected function dispatch(object $event): void
    {
        if ($this->events !== null) {
            $this->events->dispatch($event);

            return;
        }

        if (class_exists(Container::class) && Container::getInstance()?->bound('events')) {
            /** @var Dispatcher $dispatcher */
            $dispatcher = Container::getInstance()->make('events');
            $dispatcher->dispatch($event);
        }
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
            $errors = $validator->errors()->toArray();
            $this->dispatch(new ManifestValidationFailed($this->path, $errors));

            throw new ManifestValidationException($this->path, $errors);
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
     * Calculate SHA-1 hash of the manifest file on disk.
     */
    public function hash(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        return sha1((string) $this->files->get($this->path));
    }

    /**
     * Get SHA-1 hash of the file as recorded during the last load.
     */
    public function loadedHash(): ?string
    {
        return $this->loadedHash;
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
     * Capture an in-memory snapshot of current manifest data for potential rollback.
     */
    public function snapshot(): self
    {
        $this->snapshot = $this->load();

        return $this;
    }

    /**
     * Rollback manifest state to the last captured snapshot.
     *
     * @throws ManifestException
     */
    public function rollback(): self
    {
        if ($this->snapshot === null) {
            throw new ManifestException("No snapshot available to rollback manifest [{$this->path}].");
        }

        $this->save($this->snapshot);

        return $this;
    }

    /**
     * Invalidate in-memory cache and re-read fresh data from disk.
     */
    public function fresh(): self
    {
        $this->data = null;
        $this->loadedHash = null;
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
                $this->loadedHash = null;

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

        $this->loadedHash = sha1($content);

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

        $this->dispatch(new ManifestSaving($this->path, $data));

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
        $this->loadedHash = sha1($json."\n");

        $this->dispatch(new ManifestSaved($this->path, $data));
    }

    /**
     * Save data with optimistic concurrency verification.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ManifestConcurrentModificationException
     * @throws ManifestException
     */
    public function saveOptimistic(array $data, ?string $expectedHash = null): void
    {
        $expected = $expectedHash ?? $this->loadedHash;
        $currentHash = $this->hash();

        if ($expected !== null && $currentHash !== null && $currentHash !== $expected) {
            throw new ManifestConcurrentModificationException($this->path, $currentHash, $expected);
        }

        $this->save($data);
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
     * Hydrate manifest data into a typed DTO object.
     *
     * @template T of object
     *
     * @param  class-string<T>  $dtoClass
     * @return T
     *
     * @throws ManifestException
     */
    public function toDto(string $dtoClass): object
    {
        $data = $this->all();

        if (is_subclass_of($dtoClass, ManifestDto::class) || method_exists($dtoClass, 'fromArray')) {
            /** @var T */
            return $dtoClass::fromArray($data);
        }

        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            $dto = new $dtoClass;
            foreach ($data as $key => $value) {
                if (property_exists($dto, $key)) {
                    $dto->{$key} = $value;
                }
            }

            /** @var T */
            return $dto;
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $data)) {
                $args[$name] = $data[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[$name] = $param->getDefaultValue();
            }
        }

        /** @var T */
        return $reflection->newInstanceArgs($args);
    }

    /**
     * Save a typed DTO object back to the manifest.
     *
     * @throws ManifestException
     */
    public function saveDto(object $dto): void
    {
        if ($dto instanceof ManifestDto || method_exists($dto, 'toArray')) {
            /** @var array<string, mixed> $data */
            $data = $dto->toArray();
        } else {
            /** @var array<string, mixed> $data */
            $data = get_object_vars($dto);
        }

        $this->save($data);
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
        $previousData = $this->data;

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

            $before = $data;

            try {
                $mutated = $callback($data);
                if (! is_array($mutated)) {
                    throw new ManifestException('Mutation callback must return an array.');
                }

                if ($this->schema !== null) {
                    $this->validate($mutated);
                }
            } catch (Throwable $e) {
                // Auto-rollback in-memory state on mutation or validation failure
                $this->data = $previousData;
                throw $e;
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
            $this->loadedHash = sha1($json."\n");

            $this->dispatch(new ManifestMutated($this->path, $before, $mutated));

            return $mutated;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
