# Schemas & JSON Schema Specification Guide

This document explains how `alex-kassel/manifest-engine` defines, validates, and exports schema specifications for manifest files.

---

## 1. Overview & Motivation

Manifests serve as machine-readable contracts between tools, scripts, and developers. Without a strict schema contract:
* Initializing an empty manifest requires hardcoded fallback dictionaries scattered across various callers.
* Developers editing manifest files in IDEs (PhpStorm, VS Code, Cursor) receive no autocomplete, validation warnings, or inline documentation.

`ManifestEngine` solves this through the `ManifestSchema` contract, which bridges runtime default state initialization with Draft-07 JSON Schema export.

---

## 2. The `ManifestSchema` Contract

Every schema must implement `AlexKassel\ManifestEngine\Contracts\ManifestSchema`:

```php
namespace AlexKassel\ManifestEngine\Contracts;

interface ManifestSchema
{
    /**
     * Default state when a new manifest file is initialized.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * Full JSON Schema (Draft-07) representation for IDE autocomplete and validation.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array;
}
```

### The Two Responsibilities:
1. `defaults()`: Provides the initial baseline structure when `$manifest->init()` is invoked on an uninitialized or missing file.
2. `jsonSchema()`: Returns a standard JSON Schema Draft-07 dictionary.

---

## 3. Implementing a Custom Schema

Using Laravel 13's fluent `Illuminate\JsonSchema\JsonSchema` builder, you can define expressive, type-safe schemas cleanly:

```php
namespace App\Manifests\Schemas;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use Illuminate\JsonSchema\JsonSchema;

class PluginRegistrySchema implements ManifestSchema
{
    public const SCHEMA_URL = 'https://example.com/schemas/plugin-registry.json';

    public function defaults(): array
    {
        return [
            '$schema' => self::SCHEMA_URL,
            'version' => '1.0.0',
            'plugins' => [],
        ];
    }

    public function jsonSchema(): array
    {
        $pluginItem = JsonSchema::object([
            'id' => JsonSchema::string()->description('Unique plugin identifier.')->required(),
            'enabled' => JsonSchema::boolean()->description('Whether the plugin is active.'),
            'providers' => JsonSchema::array()->items(JsonSchema::string())->description('Service provider classes.'),
        ]);

        $root = JsonSchema::object([
            '$schema' => JsonSchema::string()->description('Schema location URL.'),
            'version' => JsonSchema::string()->description('Registry specification version.')->required(),
            'plugins' => JsonSchema::array()->items($pluginItem)->description('Installed plugins list.')->required(),
        ])->title('PluginRegistryManifest')
          ->description('Configuration registry for application plugins.');

        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            ...$root->toArray(),
        ];
    }
}
```

---

## 4. Binding Schemas to Manifests

Bind your schema when instantiating or opening the manifest:

```php
use AlexKassel\ManifestEngine\Manifest;
use App\Manifests\Schemas\PluginRegistrySchema;

// Open with custom schema
$manifest = Manifest::open(
    path: base_path('plugins.json'),
    schema: new PluginRegistrySchema
);

// If file does not exist, initialize it with defaults:
if (! $manifest->exists()) {
    $manifest->init();
}
```

When `init()` runs:
* The directory structure is ensured.
* The content of `$schema->defaults()` is written atomically to disk.
* Any existing file is not overwritten.

---

## 5. Exporting JSON Schema for IDE Autocomplete

To provide real-time IDE validation in PhpStorm, VS Code, or Cursor:

### Via PHP API
```php
$schemaData = $manifest->exportJsonSchema();

// Save to public resources or static path
file_put_contents(public_path('schemas/plugin-registry.json'), json_encode($schemaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
```

### Via Artisan Command
```bash
php artisan manifest:schema plugins --output=resources/schema.json
```

### In Manifest JSON Files
When the manifest contains the `"$schema"` property pointing to a local file or remote URL:

```json
{
  "$schema": "./resources/schema.json",
  "version": "1.0.0",
  "plugins": [
    {
      "id": "billing",
      "enabled": true,
      "providers": ["App\\Plugins\\BillingServiceProvider"]
    }
  ]
}
```
The IDE will automatically highlight syntax errors, suggest available keys, and render hover documentation.
