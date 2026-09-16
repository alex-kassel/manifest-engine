<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine;

use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Hydration\DtoHydrator;
use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Arr;
use JsonSerializable;

/**
 * @implements ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 */
class ManifestDocument implements Arrayable, ArrayAccess, Jsonable, JsonSerializable
{
    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_EMPTY_DATA = [];

    /**
     * @var array<int, mixed>
     */
    public const DEFAULT_APPEND_EMPTY_ARRAY = [];

    public const JSON_ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    /**
     * In-memory document data.
     *
     * @var array<string, mixed>
     */
    protected array $data = self::DEFAULT_EMPTY_DATA;

    /**
     * Snapshot of data upon instantiation or last sync.
     *
     * @var array<string, mixed>
     */
    protected array $original = self::DEFAULT_EMPTY_DATA;

    /**
     * Indicates whether in-memory data has changed.
     */
    protected bool $isDirty = false;

    protected DtoHydrator $hydrator;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        array $data = self::DEFAULT_EMPTY_DATA,
        ?DtoHydrator $hydrator = null,
    ) {
        $this->data = $data;
        $this->original = $data;
        $this->hydrator = $hydrator ?? new DtoHydrator;
    }

    /**
     * Create an in-memory ManifestDocument from an array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data, ?DtoHydrator $hydrator = null): self
    {
        return new self($data, $hydrator);
    }

    /**
     * Create an in-memory ManifestDocument from a typed DTO object.
     */
    public static function fromDto(object $dto, ?DtoHydrator $hydrator = null): self
    {
        $hydratorInstance = $hydrator ?? new DtoHydrator;
        $data = $hydratorInstance->serialize($dto);

        return new self($data, $hydratorInstance);
    }

    /**
     * Get a value from the document using dot-notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Determine if a key exists in the document using dot-notation.
     */
    public function has(string $key): bool
    {
        return Arr::has($this->data, $key);
    }

    /**
     * Set a value in the document using dot-notation (in-memory only).
     */
    public function set(string $key, mixed $value): self
    {
        data_set($this->data, $key, $value);
        $this->isDirty = true;

        return $this;
    }

    /**
     * Append a value to an array at given key (in-memory only).
     */
    public function push(string $key, mixed $value): self
    {
        $current = data_get($this->data, $key, self::DEFAULT_APPEND_EMPTY_ARRAY);
        if (! is_array($current)) {
            $current = [$current];
        }

        $current[] = $value;
        data_set($this->data, $key, $current);
        $this->isDirty = true;

        return $this;
    }

    /**
     * Alias for push().
     */
    public function append(string $key, mixed $value): self
    {
        return $this->push($key, $value);
    }

    /**
     * Forget/remove a nested key from the document (in-memory only).
     */
    public function forget(string $key): self
    {
        Arr::forget($this->data, $key);
        $this->isDirty = true;

        return $this;
    }

    /**
     * Merge given array data into the document (in-memory only).
     *
     * @param  array<string, mixed>  $data
     */
    public function merge(array $data): self
    {
        $this->data = array_replace_recursive($this->data, $data);
        $this->isDirty = true;

        return $this;
    }

    /**
     * Determine if in-memory data has changed since creation or last sync.
     */
    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    /**
     * Reset dirty state and mark current data as original baseline.
     */
    public function resetDirty(): self
    {
        $this->original = $this->data;
        $this->isDirty = false;

        return $this;
    }

    /**
     * Get the original data baseline before in-memory mutations.
     *
     * @return array<string, mixed>
     */
    public function getOriginal(): array
    {
        return $this->original;
    }

    /**
     * Hydrate in-memory document data into a typed DTO object.
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
        return $this->hydrator->hydrate($dtoClass, $this->data);
    }

    /**
     * Fill in-memory document data from a typed DTO object.
     */
    public function fillFromDto(object $dto): self
    {
        $this->data = $this->hydrator->serialize($dto);
        $this->isDirty = true;

        return $this;
    }

    /**
     * Get all document data as an associative array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return $this->all();
    }

    /**
     * {@inheritdoc}
     */
    public function toJson($options = 0): string
    {
        return (string) json_encode($this->data, $options ?: self::JSON_ENCODE_FLAGS);
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): mixed
    {
        return $this->data;
    }

    /**
     * ArrayAccess: offsetExists
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    /**
     * ArrayAccess: offsetGet
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    /**
     * ArrayAccess: offsetSet
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            return;
        }

        $this->set((string) $offset, $value);
    }

    /**
     * ArrayAccess: offsetUnset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->forget((string) $offset);
    }
}
