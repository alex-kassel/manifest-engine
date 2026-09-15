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

Storing application metadata, registries, or domain states in relational databases (MySQL, SQLite) creates significant friction:
* They are opaque to Git version control.
* They cannot be easily diffed in Pull Requests.
* AI coding agents cannot easily inspect or adjust them without database connectivity.
* Environment migrations and rollbacks become stateful and complex.

On the other hand, writing raw `json_decode()` and `file_put_contents()` to a JSON file is dangerous:
* **Race conditions & Corruption:** Concurrent requests or parallel agent executions can overwrite or corrupt the file.
* **Schema Drift:** Without enforcement, malformed keys and invalid types creep in.
* **Ugly Diffs:** Inconsistent formatting generates noisy, messy Git diffs.

**ManifestEngine** bridges this gap. It provides a lightweight, schema-driven, file-backed repository with **exclusive file locking (`flock`)**, **atomic transactions**, **dot-notation traversal**, and **Git-friendly serialization**.

---

## Key Features

* **Atomic File Transactions:** Safe concurrent reads and writes with exclusive OS-level file locks (`LOCK_EX`).
* **Schema-Driven Validation:** Enforce structure, default values, and integrity rules before any data is written to disk.
* **Dot-Notation Access:** Query and update nested paths effortlessly (`$manifest->get('workspaces.packages')`, `$manifest->append('domains', $entry)`).
* **Git-Optimized Formatting:** Output is always formatted with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` and sorted keys for clean, readable Git diffs.
* **Zero Bloat & Decoupled:** Built directly on `Illuminate\Filesystem\Filesystem`. Works in Laravel commands, standalone PHP scripts, or background workers.

---

## Requirements

* **PHP:** `^8.2` | `^8.3` | `^8.4`
* **Laravel Framework (or Components):** `^11.0` | `^12.0` | `^13.0`
  * `illuminate/filesystem`
  * `illuminate/support`

---

## Installation

Install via Composer:

```bash
composer require alex-kassel/manifest-engine
```

If using Laravel, the service provider and `Manifest` facade are automatically registered via package discovery.

---

## Quickstart

```php
use AlexKassel\ManifestEngine\Facades\Manifest;

$manifest = Manifest::open(base_path('domains.json'));

// 1. Set nested values with dot-notation
$manifest->set('meta.environment', 'production');

// 2. Append to arrays
$manifest->append('domains', [
    'hostname' => 'api.example.com',
    'ssl' => true,
]);

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

### 1. Defining a Schema with Laravel Validator & JSON Schema

Extend `AlexKassel\ManifestEngine\Schemas\BaseSchema` to declare defaults, Laravel validation rules, and IDE `$schema`:

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

    public function jsonSchema(): ?array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'integer'],
                'domains' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['version', 'domains'],
        ];
    }
}
```

Bind the schema when opening the manifest:

```php
$manifest = Manifest::open(base_path('domains.json'), new DomainRegistrySchema);

// If the file doesn't exist, initialize it with schema defaults:
$manifest->init();
```

### 2. Atomic Mutation Transactions

When multiple CLI workers, background jobs, or AI agents might write to the manifest simultaneously, use `mutate()`:

```php
$manifest->mutate(function (array $data): array {
    // Modify data safely inside an exclusive OS file lock
    $data['domains'][] = [
        'hostname' => 'blog.example.com',
        'active' => true,
    ];

    return $data;
});
```

The `mutate()` method:
1. Opens an exclusive file lock (`LOCK_EX`).
2. Reads the current on-disk content.
3. Passes the data to your callback.
4. Validates the returned data against your schema.
5. Writes the file and releases the lock automatically.

### 3. Dependency Injection in Services & Commands

```php
namespace App\Services;

use AlexKassel\ManifestEngine\Manifest;

class DomainManager
{
    protected Manifest $manifest;

    public function __construct()
    {
        $this->manifest = Manifest::open(base_path('domains.json'));
    }

    public function registerDomain(string $hostname): void
    {
        $this->manifest->append('domains', [
            'hostname' => $hostname,
            'registered_at' => date('c'),
        ]);
    }
}
```

---

## API Reference

### `Manifest`

| Method | Return Type | Description |
|---|---|---|
| `Manifest::open(string $path, ?ManifestSchema $schema = null)` | `Manifest` | Instantiate a manifest document handler. |
| `exists()` | `bool` | Check if the manifest file exists on disk. |
| `init()` | `self` | Initialize the file with schema defaults if missing. |
| `load()` | `array` | Read and decode the manifest data into an array. |
| `save(array $data)` | `void` | Validate and write array data to the manifest file. |
| `get(string $key, mixed $default = null)` | `mixed` | Read a nested value using dot-notation. |
| `has(string $key)` | `bool` | Check if a nested key exists. |
| `set(string $key, mixed $value)` | `self` | Set a nested key and persist atomically. |
| `append(string $key, mixed $value)` | `self` | Append a value to a nested array and persist. |
| `mutate(callable $callback)` | `array` | Run a locked, atomic read-modify-write transaction. |

---

## Testing

Run the automated test suite:

```bash
composer test
```

Or via direct PHPUnit binary:

```bash
vendor/bin/phpunit
```

---

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on recent changes.

## Contributing

Contributions are welcome! Please review [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
