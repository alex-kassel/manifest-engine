<h1 align="center">📜 ManifestEngine</h1>

<p align="center">
  <strong>Schema-driven, atomic file-backed JSON manifest engine for PHP and Laravel.</strong>
</p>

<p align="center">
  <a href="#why-this-exists">Why This Exists</a> •
  <a href="#key-features">Key Features</a> •
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a> •
  <a href="#quickstart">Quickstart</a> •
  <a href="#usage--recipes">Usage & Recipes</a> •
  <a href="#architecture">Architecture</a> •
  <a href="#api-reference">API Reference</a> •
  <a href="#testing">Testing</a> •
  <a href="LICENSE.md">License</a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/alex-kassel/manifest-engine"><img src="https://img.shields.io/packagist/v/alex-kassel/manifest-engine?color=f59e0b&logo=packagist&logoColor=white" alt="Latest Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?logo=laravel&logoColor=white" alt="Laravel Support"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2+-777bb4?logo=php&logoColor=white" alt="PHP Support"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square" alt="License"></a>
</p>

---

## Why This Exists

Storing application metadata, registries, configuration state, or domain catalogs in traditional relational databases (MySQL, Postgres) introduces unnecessary friction for local and developer tooling:
* **Opaque to Git:** State cannot be easily committed, reviewed in Pull Requests, or diffed.
* **Complex for AI Agents:** AI coding agents natively inspect and edit local flat files, but require external database connections to access SQL data.
* **Migration Overhead:** Environment rollbacks and synchronization require stateful migrations.

Conversely, ad-hoc `json_decode()` and `file_put_contents()` approaches introduce serious hazards:
* **Race Conditions & File Corruption:** Concurrent executions or sudden process termination truncate or corrupt JSON files.
* **Schema Drift:** Unvalidated writes allow corrupted keys and invalid types to proliferate.
* **Noisy Diffs:** Inconsistent JSON key formatting and unescaped characters cause chaotic Git diffs.

**ManifestEngine** solves this by providing an atomic, schema-validated, file-backed state repository with **OS-level locking (`flock`)**, **safe atomic rename transactions**, **optimistic concurrency control**, **dot-notation traversal**, and **automatic JSON Schema generation for IDEs**.

---

## Key Features

* **🛡️ Safe Atomic Transactions:** Reads use shared locks (`LOCK_SH`); writes and mutations run under exclusive locks (`LOCK_EX`) with temporary-file atomic replacement (`rename`), eliminating 0-byte file truncation risks.
* **📐 Schema-Driven Validation:** Enforce structure, default values, and data integrity using standard Laravel validation rules.
* **💡 Automatic JSON Schema:** Automatically compiles Laravel validation rules into Draft-07 JSON Schema for VS Code and PhpStorm autocompletion.
* **⚡ Optimistic Concurrency Control:** Detect external changes on disk using content hashes (`saveOptimistic()`).
* **📦 Typed DTO Hydration:** Seamlessly map manifest data to and from typed DTOs via the `ManifestDto` contract or constructor reflection.
* **⏪ Snapshots & Rollbacks:** Take in-memory snapshots and roll back state on failed operations.
* **🔍 Dot-Notation Access:** Query, mutate, and delete nested paths effortlessly (`$manifest->get('app.channels')`, `$manifest->append('domains', $entry)`).
* **🗂️ Centralized Manifest Registry:** Register application manifests once and open them anywhere via alias: `Manifest::get('registry')`.
* ** Git-Optimized Serialization:** Always formatted with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` and trailing newline.

---

## Requirements

* **PHP:** `^8.2` | `^8.3` | `^8.4`
* **Laravel (or Standalone Components):** `^11.0` | `^12.0` | `^13.0`
  * `illuminate/filesystem`
  * `illuminate/support`
  * `illuminate/validation`
  * `illuminate/translation`

---

## Installation

Install via Composer:

```bash
composer require alex-kassel/manifest-engine
```

In Laravel applications, the Service Provider and `Manifest` facade are automatically discovered.

---

## Quickstart

```php
use AlexKassel\ManifestEngine\Facades\Manifest;

// 1. Open any manifest file directly
$manifest = Manifest::open(base_path('registry.json'));

// 2. Set nested values with dot-notation
$manifest->set('meta.environment', 'production');

// 3. Append to arrays
$manifest->append('domains', [
    'hostname' => 'api.example.com',
    'ssl' => true,
]);

// 4. Query nested values
$domains = $manifest->get('domains', []);
$env = $manifest->get('meta.environment');

// 5. Safe atomic transaction
$manifest->mutate(function (array $data): array {
    $data['counter'] = ($data['counter'] ?? 0) + 1;
    return $data;
});
```

---

## Usage & Recipes

### 1. Defining a Schema with Auto-Compiled JSON Schema

Extend `AlexKassel\ManifestEngine\Schemas\BaseSchema` to define default states and Laravel validation rules:

```php
namespace App\Manifests;

use AlexKassel\ManifestEngine\Schemas\BaseSchema;

class DomainRegistrySchema extends BaseSchema
{
    public function defaults(): array
    {
        return [
            'version' => 1,
            'domains' => [],
        ];
    }

    public function rules(): array
    {
        return [
            'version'   => ['required', 'integer'],
            'domains'   => ['present', 'array'],
            'domains.*' => ['string'],
            'meta'      => ['sometimes', 'array'],
        ];
    }
}
```

Bind the schema when opening the manifest:

```php
$manifest = Manifest::open(base_path('domains.json'), new DomainRegistrySchema);

