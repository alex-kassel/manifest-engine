# Manifest Registry & Artisan CLI Tools Guide

This document explains the global **Manifest Registry**, inspection service, and available **Artisan CLI commands** in `alex-kassel/manifest-engine`.

---

## 1. The Central Manifest Registry

Instead of hardcoding file paths across multiple services, controllers, or console commands, `ManifestRegistry` allows you to register named manifests centrally:

```php
namespace App\Providers;

use AlexKassel\ManifestEngine\Facades\Manifest;
use App\Manifests\Schemas\WorkspaceSchema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register named manifests with optional schema and lock timeout
        Manifest::register(
            name: 'workspace',
            path: base_path('workspace.json'),
            schema: new WorkspaceSchema,
            lockTimeout: 10
        );

        Manifest::register(
            name: 'plugins',
            path: storage_path('app/plugins.json')
        );
    }
}
```

### Accessing Registered Manifests
Anywhere in your application, access registered manifests by their alias:

```php
use AlexKassel\ManifestEngine\Facades\Manifest;

// Open the registered 'workspace' manifest
$workspace = Manifest::get('workspace');

// Determine if a manifest is registered
if (Manifest::has('plugins')) {
    $plugins = Manifest::get('plugins');
}
```

If you call `Manifest::get('unknown')` with an unregistered name, `ManifestNotFoundException` is thrown.

---

## 2. Manifest Inspection Service

`AlexKassel\ManifestEngine\Services\ManifestInspectionService` provides diagnostic inspection across all registered manifests:

```php
use AlexKassel\ManifestEngine\Services\ManifestInspectionService;

$service = app(ManifestInspectionService::class);

// Inspect all registered manifests
$reports = $service->inspectAll();
foreach ($reports as $report) {
    echo "Manifest: {$report->name} ({$report->path})\n";
    echo "Exists: " . ($report->exists ? 'Yes' : 'No') . "\n";
    echo "Size: {$report->sizeFormatted}\n";
    echo "Permissions: {$report->permissions}\n";
}

// Validate JSON syntax of all registered manifests
$validationReports = $service->validateAll();
foreach ($validationReports as $val) {
    if (! $val->isValid) {
        echo "Error in {$val->name}: {$val->errorMessage}\n";
    }
}
```

---

## 3. Artisan CLI Commands

`ManifestEngine` registers 4 Artisan commands:

### A. Status Report: `manifest:status`
Displays a formatted status table of all registered manifests:

```bash
php artisan manifest:status
```

Output:
```text
+-----------+----------------------+--------+---------+-------------+---------------------+
| Name      | Path                 | Exists | Size    | Permissions | Last Modified       |
+-----------+----------------------+--------+---------+-------------+---------------------+
| workspace | /app/workspace.json  | Yes    | 2.4 KB  | 0644        | 2026-09-17 14:20:00 |
| plugins   | /app/plugins.json    | Yes    | 512 B   | 0644        | 2026-09-17 13:00:00 |
+-----------+----------------------+--------+---------+-------------+---------------------+
```

### B. Validation: `manifest:validate`
Validates that all registered manifests exist and contain valid, uncorrupted JSON syntax:

```bash
php artisan manifest:validate
```

### C. JSON Schema Export: `manifest:schema`
Exports the Draft-07 JSON Schema definition of a registered manifest to stdout or a file:

```bash
# Print to terminal
php artisan manifest:schema workspace

# Export to a file for IDE autocomplete
php artisan manifest:schema workspace --output=resources/workspace.schema.json
```

### D. Initializing a Manifest: `manifest:make`
Initializes a registered manifest using its schema default values:

```bash
php artisan manifest:make workspace
```
If the file already exists, it is left untouched unless the `--force` option is passed.
