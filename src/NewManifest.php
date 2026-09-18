<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Exceptions\ManifestNotFoundException;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use JsonSerializable;

class NewManifest implements Arrayable, JsonSerializable
{
    protected ?array $data = null;

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

        return $this->data = File::json($this->path, flags: JSON_THROW_ON_ERROR, lock: true);
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
     */
    public function mutate(callable $callback): array
    {
        return Cache::lock('manifest:'.sha1($this->path), 30)->block(10, function () use ($callback): array {
            $current = $this->exists()
                ? File::json($this->path, flags: JSON_THROW_ON_ERROR, lock: true)
                : ($this->schema?->defaults() ?? []);

            $mutated = $callback($current);

            File::ensureDirectoryExists(dirname($this->path));
            File::replace(
                $this->path,
                json_encode($mutated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            );

            return $this->data = $mutated;
        });
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
