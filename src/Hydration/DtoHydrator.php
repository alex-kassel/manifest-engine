<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Hydration;

use AlexKassel\ManifestEngine\Contracts\DtoHydratorInterface;
use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use Throwable;

class DtoHydrator implements DtoHydratorInterface
{
    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_EMPTY_SERIALIZED_DATA = [];

    public const DOCBLOCK_ARRAY_PATTERN = '/@(?:var|param)\s+(?:array<([a-zA-Z0-9_\\\\]+)>|([a-zA-Z0-9_\\\\]+)\[\])/';

    /**
     * Hydrate raw array data into a typed DTO object.
     *
     * @template T of object
     *
     * @param  class-string<T>  $dtoClass
     * @param  array<string, mixed>  $data
     * @return T
     *
     * @throws ManifestException
     */
    public function hydrate(string $dtoClass, array $data): object
    {
        try {
            if (method_exists($dtoClass, 'from')) {
                /** @var T */
                return $dtoClass::from($data);
            }

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
                        $propRef = $reflection->hasProperty($key) ? $reflection->getProperty($key) : null;
                        $dto->{$key} = $this->castValue($value, $propRef?->getType(), $propRef);
                    }
                }

                /** @var T */
                return $dto;
            }

            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                $propRef = $reflection->hasProperty($name) ? $reflection->getProperty($name) : null;

                if (array_key_exists($name, $data)) {
                    $args[$name] = $this->castValue($data[$name], $param->getType(), $param, $propRef);
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[$name] = $param->getDefaultValue();
                }
            }

            /** @var T */
            return $reflection->newInstanceArgs($args);
        } catch (Throwable $e) {
            throw new ManifestException("Failed to hydrate DTO [{$dtoClass}]: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Cast a raw value according to reflection type, BackedEnum, collection attribute, or DateTime.
     */
    protected function castValue(
        mixed $value,
        ?ReflectionType $type,
        ReflectionParameter|ReflectionProperty|null $reflector = null,
        ?ReflectionProperty $companionProperty = null,
    ): mixed {
        if ($value === null) {
            return null;
        }

        // Handle typed nested array collections (via #[ArrayOf] or PHPDoc)
        if (is_array($value)) {
            $itemClass = $this->resolveCollectionItemClass($reflector, $companionProperty);
            if ($itemClass !== null && class_exists($itemClass)) {
                return array_map(function ($item) use ($itemClass) {
                    if (is_array($item)) {
                        return $this->hydrate($itemClass, $item);
                    }

                    return $item;
                }, $value);
            }
        }

        if (! ($type instanceof ReflectionNamedType) || $type->isBuiltin()) {
            return $value;
        }

        $typeName = $type->getName();

        if (is_subclass_of($typeName, BackedEnum::class)) {
            if (is_string($value) || is_int($value)) {
                return $typeName::tryFrom($value) ?? $value;
            }

            return $value;
        }

        if (is_a($typeName, DateTimeInterface::class, true) && is_string($value)) {
            return new DateTimeImmutable($value);
        }

        if (class_exists($typeName) && is_array($value)) {
            return $this->hydrate($typeName, $value);
        }

        return $value;
    }

    /**
     * Resolve target item class for an array collection using attributes or docblock comments.
     *
     * @return class-string|null
     */
    protected function resolveCollectionItemClass(
        ReflectionParameter|ReflectionProperty|null $reflector,
        ?ReflectionProperty $companion = null,
    ): ?string {
        foreach (array_filter([$reflector, $companion]) as $ref) {
            $attributes = $ref->getAttributes(ArrayOf::class);
            if (! empty($attributes)) {
                /** @var ArrayOf $instance */
                $instance = $attributes[0]->newInstance();

                return $instance->class;
            }

            if (method_exists($ref, 'getDocComment')) {
                $docComment = $ref->getDocComment();
                if (is_string($docComment) && preg_match(self::DOCBLOCK_ARRAY_PATTERN, $docComment, $matches)) {
                    $target = $matches[1] ?: $matches[2];
                    if (class_exists($target)) {
                        return $target;
                    }

                    // Try resolving in same declaring namespace
                    $declaringClass = $ref->getDeclaringClass();
                    $namespaced = $declaringClass->getNamespaceName().'\\'.$target;
                    if (class_exists($namespaced)) {
                        return $namespaced;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Serialize a typed DTO object to an associative array.
     *
     * @return array<string, mixed>
     */
    public function serialize(object $dto): array
    {
        if ($dto instanceof ManifestDto || method_exists($dto, 'toArray')) {
            /** @var array<string, mixed> $data */
            $data = $dto->toArray();

            return $this->normalizeSerializedArray($data);
        }

        if ($dto instanceof JsonSerializable) {
            $serialized = $dto->jsonSerialize();
            if (is_array($serialized)) {
                return $this->normalizeSerializedArray($serialized);
            }
        }

        /** @var array<string, mixed> $data */
        $data = get_object_vars($dto);

        return $this->normalizeSerializedArray($data);
    }

    /**
     * Recursively normalize serialized array values (convert BackedEnums, dates, nested objects).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeSerializedArray(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if ($value instanceof BackedEnum) {
                $normalized[$key] = $value->value;
            } elseif ($value instanceof DateTimeInterface) {
                $normalized[$key] = $value->format(DateTimeInterface::ATOM);
            } elseif (is_object($value)) {
                $normalized[$key] = $this->serialize($value);
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeSerializedArray($value);
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
