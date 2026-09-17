<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Events\ManifestMutated;
use AlexKassel\ManifestEngine\Events\ManifestOpened;
use AlexKassel\ManifestEngine\Events\ManifestSaved;
use AlexKassel\ManifestEngine\Events\ManifestSaving;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestLockTimeoutException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Throwable;

class Manifest
{
    public const JSON_ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    public const JSON_DECODE_DEPTH = 512;

    public const DEFAULT_LOCK_TTL_SECONDS = 30;

    public const DEFAULT_LOCK_TIMEOUT_SECONDS = 10;

    public const DEFAULT_HASH_ALGO = 'sha1';

    public const LOCK_KEY_PREFIX = 'manifest:';

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

    public int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS;

    public int $lockTtlSeconds = self::DEFAULT_LOCK_TTL_SECONDS;

    public readonly string $path;

    protected Filesystem $files;

    public function __construct(
        string $path,
        public readonly ?ManifestSchema $schema = null,
        ?Filesystem $files = null,
        protected ?Dispatcher $events = null,
    ) {
        $this->path = self::resolvePath($path);
        $this->files = $files ?? new Filesystem;

        $this->dispatch(new ManifestOpened($this->path));
    }

    /**
     * Open a manifest document handler.
     */
    public static function open(
        string $path,
        ?ManifestSchema $schema = null,
        ?Filesystem $files = null,
        ?Dispatcher $events = null,
    ): self {
        return new self(
            path: $path,
            schema: $schema,
            files: $files,
            events: $events,
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
     * Resolve unique lock key for this manifest file.
     */
    public function lockKey(): string
    {
        return self::LOCK_KEY_PREFIX.sha1($this->canonicalPath());
    }

    /**
     * Execute an operation under atomic lock protection.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     *
     * @throws ManifestLockTimeoutException
     */
    protected function withLock(Closure $operation): mixed
    {
        if (class_exists(Container::class)) {
            $container = Container::getInstance();
            if ($container !== null && $container->bound('cache')) {
                try {
                    return Cache::lock($this->lockKey(), $this->lockTtlSeconds)
                        ->block($this->lockTimeoutSeconds, $operation);
                } catch (LockTimeoutException $e) {
                    throw new ManifestLockTimeoutException($this->path, $this->lockTimeoutSeconds, $e);
                }
            }
        }

        return $operation();
    }

    /**
     * Dispatch an event if dispatcher is available.
     */
    protected function dispatch(object $event): void
    {
        $this->events?->dispatch($event);
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
     * Load existing manifest data, or return schema defaults / empty array if not found.
     *
     * @return array<string, mixed>
     */
    protected function loadOrDefault(): array
    {
        try {
            return $this->load();
        } catch (ManifestNotFoundException) {
            return $this->schema !== null ? $this->schema->defaults() : [];
        }
    }

    /**
     * Initialize the manifest file with default schema state if it doesn't exist.
     */
    public function init(): self
    {
        if (! $this->exists()) {
            $this->save($this->loadOrDefault());
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
     * Invalidate in-memory cache without eagerly reloading from disk.
     */
    public function invalidate(): self
    {
        $this->data = null;
        $this->isDirty = false;

        return $this;
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
        return $this->withLock(function () use ($data): self {
            $payloadData = $data ?? $this->data ?? $this->loadOrDefault();

            $this->dispatch(new ManifestSaving($this->path, $payloadData));

            $json = json_encode($payloadData, self::JSON_ENCODE_FLAGS);
            if ($json === false) {
                throw new ManifestException("Failed to serialize manifest JSON for [{$this->path}].");
            }

            $this->files->ensureDirectoryExists(dirname($this->path));
            $this->files->replace($this->path, $json."\n");

            $this->data = $payloadData;
            $this->isDirty = false;

            $this->dispatch(new ManifestSaved($this->path, $payloadData));

            return $this;
        });
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
        return $this->withLock(function () use ($callback): array {
            $currentData = [];

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

            $json = json_encode($mutated, self::JSON_ENCODE_FLAGS);
            if ($json === false) {
                throw new ManifestException("Failed to serialize manifest JSON for [{$this->path}].");
            }

            $this->files->ensureDirectoryExists(dirname($this->path));
            $this->files->replace($this->path, $json."\n");

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
     * Hydrate the manifest data into a typed DTO object or custom structure.
     *
     * @template T of object
     *
     * @param  class-string<T>|callable(array<string, mixed>): T  $target
     * @return T
     *
     * @throws ManifestException
     */
    public function toDto(string|callable $target): object
    {
        if (is_callable($target)) {
            return $target($this->all());
        }

        if (is_subclass_of($target, ManifestDto::class) || method_exists($target, 'fromArray')) {
            return $target::fromArray($this->all());
        }

        if (method_exists($target, 'from')) {
            return $target::from($this->all());
        }

        try {
            return new $target(...$this->all());
        } catch (Throwable $e) {
            throw new ManifestException("Failed to instantiate DTO [{$target}]: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Save a typed DTO object back to the manifest.
     *
     * @throws ManifestException
     */
    public function saveDto(object $dto): void
    {
        if ($dto instanceof Arrayable || method_exists($dto, 'toArray')) {
            $this->save($dto->toArray());

            return;
        }

        if ($dto instanceof JsonSerializable) {
            $data = $dto->jsonSerialize();
            $this->save(is_array($data) ? $data : (array) $data);

            return;
        }

        $this->save(get_object_vars($dto));
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
        $data = $this->loadOrDefault();

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
        $data = $this->loadOrDefault();

        $current = data_get($data, $key, []);
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
        $data = $this->loadOrDefault();

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
            $this->files->replace($outputPath, $encoded."\n");
        }

        return $jsonSchema;
    }

    /**
     * Resolve canonical path for unique lock identification.
     */
    protected function canonicalPath(): string
    {
        $realPath = realpath($this->path);
        if ($realPath !== false) {
            return $realPath;
        }

        $dirname = dirname($this->path);
        $realDir = realpath($dirname);
        if ($realDir !== false) {
            return rtrim($realDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($this->path);
        }

        $base = function_exists('base_path') ? base_path() : (string) (getcwd() ?: '.');
        if (! str_starts_with($this->path, DIRECTORY_SEPARATOR)) {
            $combined = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->path;
            $realCombinedDir = realpath(dirname($combined));
            if ($realCombinedDir !== false) {
                return rtrim($realCombinedDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($combined);
            }

            return $combined;
        }

        return $this->path;
    }

    /**
     * Determine if given path is an absolute filesystem path.
     */
    public static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':');
    }

    /**
     * Resolve a relative or absolute path to a fully-qualified absolute filesystem path.
     */
    public static function resolvePath(string $path, ?string $basePath = null): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Manifest path cannot be empty.');
        }

        if (self::isAbsolutePath($trimmed)) {
            return $trimmed;
        }

        $base = $basePath ?? (function_exists('base_path') ? base_path() : (string) (getcwd() ?: '.'));

        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed), DIRECTORY_SEPARATOR);
    }

    /**
     * Normalize a path into a clean relative filename relative to project base path.
     */
    public static function normalizeFilename(string $path, ?string $basePath = null): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        $base = $basePath ?? (function_exists('base_path') ? base_path() : (string) (getcwd() ?: '.'));
        $cleanBase = rtrim(str_replace('\\', '/', $base), '/');
        $cleanPath = str_replace('\\', '/', $trimmed);

        if (str_starts_with($cleanPath, $cleanBase)) {
            $cleanPath = substr($cleanPath, strlen($cleanBase));
        }

        return ltrim($cleanPath, '/');
    }
}
