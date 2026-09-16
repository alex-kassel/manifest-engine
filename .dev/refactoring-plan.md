# 🛠️ ManifestEngine Technical Refactoring Plan (Laravel-First)

This refactoring plan prioritizes replacing custom low-level PHP plumbing with battle-tested **Laravel native tools** (`Illuminate\Filesystem\Filesystem`, Laravel Atomic Locks via `Cache::lock()`, and container resolution), followed by clean separation of responsibilities between in-memory state objects and transactional file persistence coordinators.

---

## Priority Matrix

| Priority | Scope / Area | Impact | Complexity |
| :--- | :--- | :--- | :--- |
| **P0 (Blocker)** | Laravel-First Storage (`File::replace`, `File::sharedGet`) | Eliminates custom `fopen`/`rename` bugs using native framework tools | Completed |
| **P0 (Blocker)** | Laravel Atomic Locks (`Cache::lock()->block()`) | Replaces custom deadlock-prone `flock` files with managed TTL locks | Completed |
| **P0 (Blocker)** | Fail-Safe Command Signatures (`{name?}`) | Prevents fatal non-interactive / CI console crashes | Completed |
| **P1 (Critical)** | Service Container Schema Resolution | Enables dependency injection in schema constructors | Completed |
| **P1 (Architectural Core)**| Document & Transactional Store Separation | Eliminates God Object; optimizes loop I/O via `ManifestDocument` & `transaction()` | In Progress |
| **P2 (Quality)** | Thin Coordinator Console Commands | Separates orchestration from business/inspection logic | Pending |
| **P2 (Compliance)** | Strict Fallback & Constant Encapsulation | Adheres to strict zero-raw-literal rules across classes | Pending |
| **P3 (Evolution)** | DTO Hydrator Extensibility & Nested Collections | Handles typed nested collections (`array<ItemDto>`) | Pending |

---

## Phase 1: Native Laravel-First Core & CLI Safety (P0) — [COMPLETED]

* [x] **1.1: Replace Custom Atomic I/O with `Illuminate\Filesystem\Filesystem`**: Replaced custom 40-line `writeAtomicDirect` with `$this->files->replace()` and `$this->files->sharedGet()`.
* [x] **1.2: Adopt Laravel Atomic Locks (`Cache::lock()->block()`)**: Integrated `LockProvider` from Laravel Cache, non-blocking spin-locks with timeouts, `ManifestLockTimeoutException`, and path canonicalization.
* [x] **1.3: Implement Fail-Safe Command Signatures (`{name?}`)**: Updated `manifest:schema` and `manifest:make` to optional arguments with interactive choice prompts and non-interactive guidance.

---

## Phase 2: Architectural Separation & In-Memory Efficiency (P1)

### Architectural Design: In-Memory `ManifestDocument` vs Transactional `Manifest` Store

```
┌─────────────────────────────────────────────────────────┐
│                    Manifest (Store)                     │  <-- Handles DISK, Lock Provider,
│  - transaction(callable $callback): ManifestDocument   │      and atomic persistence
│  - read(): ManifestDocument                             │
│  - write(ManifestDocument|array $doc): self             │
└───────────────────────────┬─────────────────────────────┘
                            │ produces / persists
┌───────────────────────────▼─────────────────────────────┐
│                 ManifestDocument                        │  <-- Pure IN-MEMORY object!
│  - get(string $key, mixed $default)                     │      Zero I/O operations.
│  - set(string $key, mixed $value): self                 │      Ideal for intensive loops.
│  - push(string $key, mixed $value): self                │      Tracks isDirty state.
│  - forget(string $key): self                            │      DTO serialization.
│  - toDto(class-string<T> $class): object                │
│  - toArray(): array                                     │
└─────────────────────────────────────────────────────────┘
```

### Step 2.1: Container-Aware Schema Resolution — [COMPLETED]
* Resolved schemas via `Container::getInstance()->make($this->schema)` with fallback to `new $this->schema`.

