<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Events\ManifestMutated;
use AlexKassel\ManifestEngine\Events\ManifestOpened;
use AlexKassel\ManifestEngine\Events\ManifestSaved;
use AlexKassel\ManifestEngine\Events\ManifestSaving;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;
use AlexKassel\ManifestEngine\Hydration\DtoHydrator;
use AlexKassel\ManifestEngine\Validation\ManifestValidator;
use Illuminate\Cache\FileStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use JsonException;

class Manifest
{
    public const JSON_ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    public const JSON_DECODE_DEPTH = 512;

    public const NEWLINE = "\n";

    public const DEFAULT_LOCK_TTL_SECONDS = 30;

    public const DEFAULT_LOCK_TIMEOUT_SECONDS = 10;

    public const DEFAULT_HASH_ALGO = 'sha1';

    public const LOCK_KEY_PREFIX = 'manifest:';

    public const FALLBACK_LOCK_FOLDER = 'manifest-locks';

    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_EMPTY_DATA = [];

    /**
     * @var array<int, mixed>
     */
    public const DEFAULT_APPEND_EMPTY_ARRAY = [];

    /**
     * In-memory cache of loaded manifest data.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $data = null;

    /**
     * Indicates whether in-memory data has unpersisted changes.
     */
    protected bool $isDirty = false;

    protected Filesystem $files;

    protected LockProvider $lockProvider;

    protected ManifestValidator $validator;

    protected DtoHydrator $hydrator;

    public function __construct(
        public readonly string $path,
        public readonly ?ManifestSchema $schema = null,
        ?Filesystem $files = null,
        ?LockProvider $lockProvider = null,
        ?ManifestValidator $validator = null,
        ?DtoHydrator $hydrator = null,
        protected ?Dispatcher $events = null,
    ) {
        $this->files = $files ?? new Filesystem;
        $this->lockProvider = $lockProvider ?? $this->resolveDefaultLockProvider();
        $this->validator = $validator ?? $this->resolveDefaultValidator();
        $this->hydrator = $hydrator ?? new DtoHydrator;

        $this->dispatch(new ManifestOpened($this->path));
    }

    /**
     * Open a manifest document handler.
     */
    public static function open(
        string $path,
        ?ManifestSchema $schema = null,
        ?Filesystem $files = null,
        ?LockProvider $lockProvider = null,
        ?ManifestValidator $validator = null,
        ?DtoHydrator $hydrator = null,
        ?Dispatcher $events = null,
    ): self {
        return new self(
            path: $path,
            schema: $schema,
            files: $files,
            lockProvider: $lockProvider,
            validator: $validator,
            hydrator: $hydrator,
            events: $events,
        );
    }

    /**
     * Resolve default lock provider from container or fallback to FileStore.
     */
    protected function resolveDefaultLockProvider(): LockProvider
    {
        if (class_exists(Container::class)) {
            $container = Container::getInstance();
            if ($container !== null && $container->bound('cache')) {
                $store = $container->make('cache')->store()->getStore();
                if ($store instanceof LockProvider) {
                    return $store;
                }
            }
        }

        $locksDir = function_exists('storage_path')
            ? storage_path('framework'.DIRECTORY_SEPARATOR.self::FALLBACK_LOCK_FOLDER)
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.self::FALLBACK_LOCK_FOLDER;

        return new FileStore($this->files, $locksDir);
    }

    /**
     * Resolve default validator instance.
     */
    protected function resolveDefaultValidator(): ManifestValidator
    {
        return ManifestValidator::createStandalone($this->events);
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
     * Set explicit manifest validator.
     */
    public function setValidator(ManifestValidator $validator): self
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Set explicit lock provider.
     */
    public function setLockProvider(LockProvider $lockProvider): self
    {
        $this->lockProvider = $lockProvider;

        return $this;
    }

    /**
     * Set explicit DTO hydrator.
     */
    public function setHydrator(DtoHydrator $hydrator): self
    {
        $this->hydrator = $hydrator;

        return $this;
    }

    /**
     * Dispatch an event if dispatcher is available.
     */
    protected function dispatch(object $event): void
    {
        $this->events?->dispatch($event);
    }

    /**
     * Validate data against the schema rules.
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

        $this->validator->validate($this->path, $data, $this->schema);
    }

    /**
     * Check if the manifest file exists on disk.
     */
    public function exists(): bool
    {
        return $this->files->exists($this->path);
    }

    /**
     * Calculate hash of the manifest file on disk.
     */
    public function hash(string $algorithm = self::DEFAULT_HASH_ALGO): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        return $this->files->hash($this->path, $algorithm);
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
     * Determine if in-memory data has unpersisted changes.
     */
    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    /**
     * Invalidate in-memory cache and re-read fresh data from disk.
     */
    public function fresh(): self
    {
        $this->data = null;
        $this->isDirty = false;
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
     * Load manifest content as an array with shared lock protection.
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
                $this->isDirty = false;

                return $this->data = $this->schema->defaults();
            }

            throw new ManifestNotFoundException($this->path);
        }

