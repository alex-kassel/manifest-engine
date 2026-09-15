# Changelog

All notable changes to `alex-kassel/manifest-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v0.0.1] - 2026-09-15

### Added
- Initial release of `Manifest` document manager.
- Atomic mutation transactions with exclusive file locks (`flock`).
- Dot-notation query and manipulation (`get`, `set`, `has`, `append`).
- `ManifestSchema` contract for default states and schema validation.
- Typed exceptions (`ManifestNotFoundException`, `ManifestValidationException`, `ManifestException`).
- Laravel ServiceProvider and Facade.
