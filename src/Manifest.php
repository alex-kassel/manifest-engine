<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestLockTimeoutException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use ArrayAccess;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;

/**
 * @implements ArrayAccess<string, mixed>
 */
class Manifest implements Arrayable, ArrayAccess, Jsonable, JsonSerializable
{
    public const JSON_ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

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
    ) {
        $this->path = self::resolvePath($path);
        $this->files = $files ?? new Filesystem;
    }

    /**
     * Open a manifest document handler.
     */
    public static function open(
        string $path,
        ?ManifestSchema $schema = null,
        ?Filesystem $files = null,
    ): self {
        return new self(
            path: $path,
            schema: $schema,
            files: $files,
        );
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

        $hash = $this->files->hash($this->path, $algorithm);

        return $hash !== false ? $hash : null;
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
                $this->data = $this->schema->defaults();

                return $this->data;
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
        $this->data = $data;

        return $this->data;
    }

    /**
     * Save data directly into manifest file atomically.
     *
     * @param  array<string, mixed>|Arrayable|null  $data
     *
     * @throws ManifestException
     */
    public function save(array|Arrayable|null $data = null): self
    {
        $payload = match (true) {
            $data instanceof Arrayable => $data->toArray(),
            is_array($data) => $data,
            default => $this->data ?? $this->loadOrDefault(),
        };

        $this->mutate(static fn (array $current): array => $payload);

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

            return $mutated;
        });
    }

    /**
     * Mutate manifest data using a typed DTO inside an atomic lock transaction.
     *
     * @template T of ManifestDto
     *
     * @param  class-string<T>  $dtoClass
     * @param  Closure(T): (T|void)  $mutator
     * @return T
     *
     * @throws ManifestException
     * @throws InvalidArgumentException
     */
    public function mutateDto(string $dtoClass, Closure $mutator): ManifestDto
    {
        if (! is_subclass_of($dtoClass, ManifestDto::class)) {
            throw new InvalidArgumentException("Class [{$dtoClass}] must implement ".ManifestDto::class);
        }

        $resultDto = null;

        $this->mutate(function (array $currentData) use ($dtoClass, $mutator, &$resultDto): array {
            $dto = $dtoClass::fromArray($currentData);

            $mutated = $mutator($dto);
            $finalDto = $mutated instanceof ManifestDto ? $mutated : $dto;

            $resultDto = $finalDto;

            return $finalDto->toArray();
        });

        /** @var T $resultDto */
        return $resultDto;
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
     * Hydrate the manifest data into a typed DTO object.
     *
     * @template T of ManifestDto
     *
     * @param  class-string<T>  $dtoClass
     * @return T
     *
     * @throws InvalidArgumentException
     */
    public function toDto(string $dtoClass): ManifestDto
    {
        if (! is_subclass_of($dtoClass, ManifestDto::class)) {
            throw new InvalidArgumentException("Class [{$dtoClass}] must implement ".ManifestDto::class);
        }

        return $dtoClass::fromArray($this->all());
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
        if (! self::isAbsolutePath($this->path)) {
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
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->all();
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int  $options
     *
     * @throws ManifestException
     */
    public function toJson($options = 0): string
    {
        $flags = $options === 0 ? self::JSON_ENCODE_FLAGS : $options;
        $json = json_encode($this->all(), $flags);

        if ($json === false) {
            throw new ManifestException("Failed to encode manifest JSON for [{$this->path}].");
        }

        return $json;
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->all();
    }

    /**
     * Determine if an offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    /**
     * Get the value at a given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    /**
     * Set the value at a given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new InvalidArgumentException('Cannot append to manifest without an explicit key.');
        }

        $this->set((string) $offset, $value);
    }

    /**
     * Unset the value at a given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->forget((string) $offset);
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
