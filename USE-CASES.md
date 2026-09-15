# 💡 Real-World Use Cases for ManifestEngine

`alex-kassel/manifest-engine` solves the challenge of maintaining validated, concurrent, Git-tracked state without requiring external databases. Below are three high-value practical use cases where ManifestEngine delivers distinct architectural advantages.

---

## Use Case 1: Monorepo & Local Package Tooling (Package Hub / Monorepo Orchestration)

### Context
When maintaining a modular monorepo or private package ecosystem, developers often need to track:
* Local packages, directory paths, and symlink mappings.
* Inter-package dependency graphs and release version matrix.
* Package build status, test coverage targets, and publishing flags.

### Why Relational Databases Fail Here
Databases (MySQL, SQLite) cannot be versioned alongside code in Git. Branches, pull requests, and CI/CD pipelines require the registry file to exist in the repository root (e.g. `packages.json`), allowing teammates and automated workflows to branch and diff package state effortlessly.

### How ManifestEngine Solves It
* **Exclusive Locking:** When CLI commands register or update packages (`php artisan package:register`), race conditions between parallel build tools or background workers are prevented.
* **Schema Validation:** Guarantees that no package record enters the registry missing mandatory attributes (`name`, `path`, `namespace`, `version`).
* **Git-Clean Serialization:** Formats JSON deterministically with clean indenting and line endings, ensuring PR diffs only highlight genuine package modifications.

```php
// Register or update package state atomically
Manifest::get('packages')->mutate(function (array $data) use ($newPackage): array {
    $data['packages'][$newPackage->name] = [
        'path' => $newPackage->path,
        'namespace' => $newPackage->namespace,
        'version' => $newPackage->version,
        'registered_at' => date('c'),
    ];

    return $data;
});
```

---

## Use Case 2: Developer Environment, Domain & Service Registry (Herd / Valet / Multi-Tenant Dev)

### Context
Local developer environments and multi-tenant tooling frequently provision dynamic services:
* Custom local domains (e.g. `api.app.test`, `admin.app.test`).
* Reverse-proxy port bindings, SSL certificate paths, and HTTP routing rules.
* Temporary tenant database connections and environment feature flags.

### Why Static PHP Configs Fail Here
Laravel's `config/*.php` files are static and not intended for automated programmatic mutation during runtime. Modifying PHP files via regular expressions or string concatenation is brittle and unsafe.

### How ManifestEngine Solves It
* Provides a robust, structured `domains.json` repository backed by `DomainRegistrySchema`.
* Dedicated daemons, background commands, or CLI tools can safely query or modify routes with dot-notation.
* Automatic JSON Schema allows IDEs to provide autocomplete and warnings when developers inspect or tweak the configuration manually.

```php
// Define custom virtual host mapping safely
$manifest = Manifest::get('domains');

$manifest->set("routes.{$subdomain}", [
    'target' => 'http://127.0.0.1:8080',
    'ssl' => true,
    'certificate' => "/etc/ssl/{$subdomain}.crt",
    'created_at' => now()->toIso8601String(),
]);
```

---

## Use Case 3: Autonomous AI-Agent Workspace Memory & Concurrency Bus

### Context
Modern software development increasingly relies on parallel autonomous AI coding agents (such as Antigravity, Claude Engineer, Cursor, and background subagents). When multiple agents collaborate on a codebase, they need to share state:
* Task backlogs, execution plans, and progress checklists.
* Inventory of generated code artifacts, test results, and validation reports.
* Resource lock flags preventing two agents from modifying the same files simultaneously.

### Why ManifestEngine is the Ideal AI State Bus
* **Native File-System Compatibility:** AI agents lack direct database connectivity in sandboxed environments, but have full, native access to read and edit local JSON files.
* **Optimistic Concurrency Protection (`saveOptimistic`):** If Agent A and Agent B read the same state concurrently, the first agent to write succeeds, while the second agent receives a `ManifestConcurrentModificationException`, preventing silent data overwrite.
* **Auto-Compiled JSON Schema:** Provides AI agents with an explicit contract (Draft-07 `$schema`) describing exactly which fields and formats are valid, drastically reducing hallucinations.

```php
// Agent updates task status using optimistic concurrency verification
$manifest = Manifest::open(base_path('.agent-state.json'), new AgentTaskSchema);

try {
    $manifest->saveOptimistic([
        'current_step' => 'run_migrations',
        'status' => 'in_progress',
        'lock_holder' => 'agent_frontend_01',
    ]);
} catch (ManifestConcurrentModificationException) {
    // Notify agent that state changed externally; reload and recalculate
    $manifest->fresh();
}
```
