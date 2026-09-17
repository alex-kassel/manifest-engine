# Atomic Transactions & Concurrency Guide

This document details how `alex-kassel/manifest-engine` provides concurrency-safe reads, mutations, and file persistence across parallel processes.

---

## 1. Overview & Motivation

Manipulating JSON configuration files using standard `file_get_contents()` and `file_put_contents()` introduces critical vulnerabilities in multi-process environments (such as CI workers, background queues, parallel CLI commands, or AI agents):

1. **Race Conditions:** Two processes reading simultaneously will overwrite each other's changes ("lost update").
2. **0-Byte Truncation:** If a process crashes or is killed while `file_put_contents()` is truncating the file, the manifest is permanently wiped.
3. **Dirty Reads:** A reader may parse half-written JSON while a writer is writing.

`ManifestEngine` eliminates these hazards using a multi-layered concurrency architecture:
* **Atomic Locks (`Cache::lock()`):** Distributed synchronization across processes.
* **Low-level Filesystem Locks (`flock`):** Host-level thread and process safety.
* **Safe Atomic File Replacement (`File::replace()`):** Writes to a temporary file and issues an OS-level atomic rename.
* **Stale Memory Refresh:** In-memory state is automatically reloaded from disk inside transactions before mutations occur.

---

## 2. In-Memory Operations vs. Transactions

`Manifest` provides two modes of mutation:

### A. In-Memory Chaining (Single-Process)
Use when building a state in memory before saving once:

```php
use AlexKassel\ManifestEngine\Facades\Manifest;

$manifest = Manifest::open(base_path('catalog.json'));

$manifest->set('status', 'active')
    ->set('metadata.updated_by', 'artisan')
    ->append('tags', 'core')
    ->save();
```

* Method chaining: fluent mutations modify the in-memory array.
* `save()` writes the entire in-memory state to disk atomically using `File::replace()`.

### B. Atomic Transactions via `mutate()` (Multi-Process Safe)
Use whenever multiple commands or processes can manipulate the manifest concurrently:

```php
use AlexKassel\ManifestEngine\Facades\Manifest;

$manifest = Manifest::open(base_path('catalog.json'));

$manifest->mutate(function (array $data): array {
    $data['counters']['deployments'] = ($data['counters']['deployments'] ?? 0) + 1;
    $data['last_deployed_at'] = now()->toIso8601String();

    return $data;
});
```

#### What Happens Inside `mutate()`:
1. **Lock Acquisition:** An atomic lock is obtained (default: 5 seconds timeout).
2. **Fresh Disk Read:** The file on disk is re-read via `reload()`, ensuring mutations execute against the true latest disk state, even if another process committed changes seconds ago.
3. **Callback Execution:** Your closure receives the fresh data array and returns the updated array.
4. **Atomic Save:** The modified array is written to a temporary file and atomically moved into place.
5. **Event Dispatch:** `ManifestMutated` and `ManifestSaved` events fire with the new state.
6. **Lock Release:** The lock is safely released in a `finally` block.

---

## 3. Configuring Lock Timeouts

When high contention occurs, you can specify custom lock timeout durations:

```php
// Timeout configured at instantiation (in seconds)
$manifest = Manifest::open(
    path: base_path('registry.json'),
    lockTimeout: 10
);

// Or configured per transaction
$manifest->mutate(function (array $data): array {
    $data['jobs_processed'] = ($data['jobs_processed'] ?? 0) + 1;
    return $data;
}, timeout: 15);
```

### Handling Lock Timeouts

If another process holds the lock longer than the configured timeout, `ManifestLockTimeoutException` is thrown:

```php
use AlexKassel\ManifestEngine\Exceptions\ManifestLockTimeoutException;

try {
    $manifest->mutate(function (array $data): array {
        $data['active'] = true;
        return $data;
    });
} catch (ManifestLockTimeoutException $e) {
    // Log or schedule retry
    logger()->warning("Could not obtain lock for [{$e->path}] within {$e->timeout}s.");
}
```

---

## 4. Dot-Notation Accessors

The engine supports deep dot-notation paths powered by Laravel's `Illuminate\Support\Arr`:

```php
// Check existence
$hasChannel = $manifest->has('notifications.slack.webhook');

// Query with default fallback
$timeout = $manifest->get('services.api.timeout', 30);

// Set nested path (auto-creates nested arrays)
$manifest->set('services.api.timeout', 60);

// Remove nested key
$manifest->forget('services.api.deprecated_endpoint');

// Array list operations
$manifest->append('allowed_hosts', 'app.domain.test');
$manifest->prepend('middlewares', App\Http\Middleware\CheckStatus::class);
```

---

## 5. Summary of Guarantees

| Hazard | Plain PHP (`file_put_contents`) | ManifestEngine |
| :--- | :--- | :--- |
| **Simultaneous writes** | Overwritten / Lost updates | Protected by `flock` / `Cache::lock` |
| **Stale memory writes** | Overwrites newer disk changes | Reloads from disk inside `mutate()` |
| **Power loss / SIGKILL** | File left truncated / 0-byte corrupt | Atomic file rename (`File::replace`) |
| **Concurrent reads** | Partial / broken JSON syntax errors | Shared read locking (`File::json`) |