        try {
            /** @var array<string, mixed> $data */
            $data = $this->files->json($this->path, flags: JSON_THROW_ON_ERROR, lock: true);
        } catch (JsonException $e) {
            throw new ManifestException("Malformed JSON in manifest [{$this->path}]: {$e->getMessage()}", 0, $e);
        }

        if ($this->schema !== null) {
            $this->validate($data);
        }

        $this->isDirty = false;

        return $this->data = $data;
    }

    /**
     * Save data directly into manifest file atomically using Filesystem::replace.
     *
     * @param  array<string, mixed>|null  $data
     *
     * @throws ManifestException
     */
    public function save(?array $data = null): self
    {
        $payloadData = $data ?? $this->data ?? ($this->schema !== null ? $this->schema->defaults() : self::DEFAULT_EMPTY_DATA);

        if ($this->schema !== null) {
            $this->validate($payloadData);
        }

        $this->dispatch(new ManifestSaving($this->path, $payloadData));

        $json = json_encode($payloadData, self::JSON_ENCODE_FLAGS);
        if ($json === false) {
            throw new ManifestException("Failed to serialize manifest JSON for [{$this->path}].");
        }

        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->replace($this->path, $json.self::NEWLINE);

        $this->data = $payloadData;
        $this->isDirty = false;

        $this->dispatch(new ManifestSaved($this->path, $payloadData));

        return $this;
    }

    /**
     * Mutate manifest data inside an atomic lock transaction.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     * @return array<string, mixed>
     *
     * @throws ManifestException
     */
    public function mutate(callable $callback): array
    {
        $canonicalPath = $this->canonicalPath();
        $lockKey = self::LOCK_KEY_PREFIX.hash('sha256', $canonicalPath);
        $lock = $this->lockProvider->lock($lockKey, self::DEFAULT_LOCK_TTL_SECONDS);

        return $lock->block(self::DEFAULT_LOCK_TIMEOUT_SECONDS, function () use ($callback): array {
            $currentData = self::DEFAULT_EMPTY_DATA;

            if ($this->exists()) {
                try {
                    /** @var array<string, mixed> $currentData */
                    $currentData = $this->files->json($this->path, flags: JSON_THROW_ON_ERROR, lock: true);
                } catch (JsonException $e) {
                    throw new ManifestException("Malformed JSON in manifest [{$this->path}]: {$e->getMessage()}", 0, $e);
                }
            } elseif ($this->schema !== null) {
                $currentData = $this->schema->defaults();
            }

            $before = $currentData;
            $mutated = $callback($currentData);

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

            $this->files->ensureDirectoryExists(dirname($this->path));
            $this->files->replace($this->path, $json.self::NEWLINE);

            $this->data = $mutated;
            $this->isDirty = false;

            $this->dispatch(new ManifestMutated($this->path, $before, $mutated));

            return $mutated;
        });
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
        return $this->hydrator->hydrate($dtoClass, $this->all());
    }

    /**
     * Save a typed DTO object back to the manifest.
     *
     * @throws ManifestException
     */
    public function saveDto(object $dto): void
    {
        $this->save($this->hydrator->serialize($dto));
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
     * Set a value in the manifest using dot-notation.
     */
    public function set(string $key, mixed $value): self
    {
        try {
            $data = $this->load();
        } catch (ManifestNotFoundException) {
            $data = $this->schema !== null ? $this->schema->defaults() : self::DEFAULT_EMPTY_DATA;
        }

        data_set($data, $key, $value);
        $this->data = $data;
        $this->isDirty = true;

        return $this;
    }

    /**
     * Append a value to an array at given key.
     */
    public function append(string $key, mixed $value): self
    {
        try {
            $data = $this->load();
        } catch (ManifestNotFoundException) {
            $data = $this->schema !== null ? $this->schema->defaults() : self::DEFAULT_EMPTY_DATA;
        }

        $current = data_get($data, $key, self::DEFAULT_APPEND_EMPTY_ARRAY);
        if (! is_array($current)) {
            $current = [$current];
        }

        $current[] = $value;
        data_set($data, $key, $current);
        $this->data = $data;
        $this->isDirty = true;

        return $this;
    }

    /**
     * Forget/remove a nested key from the manifest.
     */
    public function forget(string $key): self
    {
        try {
            $data = $this->load();
        } catch (ManifestNotFoundException) {
            $data = $this->schema !== null ? $this->schema->defaults() : self::DEFAULT_EMPTY_DATA;
        }

        Arr::forget($data, $key);
        $this->data = $data;
        $this->isDirty = true;

        return $this;
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
            $this->files->replace($outputPath, $encoded.self::NEWLINE);
        }

        return $jsonSchema;
    }

    /**
     * Resolve canonical path for unique lock identification.
     */
    protected function canonicalPath(): string
    {
        $dirname = dirname($this->path);
        $realDir = realpath($dirname) ?: $dirname;

        return rtrim($realDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($this->path);
    }
}