// If the file does not exist, initialize it with schema defaults:
$manifest->init();
```

Export Draft-07 JSON Schema for IDEs:

```php
$manifest->exportJsonSchema(base_path('.schemas/domains.schema.json'));
```

### 2. Centralized Registry & Alias Resolution

Register your manifests during application bootstrapping (e.g. in a service provider):

```php
use AlexKassel\ManifestEngine\Facades\Manifest;
use App\Manifests\AppRegistrySchema;

// Register manifest definition
Manifest::register(
    name: 'app_registry',
    filename: 'registry.json',
    schema: AppRegistrySchema::class,
    description: 'Core application registry'
);
```

Then open it from anywhere in your codebase using the registered alias:

```php
$registry = Manifest::get('app_registry');
$registry->set('status', 'active');
```

Check presence across registered manifests via CLI:

```bash
php artisan manifest:status
```

### 3. Batching & Concurrency Best Practices

> [!TIP]
> Each standalone `set()`, `append()`, or `forget()` call acquires an exclusive lock and performs an atomic write. When making multiple changes, use `batch()` or `mutate()` to run all modifications in a single locked transaction:

```php
$manifest->batch(function (array $data): array {
    $data['settings']['theme'] = 'dark';
    $data['settings']['notifications'] = true;
    $data['updated_at'] = date('c');

    return $data;
});
```

### 4. Typed DTO Mapping

Hydrate manifest data into typed objects using constructor promotion or the `ManifestDto` contract:

```php
use AlexKassel\ManifestEngine\Contracts\ManifestDto;

class AppConfigDto implements ManifestDto
{
    public function __construct(
        public string $name,
        public int $version = 1,
    ) {}

    public function toArray(): array
    {
        return ['name' => $this->name, 'version' => $this->version];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            name: (string) ($data['name'] ?? ''),
            version: (int) ($data['version'] ?? 1),
        );
    }
}

// Hydrate from manifest
$dto = $manifest->toDto(AppConfigDto::class);

// Mutate and save back
$dto->version++;
$manifest->saveDto($dto);
```

### 5. Optimistic Concurrency Control

Prevent accidental overwrites when multiple external processes or Git branches modify files:

```php
// Read manifest
$manifest = Manifest::open(base_path('state.json'));

// If an external process changes state.json before this save completes,
// a ManifestConcurrentModificationException is thrown:
$manifest->saveOptimistic($updatedData);
```

---

## Architecture

ManifestEngine adheres to Single Responsibility (SRP) and clean dependency injection:

* **`StorageDriver` (`AtomicFileStorage`):** Manages file I/O, shared read locks, exclusive mutation locks, and atomic replacement via temporary files.
* **`ManifestValidator`:** Handles schema validation via Laravel's Validation Factory.
* **`DtoHydrator`:** Converts manifest payloads into typed objects and serializes them back.
* **`JsonSchemaCompiler`:** Translates Laravel validation rules into Draft-07 JSON Schema.
* **`Manifest`:** Expressive document repository focused entirely on querying and mutating state.
* **`ManifestManager` & `ManifestRegistry`:** Central coordinator for opening arbitrary documents or registered aliases.

---

## API Reference

### `Manifest` Document

| Method | Return Type | Description |
|---|---|---|
| `Manifest::open(string $path, ?ManifestSchema $schema = null)` | `Manifest` | Instantiate a manifest document handler. |
| `exists()` | `bool` | Check if the manifest file exists on disk. |
| `init()` | `self` | Initialize the file with schema defaults if missing. |
| `load(bool $forceFresh = false)` | `array` | Read and decode manifest data with shared lock. |
| `save(array $data)` | `void` | Validate and write array data to the file atomically. |
| `saveOptimistic(array $data, ?string $hash = null)` | `void` | Write data with concurrency conflict verification. |
| `get(string $key, mixed $default = null)` | `mixed` | Read a nested value using dot-notation. |
| `has(string $key)` | `bool` | Check if a nested key exists. |
| `set(string $key, mixed $value)` | `self` | Set a nested key and persist atomically. |
| `append(string $key, mixed $value)` | `self` | Append a value to a nested array and persist. |
| `forget(string $key)` | `self` | Remove a nested key and persist. |
| `batch(callable $callback)` | `self` | Run multiple modifications in a single locked transaction. |
| `mutate(callable $callback)` | `array` | Run an atomic read-modify-write transaction. |
| `snapshot()` | `self` | Capture an in-memory state snapshot. |
| `rollback()` | `self` | Restore state from the captured snapshot. |
| `fresh()` / `reload()` | `self` | Invalidate in-memory cache and re-read from disk. |
| `toDto(string $dtoClass)` | `object` | Hydrate manifest into a typed DTO object. |
| `saveDto(object $dto)` | `void` | Save a typed DTO back to the manifest. |
| `exportJsonSchema(?string $outputPath = null)` | `?array` | Generate and optionally save JSON Schema. |

### `ManifestManager` & Facade

| Method | Return Type | Description |
|---|---|---|
| `Manifest::open(string $path, ?ManifestSchema $schema = null)` | `Manifest` | Open an arbitrary manifest file. |
| `Manifest::get(string $name, ?string $basePath = null)` | `Manifest` | Retrieve and open a registered manifest by alias. |
| `Manifest::has(string $name)` | `bool` | Check if a manifest alias is registered. |
| `Manifest::register(...)` | `ManifestRegistry` | Register a manifest definition. |

---

## Testing

Run the test suite via Composer or PHPUnit:

```bash
composer test
# or
vendor/bin/phpunit packages/alex-kassel/manifest-engine/tests
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
