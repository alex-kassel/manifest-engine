<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Contracts;

interface DtoHydratorInterface
{
    /**
     * Hydrate raw array data into a typed DTO object.
     *
     * @template T of object
     *
     * @param  class-string<T>  $dtoClass
     * @param  array<string, mixed>  $data
     * @return T
     */
    public function hydrate(string $dtoClass, array $data): object;

    /**
     * Serialize a typed DTO object to an associative array.
     *
     * @return array<string, mixed>
     */
    public function serialize(object $dto): array;
}
