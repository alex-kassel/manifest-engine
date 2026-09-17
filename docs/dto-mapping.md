# Typed DTO Mapping & Hydration Guide

This document describes how `alex-kassel/manifest-engine` bridges raw JSON manifest arrays with strongly-typed Data Transfer Objects (DTOs).

---

## 1. Overview & Motivation

Manipulating manifests using raw PHP arrays (`$data['workspaces'][$name]['packages']...`) leads to common defects:
* Typos in dictionary keys produce silent `null` values.
* No static analysis assistance (PHPStan cannot infer dynamic array shapes).
* Complex business invariants and sorting rules must be repeated wherever arrays are modified.

`ManifestEngine` provides first-class DTO hydration and atomic persistence via the `ManifestDto` contract:
```php
$dto = $manifest->toDto(AppManifestDto::class);
$newDto = $dto->withFeatureFlag('beta', true);
$manifest->saveDto($newDto);
```

---

## 2. The `ManifestDto` Contract

Any DTO intended for use with `toDto()` and `saveDto()` implements `AlexKassel\ManifestEngine\Contracts\ManifestDto`:

```php
namespace AlexKassel\ManifestEngine\Contracts;

/**
 * @template TKey of array-key
 * @template TValue
 */
interface ManifestDto
{
    /**
     * Create a DTO instance from raw manifest data.
     *
     * @param  array<TKey, TValue>  $data
     */
    public static function fromArray(array $data): static;

    /**
     * Convert the DTO to a normalized array for manifest storage.
     *
     * @return array<TKey, TValue>
     */
    public function toArray(): array;
}
```

---

## 3. Designing an Immutable DTO

The recommended pattern is an immutable DTO using PHP 8.2+ `readonly` classes with `with*()` mutation methods:

```php
namespace App\DTOs;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;

final readonly class SystemRegistryDto implements ManifestDto
{
    /**
     * @param array<string, array{url: string, active: bool}> $services
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $environment = 'production',
        public array $services = [],
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): static
    {
        $env = (string) ($data['environment'] ?? 'production');
        $services = (array) ($data['services'] ?? []);

        // Retain unmapped keys for forward compatibility
        $extra = array_diff_key($data, array_flip(['environment', 'services']));

        return new self(
            environment: $env,
            services: $services,
            extra: $extra,
        );
    }

    public function toArray(): array
    {
        return array_merge($this->extra, [
            'environment' => $this->environment,
            'services' => $this->services,
        ]);
    }

    public function withService(string $name, string $url, bool $active = true): self
    {
        $services = $this->services;
        $services[$name] = ['url' => $url, 'active' => $active];
        ksort($services);

        return new self(
            environment: $this->environment,
            services: $services,
            extra: $this->extra,
        );
    }
}
```

---

## 4. Hydration & Persistence API

### Reading via `toDto()`
`toDto(string $dtoClass)` deserializes the manifest contents through the class's `fromArray()` method:

```php
use AlexKassel\ManifestEngine\Facades\Manifest;
use App\DTOs\SystemRegistryDto;

$manifest = Manifest::open(base_path('registry.json'));

/** @var SystemRegistryDto $dto */
$dto = $manifest->toDto(SystemRegistryDto::class);

echo $dto->environment;
```

### In-Memory DTO Caching
If you call `toDto()` multiple times on the same `Manifest` instance, the engine caches the hydrated DTO instance:
```php
$dto1 = $manifest->toDto(SystemRegistryDto::class);
$dto2 = $manifest->toDto(SystemRegistryDto::class);

assert($dto1 === $dto2); // Identical object reference
```
Any raw mutation (`$manifest->set(...)`, `$manifest->mutate(...)`, or `$manifest->reload()`) automatically invalidates the cached DTO.

### Persisting via `saveDto()`
`saveDto(ManifestDto $dto)` serializes the DTO back to disk using atomic replacement:

```php
$updatedDto = $dto->withService('billing', 'https://billing.internal');

$manifest->saveDto($updatedDto);
```

---

## 5. Forward Compatibility with `$extra`

Manifest files are frequently edited by multiple tools, future versions of packages, or human operators adding custom metadata.

When designing your DTOs:
1. Identify known reserved keys: `['version', 'services', 'default']`.
2. Collect everything else into an `$extra` array via `array_diff_key($data, array_flip($reservedKeys))`.
3. In `toArray()`, merge `$extra` back in.

This prevents new or unrecognized properties from being wiped out when an older version of your code writes to the manifest.
