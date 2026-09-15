<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Hydration;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use ReflectionClass;
use Throwable;

class DtoHydrator
{
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
        } catch (Throwable $e) {
            throw new ManifestException("Failed to hydrate DTO [{$dtoClass}]: {$e->getMessage()}", 0, $e);
        }
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

            return $data;
        }

        /** @var array<string, mixed> $data */
        $data = get_object_vars($dto);

        return $data;
    }
}
