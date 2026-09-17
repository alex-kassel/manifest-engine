<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Contracts;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Arrayable<TKey, TValue>
 */
interface ManifestDto extends Arrayable
{
    /**
     * Create a DTO instance from raw manifest data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static;
}

