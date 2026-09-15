# Changelog

All notable changes to `alex-kassel/manifest-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `AlexKassel\ManifestEngine\Contracts\StorageDriver` contract for atomic I/O and locking.
- `AlexKassel\ManifestEngine\Storage\AtomicFileStorage` driver with shared lock reads, exclusive lock mutations, and temporary-file atomic replacement (`rename`).
- `AlexKassel\ManifestEngine\Validation\ManifestValidator` for isolated schema validation decoupled from service locators.
- `AlexKassel\ManifestEngine\Hydration\DtoHydrator` for typed DTO hydration and serialization.
- `AlexKassel\ManifestEngine\ManifestFactory` for assembling configured manifest instances with explicit dependencies.
- `ManifestManager::get(string $name, ?string $basePath = null)` and `Manifest::get()` facade method to open registered manifests by alias.
- Support for `nullable`, `email`, `uuid`, and object-based validation rules in `JsonSchemaCompiler`.
- Unit and integration tests for `AtomicFileStorage`, `DtoHydrator`, `JsonSchemaCompiler`, and `ManifestManager::get()`.

### Changed
- Refactored `Manifest` class into a clean domain state repository adhering to Single Responsibility Principle (SRP).
- Replaced dangerous `ftruncate` writes in `mutate()` with safe atomic temporary file replacement.
- Completely removed container service locators (`Container::getInstance()`) from core domain entities.
- Completely purged all domain-specific workspace terminology from the engine.

## [v0.0.1] - 2026-09-15

### Added
- Initial release of `Manifest` document manager.
- Atomic mutation transactions with exclusive file locks (`flock`).
- Dot-notation query and manipulation (`get`, `set`, `has`, `append`).
- `ManifestSchema` contract for default states and schema validation.
- Typed exceptions (`ManifestNotFoundException`, `ManifestValidationException`, `ManifestException`).
- Laravel ServiceProvider and Facade.
