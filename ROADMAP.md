# 🗺️ ManifestEngine Roadmap

This roadmap outlines planned high-impact capabilities and architectural enhancements designed to elevate `alex-kassel/manifest-engine` into an industry-grade, developer-friendly flat-file state engine.

---

## 1. Schema Migrations & Auto-Evolution (`ManifestMigrator`)

### Problem
As packages and CLI applications evolve, schema structures inevitably change (e.g., renaming fields, restructuring nested objects, moving legacy scalar values into typed objects). Currently, breaking changes require fragile runtime checks like `if (isset($data['legacy_field']))`.

### Planned Solution
Introduce a native, versioned migration pipeline inside `ManifestSchema`:

```php
class AppRegistrySchema extends BaseSchema
{
    public int $version = 2;

    public function migrations(): array
    {
        return [
            1 => function (array $data): array {
                // Migrate from v1 to v2: group legacy credentials under database
                $data['database'] = [
                    'username' => $data['db_user'] ?? 'root',
                ];
                unset($data['db_user']);
                $data['version'] = 2;

                return $data;
            },
        ];
    }
}
```

* **Automatic Migration on Open:** When `Manifest::open()` loads a document whose file version is lower than the schema version, the engine automatically creates a temporary backup and runs pending migrations atomically.
* **Artisan Command:** `php artisan manifest:migrate {name?}` to inspect and migrate manifests safely with a `--dry-run` option.

---

## 2. Deterministic Key Canonicalization & Semantic Diffing

### Problem
JSON files stored in Git repositories suffer from noise and merge conflicts when keys are inserted in arbitrary order. Standard `git diff` produces noisy line diffs, obscuring genuine structural modifications.

### Planned Solution
* **Canonical Key Ordering (`ksort`):** Recursively sort all associative dictionary keys before serialization while preserving ordered list arrays. Every write produces an identical byte-for-byte layout, guaranteeing minimal, clean Git diffs.
* **Semantic Diffing Engine:**
  ```php
  $diff = $manifest->diff($newData);
  // Returns:
  // [
  //     'added' => ['features.dark_mode'],
  //     'modified' => ['version' => ['from' => 1, 'to' => 2]],
  //     'removed' => ['legacy_token'],
  // ]
  ```
* **CI/CD Linter Command:** `php artisan manifest:lint` to verify that all tracked manifests in the repository conform to their declared schemas and canonical formatting in pre-commit hooks and GitHub Actions.

---

## 3. Reactive Collections & Fluid Querying

### Problem
Querying and manipulating nested arrays of entities (modules, domains, routes, packages) currently requires raw PHP array filtering and mapping, followed by manual re-saving.

### Planned Solution
Integrate with Laravel's `Illuminate\Support\Collection` for fluid querying and optional reactive persistence:

```php
// Query nested entities with Laravel Collection methods
$activePackages = $manifest->collection('packages')
    ->where('enabled', true)
    ->sortBy('order');

// Reactive mutations automatically validate and persist
$manifest->collection('packages')->push([
    'name' => 'billing',
    'version' => '1.0',
    'enabled' => true,
])->save();
```

* **Query Builder Layer:** `$manifest->query('services.*')->where('status', 'healthy')->get()`.
* **Zero Overhead:** Builds on top of existing `AtomicFileStorage` and Laravel's native collection utilities.

---

## 4. Custom Git Merge Driver (`php artisan manifest:merge`)

### Problem
When multiple developers or autonomous AI agents work concurrently on separate Git branches, standard line-based `git merge` frequently breaks JSON syntax upon branch reconciliation. Adding new entries to arrays or adding adjacent object keys often results in syntax-breaking merge conflicts (`<<<<<<< HEAD` markers, missing or trailing commas).

### Planned Solution
Implement a dedicated Git three-way merge driver command:

```bash
php artisan manifest:merge --base=%O --ours=%A --theirs=%B
```

* **Zero-Conflict Git Reconciliation:** Developers configure `.gitattributes` once:
  ```gitattributes
  *.manifest.json merge=manifest
  workspace.json merge=manifest
  ```
* **Semantic Reconciliation:** The driver resolves structural conflicts automatically:
  - Deep-merges non-conflicting dictionary keys from both branches.
  - Combines unique array entries while preserving ordering.
  - Re-formats and writes back canonical, syntactically valid JSON.

---

## 5. Reactive File Watcher & Live Event Streaming (Reverb & Octane)

### Problem
In long-running application runtimes (Laravel Octane, RoadRunner, FrankenPHP, background worker daemons, or interactive developer dashboards), detecting when an external process (Git pull, IDE, or AI agent) edits a manifest on disk currently requires manual polling.

### Planned Solution
Introduce a native, low-overhead file watcher CLI daemon and broadcasting pipeline:

```bash
php artisan manifest:watch {name}
```

* **Live Event Streaming:** Dispatches `ManifestExternallyChanged` events providing granular, field-level diffs (`added`, `updated`, `removed`).
* **Real-Time WebSocket Broadcasting:** Seamlessly broadcasts updates to browser dashboards or frontends via **Laravel Reverb** or Server-Sent Events (SSE), turning static JSON documents into reactive real-time state buses.
