# Lifecycle Events & Auditing Guide

This document details the lifecycle events dispatched by `alex-kassel/manifest-engine` and how to integrate them for reactive caching, metrics, and audit logging.

---

## 1. Overview & Motivation

Manifest files drive core infrastructure in modular and workspace applications. When a manifest is created, modified, or saved, other parts of the system often need to react:
* Invalidate cached service routes or container bindings.
* Trigger background syncs or webhooks.
* Record audit logs with timestamps and diffs.

`ManifestEngine` integrates natively with Laravel's `Illuminate\Contracts\Events\Dispatcher` to broadcast events at every stage of the manifest lifecycle.

---

## 2. Event Catalog

All events reside in the `AlexKassel\ManifestEngine\Events` namespace:

| Event Class | When Dispatched | Payload Properties |
| :--- | :--- | :--- |
| **`ManifestOpened`** | When `Manifest::open()` or `ManifestManager::get()` loads a manifest. | `string $path`, `Manifest $manifest` |
| **`ManifestSaving`** | Immediately prior to writing data to disk (can be inspected or logged). | `string $path`, `array $data`, `Manifest $manifest` |
| **`ManifestSaved`** | Immediately after data is successfully written to disk. | `string $path`, `array $data`, `Manifest $manifest` |
| **`ManifestMutated`** | After an atomic transaction (`$manifest->mutate()`) finishes saving. | `string $path`, `array $data`, `Manifest $manifest` |

---

## 3. Registering Event Listeners

You can register listeners in your application's `EventServiceProvider` or package service provider:

### Example: Clearing Caches on Manifest Mutation
```php
namespace App\Listeners;

use AlexKassel\ManifestEngine\Events\ManifestMutated;
use Illuminate\Support\Facades\Cache;

class InvalidateRegistryCache
{
    public function handle(ManifestMutated $event): void
    {
        if (str_ends_with($event->path, 'registry.json')) {
            Cache::forget('system_registry_index');
        }
    }
}
```

### Example: Audit Logging
```php
use AlexKassel\ManifestEngine\Events\ManifestSaved;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(ManifestSaved::class, function (ManifestSaved $event): void {
    Log::info("Manifest saved [{$event->path}]", [
        'keys_count' => count($event->data),
        'timestamp' => now()->toIso8601String(),
    ]);
});
```

---

## 4. Disabling Events in Standalone Environments

If running `ManifestEngine` in a standalone CLI script where Laravel's Event Dispatcher is not bound, `ManifestEngine` gracefully checks if `event()` helper or container dispatcher is available. If no dispatcher exists, operations proceed normally without errors.
