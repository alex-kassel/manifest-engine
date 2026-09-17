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

---

## Use Case 4: "Beyond .env" — Structured Local Configuration & Sandbox Profiles

### Context
Modern CLI applications, development sandboxes, and developer tools frequently need complex configuration profiles:
* Lists of webhook URLs, allowed domains, or CORS origins.
* Mock credentials, testing flags, and local port offsets.
* Nested API keys per service or third-party mock providers.

### Why Flat `.env` Files Fail Here
`.env` files were designed exclusively for flat scalar strings (`KEY=value`). When developers attempt to store structured data in `.env`, they resort to fragile serializations (e.g. `ALLOWED_ORIGINS="['https://app.test','https://api.test']"`), which require dangerous manual parsing, cannot be validated, and fail silently on syntax mistakes.

### How ManifestEngine Solves It
* **Structured & Expressive:** Stores complex objects, lists, and booleans natively in `sandbox.json`.
* **Dot-Notation Access:** Query deeply nested parameters effortlessly (`$manifest->get('services.stripe.webhook_endpoints')`).
* **Validation & Defaults:** Prevents invalid configurations from crashing the application during boot.
* **Safe Programmatic Updates:** CLI commands can dynamically tweak sandbox settings without breaking file formatting.

```php
// Query and mutate complex structured configuration safely
$config = Manifest::open(base_path('sandbox.json'), new SandboxConfigSchema);

$endpoints = $config->get('services.stripe.webhook_endpoints', []);
$config->append('services.stripe.webhook_endpoints', 'http://localhost:8080/webhooks/stripe')
    ->set('mocking.enabled', true)
    ->save();
```

---

## Use Case 5: Zero-Database Service Registry for Modular Monoliths

### Context
In Modular Monolith and Domain-Driven Design architectures, applications consist of dozens of decoupled modules (e.g. `Billing`, `Identity`, `Catalog`, `Fulfillment`). During framework bootstrap, the application must identify:
* Which modules are currently enabled or in maintenance mode.
* Which service providers and route files must be booted.
* Module dependencies, event subscriptions, and feature toggle flags.

### Why Databases Create Bootstrap Deadlocks
If module activation is stored in a database table, Laravel cannot register module service providers or load module routes until a database connection is established and all migrations are verified. This creates bootstrap bottlenecks, complicates CLI commands, and introduces single-point-of-failure risks.

### How ManifestEngine Solves It
* **Instant Bootstrap:** ManifestEngine reads `modules.json` in microseconds during the earliest service provider registration phase, with zero database dependencies.
* **Atomic Activation:** Enabling or disabling a module via CLI (`php artisan module:enable Billing`) executes an atomic, schema-validated write with exclusive lock protection.
* **Git-Tracked State:** Module configuration branches and merges seamlessly with code releases across staging and production environments.

```php
// ServiceProvider dynamically registers active modules before DB connection
public function register(): void
{
    $manifest = Manifest::get('modules');

    foreach ($manifest->get('active_modules', []) as $module) {
        if ($providerClass = $module['provider'] ?? null) {
            $this->app->register($providerClass);
        }
    }
}
```
