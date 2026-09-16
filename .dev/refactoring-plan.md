# 🛠️ Refactoring & Code Simplification Plan: `alex-kassel/manifest-engine`

**Role:** Specialist in Refactoring and Ruthless Code Simplification  
**Package:** `packages/alex-kassel/manifest-engine`  
**Development Stage:** Active pre-1.0 (Zero backward compatibility overhead required)  
**Target Code Reduction:** ~35% - 45% of codebase elimination without capability loss  

---

## Executive Summary

This plan compiles all architectural defects, redundancies, framework anti-patterns, and critical concurrency flaws identified during the audit of `alex-kassel/manifest-engine`.

The refactoring roadmap is organized into actionable phases with checklists. Every step explicitly describes the **Problem** and the **Proposed Solution** adhering to:
* **Laravel-First Philosophy:** Eliminating custom low-level plumbing (`flock`, custom lock directories, raw regex hydrators, manual byte math) in favor of battle-tested framework tooling (`Cache::lock()`, `Filesystem::json()`, `Number::fileSize()`, `ValidationRuleParser`).
* **Ruthless Simplification:** Eradicating duplicate abstractions (`ManifestDocument`, `ManifestFactory`, wrapper methods) and dead code.
* **Strict Concurrency Safety:** Fixing TOCTOU race conditions and timestamp caching flaws.

---

## Master Issues & Priorities Index

| # | Issue / Finding | Category | Priority | Target Location |
|---|---|---|---|---|
| **1** | TOCTOU Race Condition in `saveOptimistic()` | Bug / Critical Risk | **Critical** | `src/Manifest.php:384-394` |
| **2** | Second-granularity `filemtime` and dirty cache flaws in `load()` | Bug / Critical Risk | **Critical** | `src/Manifest.php:289-301` |
| **3** | Split personality duplication: `Manifest` vs `ManifestDocument` | Redundancy | **High** | `src/ManifestDocument.php` |
| **4** | Reflection property hack on `Enum::$type` in `JsonSchemaCompiler` | Bad Practice | **High** | `src/Schemas/JsonSchemaCompiler.php:235-245` |
| **5** | Custom reflection DTO hydrator with regex docblock parser and `#[ArrayOf]` | Reinventing Wheel | **High** | `src/Hydration/DtoHydrator.php`, `ArrayOf.php` |
| **6** | Custom `flock` manager and lock directory instead of Laravel locks | Reinventing Wheel | **High** | `src/Storage/AtomicFileStorage.php:133-177` |
| **7** | Split instantiation lock semantics (`Manifest::open` vs `ManifestManager::open`) | Bad Practice | **High** | `src/Manifest.php:100-120` |
| **8** | Schema compiler breaks on string regex rules containing pipes (`|`) | Bug / Risk | **High** | `src/Schemas/JsonSchemaCompiler.php:125-127` |
| **9** | Path Traversal vulnerability in `ManifestMakeCommand` | Security Risk | **High** | `src/Console/Commands/ManifestMakeCommand.php:96-100` |
| **10** | Empty proxy factory `ManifestFactory` | Redundancy | **Medium** | `src/ManifestFactory.php` |
| **11** | Custom byte formatter instead of `Illuminate\Support\Number::fileSize()` | Reinventing Wheel | **Medium** | `src/Services/ManifestInspectionService.php:146-155` |
| **12** | Redundant double-save under `--force` in `ManifestMakeCommand` | Bad Practice | **Medium** | `src/Console/Commands/ManifestMakeCommand.php:108-113` |
| **13** | Separate file read and JSON decode instead of `Filesystem::json(..., lock: true)` | Reinventing Wheel | **Medium** | `src/Storage/AtomicFileStorage.php:74`, `Manifest.php:327` |
| **14** | `README.md` documentation contradicts code regarding atomic writes on `set()` | Documentation Drift | **Medium** | `README.md:193-195` |
| **15** | Lock file accumulation without cleanup in local storage | Risk | **Medium** | `src/Storage/AtomicFileStorage.php:193-200` |
| **16** | Fragmented command tests across two separate test files | Redundancy | **Medium** | `tests/ConsoleCommandsTest.php`, `ManifestCommandTest.php` |
| **17** | Raw empty array literals `return [];` violating fallback encapsulation | Style / Rule Violation | **Low** | `src/Schemas/BaseSchema.php:28,36` |

---

## Phase 1: Critical Concurrency & Cache Integrity (Checklist)

