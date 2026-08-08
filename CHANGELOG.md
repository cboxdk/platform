# Changelog

All notable changes to `cboxdk/platform` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While
the package is `0.x` the public API may change in a minor release; see
[Public API](docs/core-concepts/public-api.md) for what is and is not covered.

## [Unreleased]

## [0.1.0] — 2026-08-08

First extraction from Cbox Cortex. The compiler and the typed platform model that
were already Cortex's reusable centre, lifted out unchanged.

### Added

- **Typed platform intent** — `ServiceSpec`, `ProcessSpec`, `VolumeSpec`,
  `RuntimeSettings`, `RegistrySpec`, `BindingSpec`, `ConnectionSource`,
  `DatabaseSpec`, `BackupSpec`, `BackupStorage`, `RestoreSpec`, `RunSpec`,
  `EnvironmentGatewaySpec`. Persistence-agnostic value objects — no ORM, no
  framework, no IO.
- **Deterministic Kubernetes compiler** — `ServiceCompiler`,
  `EngineDatabaseCompiler` (routing to `CnpgDatabaseCompiler` and
  `StatefulDatabaseCompiler`), `EnvironmentGatewayCompiler`, `RunJobCompiler`,
  `BackupCompiler`.
- **Plan/diff primitives** — `Manifest`, `ManifestSet`, `HashPlanner`, `Plan`,
  `PlanEntry`, `PlanAction`.
- **Capability model** — `PlatformTarget` plus `SnapshotRuntime`,
  `HttpAutoscaler`, `Certificates` and `PostgresBackend`, so a target's real
  capabilities are typed input rather than branches in the compiler.
- **Testing support** — `Cbox\Platform\Testing\CompilesPlatformIntent`, the trait
  the package's own golden tests use.
- Golden manifest fixtures and determinism tests carried over from Cortex, byte
  for byte.

[Unreleased]: https://github.com/cboxdk/platform/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/cboxdk/platform/releases/tag/v0.1.0