### Step 2.2: Implement `ManifestDocument` (Pure In-Memory State Object)
* **Target:** `AlexKassel\ManifestEngine\ManifestDocument`
* **Purpose:** A dedicated, lightweight in-memory data container adhering to Single Responsibility Principle.
* **Responsibilities:**
  * Dot-notation access (`get`, `set`, `push`, `forget`, `has`).
  * Array & DTO serialization (`toArray`, `toDto`, `fromDto`).
  * In-memory dirty state tracking (`isDirty()`, `getOriginal()`, `resetDirty()`).
  * **Zero I/O knowledge**: Has no coupling to file descriptors, locks, or filesystem disks.

### Step 2.3: Refactor `Manifest` into a Transactional Persistence Store
* **Target:** `AlexKassel\ManifestEngine\Manifest`
* **Responsibilities:**
  * `$manifest->read(): ManifestDocument`: Reads from disk with shared lock and returns a fresh `ManifestDocument`.
  * `$manifest->write(ManifestDocument|array $document): self`: Validates and atomically writes to disk.
  * `$manifest->transaction(callable $callback): ManifestDocument`:
    * Acquires exclusive lock (`Cache::lock()->block()`).
    * Instantiates `ManifestDocument` from disk state.
    * Executes `$callback($document)`. Inside the callback, hundreds of loop iterations run entirely in-memory with zero disk I/O.
    * Validates and atomically persists the document **exactly once** before releasing lock.
  * `$manifest->mutate(callable $callback): ManifestDocument`: Convenient wrapper for `transaction()`.

---

## Phase 3: Architectural Decoupling & Strict Clean Code (P2)

### Step 3.1: Decouple Inspection Logic via `ManifestInspectionService`
* **Targets:**
  * `AlexKassel\ManifestEngine\Services\ManifestInspectionService` (new service)
  * `AlexKassel\ManifestEngine\Console\Commands\ManifestStatusCommand`
  * `AlexKassel\ManifestEngine\Console\Commands\ManifestValidateCommand`
* **Solution:**
  * Move byte formatting, file stat reading, and validation collation into `ManifestInspectionService`.
  * Keep console commands strictly as Thin Coordinators rendering `$this->table()`.

### Step 3.2: Strict Fallback & Class Constant Encapsulation
* **Targets:**
  * `AlexKassel\ManifestEngine\ManifestManager`
  * `AlexKassel\ManifestEngine\ManifestEngineServiceProvider`
* **Solution:**
  * Extract all literal defaults to class constants at the top of classes.

---

## Phase 4: Long-Term Enhancements & Extensibility (P3)

### Step 4.1: DTO Hydrator Interface & Complex Collections
* **Target:** `AlexKassel\ManifestEngine\Hydration\DtoHydrator`
* **Solution:**
  * Add `DtoHydratorInterface` to allow plugging in `Spatie\LaravelData` or `CuyZ\Valinor`.
  * Support nested typed collection casting.

---

## Execution Status

- [x] **Phase 1: Native Laravel-First Core & CLI Safety (P0)**
  - [x] 1.1: Replace Custom Atomic I/O with `Illuminate\Filesystem\Filesystem`
  - [x] 1.2: Adopt Laravel Atomic Locks (`Cache::lock()->block()`)
  - [x] 1.3: Implement Fail-Safe Command Signatures (`{name?}`)
- [x] **Phase 2: Architectural Separation & In-Memory Efficiency (P1)**
  - [x] 2.1: Container-Aware Schema Resolution
  - [x] 2.2: Implement Pure In-Memory `ManifestDocument`
  - [x] 2.3: Implement Transactional Store (`read`, `write`, `transaction`) in `Manifest`
- [x] **Phase 3: Architectural Decoupling & Strict Clean Code (P2)**
  - [x] 3.1: Decouple Inspection Logic via `ManifestInspectionService`
  - [x] 3.2: Strict Fallback & Class Constant Encapsulation
- [x] **Phase 4: Long-Term Enhancements & Extensibility (P3)**
  - [x] 4.1: DTO Hydrator Interface & Complex Collection Casting
