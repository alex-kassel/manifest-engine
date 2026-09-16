<h1 align="center">📜 ManifestEngine</h1>

<p align="center">
  <strong>Schema-driven, atomic file-backed JSON manifest engine for PHP and Laravel.</strong>
</p>

<p align="center">
  <a href="#why-this-exists">Why This Exists</a> •
  <a href="#key-features">Key Features</a> •
  <a href=".dev/use-cases.md">Use Cases</a> •
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a> •
  <a href="#quickstart">Quickstart</a> •
  <a href="#usage--recipes">Usage & Recipes</a> •
  <a href="#architecture">Architecture</a> •
  <a href="#api-reference">API Reference</a> •
  <a href=".dev/roadmap.md">Roadmap</a> •
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

Storing application metadata, registries, configuration state, or domain catalogs in traditional relational databases introduces unnecessary friction for local and developer tooling:
* **Opaque to Git:** State cannot be easily committed, reviewed in Pull Requests, or diffed.
* **Complex for AI Agents:** AI coding agents natively inspect and edit local flat files, but require external database connections to access SQL data.
* **Migration Overhead:** Environment rollbacks and synchronization require stateful migrations.

Conversely, ad-hoc `json_decode()` and `file_put_contents()` approaches introduce serious hazards:
* **Race Conditions & File Corruption:** Concurrent executions or sudden process termination truncate or corrupt JSON files.
* **Schema Drift:** Unvalidated writes allow corrupted keys and invalid types to proliferate.
* **Noisy Diffs:** Inconsistent JSON key formatting and unescaped characters cause chaotic Git diffs.

**ManifestEngine** solves this by providing a clean, schema-validated, file-backed state repository powered natively by Laravel's **Atomic Locks (`Cache::lock()`)**, **safe atomic replacement (`File::replace()`)**, **shared read locks (`File::json()`)**, **dot-notation traversal**, and **automatic JSON Schema generation for IDEs**.

---

## Key Features

* **🛡️ Safe Atomic Transactions:** Mutations run under Laravel atomic locks (`Cache::lock()`) with temporary-file atomic replacement (`File::replace()`), eliminating 0-byte file truncation and race conditions.
* **📐 Schema-Driven Validation:** Enforce structure, default values, and data integrity using standard Laravel validation rules.
* **💡 Automatic JSON Schema:** Automatically compiles Laravel validation rules into Draft-07 JSON Schema for VS Code and PhpStorm autocompletion.
* **📦 Typed DTO Hydration:** Map manifest data to and from typed DTOs via the `ManifestDto` contract or constructor promotion.
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

// 2. Set nested values with chainable in-memory mutations
$manifest->set('meta.environment', 'production')
    ->append('domains', [
        'hostname' => 'api.example.com',
        'ssl' => true,
    ])
    ->save();

// 3. Query nested values
$domains = $manifest->get('domains', []);
$env = $manifest->get('meta.environment');

// 4. Safe atomic transaction
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
$registry->set('status', 'active')->save();
```

Check presence across registered manifests via CLI:

```bash
php artisan manifest:status
```

### 3. Atomic Transactions (`mutate()`)

> [!TIP]
> Use `$manifest->mutate()` whenever multiple modifications or concurrent processes need guaranteed atomicity. It runs under an exclusive Laravel atomic lock:

```php
$manifest->mutate(function (array $data): array {
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

---

## Architecture

ManifestEngine adheres to Single Responsibility (SRP) and the Laravel-First philosophy:

* **Concurrency & Locking:** Powered by `Illuminate\Contracts\Cache\LockProvider` (`Cache::lock()`) with automatic timeouts and owner isolation.
* **Storage & Atomic Writes:** Uses `Illuminate\Filesystem\Filesystem` (`replace()` for atomic rename and `json()` for shared-lock reading).
* **Validation:** Handles schema validation via Laravel's native Validation Factory.
* **DTO Hydration:** Converts manifest payloads into typed objects via the `ManifestDto` contract or PHP 8 constructor promotion.
* **JSON Schema Generation:** Translates Laravel validation rules into Draft-07 JSON Schema.
* **`Manifest`:** Unified document handler focused entirely on querying and mutating state.
* **`ManifestManager` & `ManifestRegistry`:** Central coordinator for opening arbitrary documents or registered aliases.

---

## API Reference

### `Manifest` Document

| Method | Return Type | Description |
|---|---|---|
| `Manifest::open(string $path, ?ManifestSchema $schema = null)` | `Manifest` | Instantiate a manifest document handler. |
| `exists()` | `bool` | Check if the manifest file exists on disk. |
| `init()` | `self` | Initialize the file with schema defaults if missing. |
| `load(bool $forceFresh = false)` | `array` | Read and decode manifest data with shared lock protection. |
| `isDirty()` | `bool` | Determine if in-memory data has unpersisted changes. |
| `save(?array $data = null)` | `self` | Persist in-memory state or provided data atomically via `File::replace()`. |
| `mutate(callable $callback)` | `array` | Run an atomic read-modify-write transaction under atomic lock. |
| `get(string $key, mixed $default = null)` | `mixed` | Read a nested value using dot-notation. |
| `has(string $key)` | `bool` | Check if a nested key exists. |
| `set(string $key, mixed $value)` | `self` | Set a nested key in memory (chainable). |
| `append(string $key, mixed $value)` | `self` | Append a value to an array in memory (chainable). |
| `forget(string $key)` | `self` | Remove a nested key in memory (chainable). |
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

### Artisan Commands

| Command | Description |
|---|---|
| `php artisan manifest:status` | Display registered manifests, file presence, file size, and last modified date. |
| `php artisan manifest:validate {name?}` | Validate registered manifests against schema rules (ideal for CI/CD). |
| `php artisan manifest:schema {name} {--output=}` | Generate and print or export JSON Schema for IDE autocompletion. |
| `php artisan manifest:make {name} {--force}` | Scaffold a manifest file populated with its schema defaults. |

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