- [x] **Step 1.1: Eliminate TOCTOU Race Condition in `saveOptimistic()`**
  * **Problem:** In `Manifest::saveOptimistic()`, `$currentHash = $this->hash()` is evaluated before taking an exclusive file lock in `save()`. A concurrent process can mutate the file between the hash check and the write operation, silently overwriting external changes and defeating optimistic concurrency protection.
  * **Proposed Solution:** Move the expected hash verification inside `StorageDriver::mutateLocked()` (under an exclusive lock) before writing new content. If the locked on-disk content hash does not match `$expectedHash`, throw `ManifestConcurrentModificationException` immediately.

- [x] **Step 1.2: Fix Cache Invalidation & `filemtime` Second-Granularity Flaw in `load()`**
  * **Problem:** `Manifest::load()` skips reloading if `filemtime` equals `$this->lastLoadedMtime`. Because standard `filemtime` operates on 1-second resolution, modifications within the same second are ignored. Furthermore, if `$this->isDirty` is `true`, `load()` unconditionally returns stale memory data even if disk state has been altered externally.
  * **Proposed Solution:** Replace raw `filemtime` checks with robust validation. When refreshing or verifying staleness under concurrency, inspect content hashes or ensure `fresh()` explicitly clears dirty flags and forces a clean read. During mutations, guarantee atomic read-modify-write semantics inside `mutate()` so in-memory dirty state cannot overwrite concurrent external updates.

---

## Phase 2: Structural De-duplication & Codebase Reduction (Checklist)

- [x] **Step 2.1: Ruthlessly Eliminate `ManifestDocument` and Consolidate in `Manifest`**
  * **Problem:** `Manifest` (668 lines) and `ManifestDocument` (288 lines) maintain separate instances of `$data`, `$isDirty`, and duplicate identical methods (`get()`, `set()`, `append()`, `forget()`, `all()`, `toDto()`). Wrapper methods (`read()`, `write()`, `transaction()`, `batch()`) create confusion and fragmented API paradigms.
  * **Proposed Solution:** Delete `ManifestDocument.php` entirely (-288 lines). Consolidate all document manipulation methods inside `Manifest.php`. Remove redundant wrappers:
    * Remove `batch()` (duplicate of `mutate()`).
    * Remove `transaction()` (which wrapped `ManifestDocument`).
    * Remove `read()` and `write()` in favor of direct `$manifest->mutate()` or `$manifest->save()`.

- [x] **Step 2.2: Delete Redundant Proxy `ManifestFactory`**
  * **Problem:** `ManifestFactory.php` (51 lines) is a 1-method class forwarding 8 parameters from `Manifest::open()` to `new Manifest()`. `ManifestManager::open()` bypasses this factory completely, proving it is useless overhead.
  * **Proposed Solution:** Delete `ManifestFactory.php` (-51 lines). Consolidate default dependency instantiations directly into `Manifest::open()` or constructor defaults.

- [x] **Step 2.3: Unify Manifest Instantiation & Lock Semantics**
  * **Problem:** `ManifestManager::open()` injects container-configured `AtomicFileStorage` with `LockProvider`, whereas static `Manifest::open()` instantiates storage without container context, resulting in inconsistent lock mechanisms for the same file.
  * **Proposed Solution:** Ensure `Manifest::open()` resolves dependencies from `Container::getInstance()` when running inside Laravel, harmonizing lock drivers and validator instances regardless of instantiation path.

- [x] **Step 2.4: Consolidate Console Command Test Suites**
  * **Problem:** `tests/ConsoleCommandsTest.php` and `tests/ManifestCommandTest.php` test overlapping console commands (`manifest:status`, `manifest:validate`, `manifest:make`) and duplicate registry setups.
  * **Proposed Solution:** Merge `ManifestCommandTest.php` into `ConsoleCommandsTest.php`, removing redundant scaffolding and fixtures.

---

## Phase 3: Laravel-First Wheel Elimination (Checklist)

- [x] **Step 3.1: Replace Custom `flock` Plumbing with Laravel `LockProvider` / `FileLock`**
  * **Problem:** `AtomicFileStorage.php` contains 50+ lines of raw `fopen`, non-blocking `flock` polling loops with `microtime`, `usleep`, and maintains an ad-hoc `.lock` folder (`storage/framework/manifest-locks`).
  * **Proposed Solution:** Replace the custom loop with Laravel's native lock tooling:
    * When in a Laravel application, rely on `LockProvider` (`Cache::lock(...)` with blocking timeout guarantees).
    * When running standalone, utilize Laravel's `Illuminate\Cache\FileStore` / `FileLock` or an encapsulated, clean implementation, eliminating manual file descriptor polling and lock folder clutter.

