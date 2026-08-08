# Changelog

All notable changes to `cboxdk/platform` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While
the package is `0.x` the public API may change in a minor release; see
[Public API](docs/core-concepts/public-api.md) for what is and is not covered.

## [Unreleased]

## [0.2.0] — 2026-08-08

### Added

- **`Certificates` capability** — how a target signs the hostnames it serves:
  `Certificates::acme()`, `::selfSigned()`, `::certificateAuthority()`. The
  first capability two real targets cannot agree on: ACME's HTTP-01 challenge
  needs the authority to reach the hostname, which a local cluster cannot offer
  at any price, so it would compile an Issuer whose orders never validate.
- **`ResourceRequirements`** on `ServiceSpec` — CPU and memory requests and
  limits, applied to every container a service compiles. Absent takes
  `ResourceRequirements::defaults()`, which is the `100m`/`128Mi` request that
  was previously inline in the compiler, so an unsized service compiles
  byte-identically.
- **Field-level plan detail** — `HashPlanner::planAgainst()`, `ManifestDiff`,
  `FieldChange`, `FieldChangeKind`. Produces `~ spec.replicas 1 → 3` rather than
  `~ Deployment/web`. Deliberately a separate call, not a wider `Planner`
  contract: the detail costs the previous body, and a consumer that does not
  retain bodies keeps the cheap plan unchanged. It takes hashes and bodies
  separately — hashes decide every action and are complete, bodies only
  decorate and are always partial.
  **Secrets are always redacted**, whatever a caller passes: a plan is rendered
  into a browser, an API response and a log, so the value never appears and the
  path does. Consumers must additionally not retain a `Secret`'s body at all —
  redaction protects the plan, not your database.
- **`CredentialBoundaryTest`** — asserts that `Secret` is the only kind this
  package emits that can carry a credential, which is what makes "filter by
  kind" a sufficient rule for a consumer rather than a heuristic.
- **`Manifest`/`ManifestSet` round-tripping** — `toArray()`, `fromArray()`, and
  `ManifestSet::find()`, so a consumer can retain and rehydrate what it applied.
- **Testing support** — `FakeCompiler`, `FakePlanner`, `FakeSnapshotRuntime` and
  `SpecFactory`, for testing the layer above the compiler. Used by this
  package's own suite.
- **An architecture gate** — the purity and boundary rules were documented but
  unenforced. `tests/ArchitectureTest.php` now asserts them, including a
  line-by-line scan for IO that catches the static-call-into-a-value-object
  shape the original audit missed.
- **A coverage gate** — 90% in CI, currently 93%, run in a container because the
  runner is guaranteed to have docker and not a coverage driver. It found three
  shipped enums at 0% and `BackupType`'s refusal logic at 21%; both are covered
  now. `composer mutate` exists but is not gated: `pest-plugin-mutate` 5.0.1
  crashes before reporting, so the mutation score is unknown rather than good.
- **Kubernetes name validation** — a service or process name that the API server
  would reject is refused here, in the product's own words, rather than failing
  mid-deploy in a vocabulary the customer never opted into.

### Changed

- **BREAKING** — `EnvironmentGatewaySpec` no longer takes `acmeServer` or
  `acmeEmail`. Who signs a certificate is a fact about the target, not about the
  environment's intent; it moved to `PlatformTarget::$certificates`.
  `EnvironmentGatewayCompiler` now takes a `PlatformTarget`.
- `ServiceCompiler` refuses a service that autoscales on CPU with no CPU
  request. CPU *utilization* is a percentage of the request, so without one the
  metric is unavailable and the service would never scale while reporting
  autoscaling as on.

### Note on compiled output

Unchanged for every existing input. The golden files are untouched: the default
target still issues over ACME with the same directory URL, and an unsized
service still compiles the same request block.

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

[Unreleased]: https://github.com/cboxdk/platform/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/cboxdk/platform/releases/tag/v0.2.0
[0.1.0]: https://github.com/cboxdk/platform/releases/tag/v0.1.0
