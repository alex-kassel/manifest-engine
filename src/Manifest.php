<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestLockTimeoutException;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use JsonSerializable;

class Manifest implements Arrayable, Jsonable, JsonSerializable
{
    protected ?array $data = null;

    public int $lockTimeoutSeconds = 10;

    public int $lockTtlSeconds = 30;

    public function __construct(
        public readonly string $path,
        public ?ManifestSchema $schema = null,
    ) {
        $this->schema ??= $this->resolveSchema();
    }

    /**
     * Determine if the manifest file exists on disk.
     */
    public function exists(): bool
    {
        return File::exists($this->path);
    }

    /**
     * Resolve the unique lock key for this manifest file.
     */
    public function lockKey(): string
    {
        return 'manifest:'.sha1($this->path);
    }

    /**
     * Calculate hash of the manifest file on disk.
     */
    public function hash(string $algorithm = 'sha1'): ?string
    {
        return $this->exists() ? (File::hash($this->path, $algorithm) ?: null) : null;
    }

    /**
     * Load manifest data from disk or fallback to schema defaults.
     *
     * @return array<string, mixed>
     *
     * @throws ManifestNotFoundException
     */
    public function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (! $this->exists()) {
            return $this->data = $this->schema?->defaults() ?? throw new ManifestNotFoundException($this->path);
        }

        try {
            return $this->data = File::json($this->path, flags: JSON_THROW_ON_ERROR, lock: true);
        } catch (\JsonException $e) {
            throw new ManifestException("Malformed JSON in manifest [{$this->path}]: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Initialize the manifest file with default schema state if it doesn't exist.
     *
     * @return array<string, mixed>
     */
    public function init(): array
    {
        return ! $this->exists()
            ? $this->mutate(fn () => $this->schema?->defaults() ?? [])
            : $this->load();
    }

    /**
     * Save raw data or Arrayable directly into the manifest file under atomic lock.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function save(array|Arrayable $data): array
    {
        $payload = $data instanceof Arrayable ? $data->toArray() : $data;

        return $this->mutate(static fn (): array => $payload);
    }

    /**
     * Invalidate in-memory cached data.
     */
    public function invalidate(): self
    {
        $this->data = null;

        return $this;
    }

    /**
     * Export the JSON schema specification, optionally saving to disk.
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

        if ($outputPath !== null) {
            $encoded = json_encode($jsonSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new ManifestException('Failed to serialize JSON Schema for export.');
            }

            File::ensureDirectoryExists(dirname($outputPath));
            File::replace($outputPath, $encoded."\n");
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
     * Invalidate in-memory cache and re-read fresh data from disk.
     *
     * @return array<string, mixed>
     */
    public function fresh(): array
    {
        $this->data = null;

        return $this->load();
    }

    /**
     * Mutate manifest data inside an atomic lock transaction and persist to disk.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     * @return array<string, mixed>
     *
     * @throws ManifestLockTimeoutException
     * @throws ManifestException
     */
    public function mutate(callable $callback): array
    {
        try {
            return Cache::lock($this->lockKey(), $this->lockTtlSeconds)->block($this->lockTimeoutSeconds, function () use ($callback): array {
                try {
                    $current = $this->exists()
                        ? File::json($this->path, flags: JSON_THROW_ON_ERROR, lock: true)
                        : ($this->schema?->defaults() ?? []);
                } catch (\JsonException $e) {
                    throw new ManifestException("Malformed JSON in manifest [{$this->path}]: {$e->getMessage()}", 0, $e);
                }

                $mutated = $callback($current);

                File::ensureDirectoryExists(dirname($this->path));
                File::replace(
                    $this->path,
                    json_encode($mutated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
                );

                return $this->data = $mutated;
            });
        } catch (LockTimeoutException $e) {
            throw new ManifestLockTimeoutException($this->path, $this->lockTimeoutSeconds, $e);
        }
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
     * Set a value using dot-notation and return the mutated manifest data.
     *
     * @return array<string, mixed>
     */
    public function set(string $key, mixed $value): array
    {
        return $this->mutate(function (array $data) use ($key, $value): array {
            data_set($data, $key, $value);

            return $data;
        });
    }

    /**
     * Append a value to an array at given key and return the mutated manifest data.
     *
     * @return array<string, mixed>
     */
    public function append(string $key, mixed $value): array
    {
        return $this->mutate(function (array $data) use ($key, $value): array {
            $current = data_get($data, $key, []);
            if (! is_array($current)) {
                $current = [$current];
            }
            $current[] = $value;
            data_set($data, $key, $current);

            return $data;
        });
    }

    /**
     * Forget a key using dot-notation and return the mutated manifest data.
     *
     * @return array<string, mixed>
     */
    public function forget(string $key): array
    {
        return $this->mutate(function (array $data) use ($key): array {
            Arr::forget($data, $key);

            return $data;
        });
    }

    /**
     * Hydrate the manifest data into a typed DTO object.
     *
     * @template T of ManifestDto
     *
     * @param  class-string<T>  $dtoClass
     * @return T
     */
    public function toDto(string $dtoClass): ManifestDto
    {
        return $dtoClass::fromArray($this->load());
    }

    /**
     * Mutate manifest data using a typed DTO inside an atomic lock transaction.
     *
     * @template T of ManifestDto
     *
     * @param  class-string<T>  $dtoClass
     * @param  Closure(T): (T|void)  $mutator
     * @return T
     */
    public function mutateDto(string $dtoClass, Closure $mutator): ManifestDto
    {
        $resultDto = null;

        $this->mutate(function (array $current) use ($dtoClass, $mutator, &$resultDto): array {
            $dto = $dtoClass::fromArray($current);
            $mutated = $mutator($dto);
            $resultDto = $mutated instanceof ManifestDto ? $mutated : $dto;

            return $resultDto->toArray();
        });

        /** @var T $resultDto */
        return $resultDto;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->load();
    }

    /**
     * Convert the object to its JSON representation.
     */
    public function toJson($options = 0): string
    {
        $flags = $options === 0
            ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            : $options;

        return json_encode($this->load(), $flags);
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->load();
    }

    /**
     * Resolve the schema for this manifest from the application registry.
     */
    protected function resolveSchema(): ?ManifestSchema
    {
        return app(ManifestRegistry::class)->findByPath($this->path)?->schema;
    }
}