- [x] **Step 3.2: Use Native `Filesystem::json(..., lock: true)`**
  * **Problem:** Reading manifests involves manual `sharedGet()` followed by separate `trim()` and `json_decode()` boilerplate.
  * **Proposed Solution:** Adopt `Illuminate\Filesystem\Filesystem::json($path, flags: JSON_THROW_ON_ERROR, lock: true)` for single-call shared-lock JSON retrieval.

- [x] **Step 3.3: Replace Custom `formatBytes` with `Illuminate\Support\Number::fileSize()`**
  * **Problem:** `ManifestInspectionService.php` implements manual math dividing by 1024 with 4 dedicated constants.
  * **Proposed Solution:** Replace `formatBytes()` with Laravel's battle-tested `Number::fileSize($bytes, precision: 1)`.

- [x] **Step 3.4: Strip Regex Docblock Parser & `#[ArrayOf]` from `DtoHydrator`**
  * **Problem:** `DtoHydrator.php` (233 lines) attempts to act as a full reflection object mapper with regex docblock comment parsing (`DOCBLOCK_ARRAY_PATTERN`) and custom attributes (`ArrayOf`).
  * **Proposed Solution:** Remove the fragile regex parser and `ArrayOf.php`. Retain clean, predictable hydration through the `ManifestDto` contract (`fromArray` / `toArray`), native constructors, or static `from()`. For complex DTO requirements, recommend established ecosystem standards (`spatie/laravel-data`).

- [x] **Step 3.5: Utilize Laravel `ValidationRuleParser` in `JsonSchemaCompiler`**
  * **Problem:** `JsonSchemaCompiler` manually parses rule strings (`min:`, `max:`, `in:`) with ad-hoc string splitting that fails on complex rule expressions.
  * **Proposed Solution:** Leverage `Illuminate\Validation\ValidationRuleParser::parse()` to normalize and decompose Laravel validation rules into structured names and parameters.

---

## Phase 4: Schema Compiler, CLI, and Quality Fixes (Checklist)

- [x] **Step 4.1: Eliminate Reflection Hack on `Enum::$type` in `JsonSchemaCompiler`**
  * **Problem:** `JsonSchemaCompiler` accesses protected `$type` via `ReflectionProperty`, completely bypassing `Rule::enum()->only()` / `->except()` constraints.
  * **Proposed Solution:** Treat `Rule::enum()` as `\Stringable`. Utilizing `(string) $rule` generates `'in:"case1","case2"'` honoring `only` and `except` without reflection.

- [x] **Step 4.2: Fix Pipe Splitting Bug on Regex Rules in `JsonSchemaCompiler`**
  * **Problem:** `explode('|', $rules)` shatters regex rules like `'regex:/^[a-z|0-9]+$/'` into invalid fragments.
  * **Proposed Solution:** Process rules via `ValidationRuleParser` or normalize array definitions before tokenizing string rules.

- [x] **Step 4.3: Prevent Path Traversal in `ManifestMakeCommand`**
  * **Problem:** `ManifestMakeCommand` accepts user-supplied paths without traversal sanitization (`../`), allowing arbitrary file scaffolding.
  * **Proposed Solution:** Implement path normalization and traversal prevention (e.g. adopting `normalizeWorkspacePath` semantics).

- [x] **Step 4.4: Remove Redundant Double-Save in `ManifestMakeCommand`**
  * **Problem:** Running `manifest:make --force` on a nonexistent file causes `$manifest->init()` to save, followed immediately by an identical second save in the `--force` block.
  * **Proposed Solution:** Simplify logic to a single conditional save action.

- [x] **Step 4.5: Clean Up Unused Lock Files (Garbage Accumulation)**
  * **Problem:** Custom file locks leave permanent `.lock` artifacts in `manifest-locks` without lifetime garbage collection.
  * **Proposed Solution:** When transitioning to Laravel's atomic cache locks or `FileStore`, automatic TTL management will eliminate persistent lock file accumulation.

- [x] **Step 4.6: Reconcile `README.md` Documentation with Code**
  * **Problem:** `README.md` erroneously claims individual `set()` calls acquire exclusive locks and write atomically to disk.
  * **Proposed Solution:** Update documentation to clearly distinguish in-memory modifications (`set()->save()`) from atomic locked transactions (`mutate(callable)`).

- [x] **Step 4.7: Enforce Strict Fallback Encapsulation in `BaseSchema`**
  * **Problem:** `BaseSchema::messages()` and `attributes()` return raw literal `[]` instead of class constants.
  * **Proposed Solution:** Define `public const DEFAULT_EMPTY_ARRAY = [];` at the top of `BaseSchema` and return the constant.
