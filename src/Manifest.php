<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Contracts\StorageDriver;
use AlexKassel\ManifestEngine\Events\ManifestMutated;
use AlexKassel\ManifestEngine\Events\ManifestOpened;
use AlexKassel\ManifestEngine\Events\ManifestSaved;
use AlexKassel\ManifestEngine\Events\ManifestSaving;
use AlexKassel\ManifestEngine\Exceptions\ManifestConcurrentModificationException;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;
use AlexKassel\ManifestEngine\Hydration\DtoHydrator;
use AlexKassel\ManifestEngine\Storage\AtomicFileStorage;
use AlexKassel\ManifestEngine\Validation\ManifestValidator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use JsonException;
use Throwable;

class Manifest
{
    public const JSON_ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    public const JSON_DECODE_DEPTH = 512;

    public const DEFAULT_EMPTY_CONTENT = '';

    public const NEWLINE = "\n";

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
     * In-memory snapshot for rollback.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $snapshot = null;

    /**
     * Indicates whether in-memory data has unpersisted changes.
     */
    protected bool $isDirty = false;

    /**
     * File modification time recorded at last load.
     */
    protected ?int $lastLoadedMtime = null;

    /**
     * SHA-1 hash of the file content at last load.
     */
    protected ?string $loadedHash = null;

    protected StorageDriver $storage;

    protected ManifestValidator $validator;

    protected DtoHydrator $hydrator;

    public function __construct(
        public readonly string $path,
        public readonly ?ManifestSchema $schema = null,
        ?StorageDriver $storage = null,
        ?ManifestValidator $validator = null,
        ?DtoHydrator $hydrator = null,
        protected ?Dispatcher $events = null,
    ) {
        $this->storage = $storage ?? new AtomicFileStorage;
        $this->validator = $validator ?? ManifestValidator::createStandalone($this->events);
        $this->hydrator = $hydrator ?? new DtoHydrator;

        $this->dispatch(new ManifestOpened($this->path));
    }

    /**
     * Open a manifest document handler.
     */
    public static function open(
        string $path,
        ?ManifestSchema $schema = null,
        StorageDriver|Filesystem|null $storage = null,
        ?ManifestValidator $validator = null,
        ?DtoHydrator $hydrator = null,
        ?Dispatcher $events = null,
        ?Filesystem $files = null,
        ?ValidationFactory $validatorFactory = null,
    ): self {
        return ManifestFactory::create(
            path: $path,
            schema: $schema,
            storage: $storage,
            validator: $validator,
            hydrator: $hydrator,
            events: $events,
            files: $files,
            validationFactory: $validatorFactory,
        );
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
     * Set explicit storage driver.
     */
    public function setStorage(StorageDriver $storage): self
    {
        $this->storage = $storage;

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
        return $this->storage->exists($this->path);
    }

    /**
     * Calculate SHA-1 hash of the manifest file on disk.
     */
    public function hash(): ?string
    {
        return $this->storage->hash($this->path);
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
        $this->loadedHash = null;
        $this->lastLoadedMtime = null;
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
            if ($this->isDirty) {
                return $this->data;
            }

            if ($this->exists()) {
                clearstatcache(true, $this->path);
                $mtime = @filemtime($this->path);
                if ($mtime !== false && $mtime === $this->lastLoadedMtime) {
                    return $this->data;
                }
            }
        }

        if (! $this->exists()) {
            if ($this->schema !== null) {
                $this->loadedHash = null;
                $this->lastLoadedMtime = null;
                $this->isDirty = false;

                return $this->data = $this->schema->defaults();
            }

            throw new ManifestNotFoundException($this->path);
        }

        $content = $this->storage->readLocked($this->path);
        $this->loadedHash = sha1($content);
        $this->lastLoadedMtime = @filemtime($this->path) ?: null;
        $this->isDirty = false;

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

        $payload = $json.self::NEWLINE;
        $this->storage->writeAtomic($this->path, $payload);

        $this->data = $payloadData;
        $this->loadedHash = sha1($payload);
        clearstatcache(true, $this->path);
        $this->lastLoadedMtime = @filemtime($this->path) ?: null;
        $this->isDirty = false;

        $this->dispatch(new ManifestSaved($this->path, $payloadData));

        return $this;
    }

    /**
     * Save data with optimistic concurrency verification.
     *
     * @param  array<string, mixed>|null  $data
     *
     * @throws ManifestConcurrentModificationException
     * @throws ManifestException
     */
    public function saveOptimistic(?array $data = null, ?string $expectedHash = null): self
    {
        $expected = $expectedHash ?? $this->loadedHash;
        $currentHash = $this->hash();

        if ($expected !== null && $currentHash !== null && $currentHash !== $expected) {
            throw new ManifestConcurrentModificationException($this->path, $currentHash, $expected);
        }

        return $this->save($data);
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

            $this->storage->writeAtomic($outputPath, $encoded.self::NEWLINE);
        }

        return $jsonSchema;
    }

    /**
     * Read manifest contents into an in-memory ManifestDocument object.
     *
     * @throws ManifestException
     */
    public function read(): ManifestDocument
    {
        return new ManifestDocument($this->load(), $this->hydrator);
    }

    /**
     * Persist an in-memory ManifestDocument or array to disk atomically.
     *
     * @param  ManifestDocument|array<string, mixed>  $document
     *
     * @throws ManifestException
     */
    public function write(ManifestDocument|array $document): self
    {
        $data = $document instanceof ManifestDocument ? $document->all() : $document;

        $this->save($data);

        if ($document instanceof ManifestDocument) {
            $document->resetDirty();
        }

        return $this;
    }

    /**
     * Execute multiple in-memory operations inside an exclusive file lock transaction.
     *
     * @param  callable(ManifestDocument): (ManifestDocument|void)  $callback
     *
     * @throws ManifestException
     */
    public function transaction(callable $callback): ManifestDocument
    {
        $docResult = null;

        $this->mutate(function (array $data) use ($callback, &$docResult): array {
            $document = new ManifestDocument($data, $this->hydrator);

            $result = $callback($document);

            $docResult = $result instanceof ManifestDocument ? $result : $document;

            return $docResult->all();
        });

        /** @var ManifestDocument $docResult */
        return $docResult;
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
        $previousData = $this->data;
        $before = self::DEFAULT_EMPTY_DATA;
        $mutatedData = self::DEFAULT_EMPTY_DATA;

        try {
            $this->storage->mutateLocked($this->path, function (string $content) use ($callback, &$before, &$mutatedData): string {
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

                $mutatedData = $mutated;

                return $json.self::NEWLINE;
            });
        } catch (Throwable $e) {
            $this->data = $previousData;
            throw $e;
        }

        $this->data = $mutatedData;
        $this->loadedHash = sha1(json_encode($mutatedData, self::JSON_ENCODE_FLAGS).self::NEWLINE);
        clearstatcache(true, $this->path);
        $this->lastLoadedMtime = @filemtime($this->path) ?: null;
        $this->isDirty = false;

        $this->dispatch(new ManifestMutated($this->path, $before, $mutatedData));

        return $mutatedData;
    }
}
