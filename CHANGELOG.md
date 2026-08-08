# Changelog

All notable changes to `cboxdk/platform` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While
the package is `0.x` the public API may change in a minor release; see
[Public API](docs/core-concepts/public-api.md) for what is and is not covered.

## [Unreleased]

### Added

- **The Kubernetes recommended label set** — `instance`, `version`, `component`
  and `part-of` join `name` and `managed-by`, built in one place instead of by
  five compilers separately. These are the labels ArgoCD, Backstage, Lens,
  `kubectl -l` and Prometheus relabeling group on. `version` goes on the workload
  only; on every object it would mark the Service and the Namespace as changed
  every time an image tag moves. `ServiceSpec::$partOf` is new and optional.
- **`Placement` capability** — topology spread, node selector and tolerations
  moved out of the compiler and onto the target. An application and its placement
  are two different designs: what a service *is* is authored once and runs
  anywhere, while *where its pods land* is a fact about the cluster.
  `Placement::singleNode()` emits no constraint at all, which is what a kind
  cluster should get.
- **`PlatformIdentity`** — the label prefix, the field manager and the
  resource-name prefix, in one place. Nothing in the compiled output spells a
  product name any more: `cortex.io/*`, `cortex-sync`, `cortex-gateway`,
  `cortex-acme` and `cortex:cluster-reader` were literals in five compilers. The
  default prefix is now `platform.cbox.dk`, a domain Cbox owns; `cortex.io` is
  not, and a real company is on it.
- **`GatewayImplementation`** — `gatewayClassName` was the literal `cortex`, so a
  Gateway compiled for any other cluster names a class no controller claims: it
  applies, never gets an address, and serves nothing, with no error anywhere.
  This was the second thing a local cluster could not inherit. It also decides
  whether Envoy Gateway's `ClientTrafficPolicy` is emitted, since the Gateway API
  has no portable way to ask for PROXY protocol or client-IP detection.

- **API versions are the owning capability's to set.** The three unstable groups
  this package writes against — `keda.sh/v1alpha1`, `http.keda.sh/v1alpha1`,
  `gateway.envoyproxy.io/v1alpha1` — are now values on `HttpAutoscaler` and
  `GatewayImplementation`, so an installation that upgrades KEDA changes one
  value instead of waiting for a release. `ApiVersionTest` pins the complete
  surface, and separately that the unstable part of it is exactly those three;
  writing it found two objects the first sweep missed, both conditional.
- **`GatewayImplementation::conformant()` emits no `ClientTrafficPolicy`.** The
  capability existed but nothing read it — the policy was emitted unconditionally
  against a CRD that only Envoy Gateway installs, so the apply would have failed
  on any other implementation. Caught by the API-surface test.

### Migration

**Every label key and the field manager changed.** Nothing is released, so there
is no compatibility to preserve and none is attempted — the legacy prefix is gone
rather than dual-emitted. Consumers must move together: a consumer whose
admission policy still matches `cortex.io/managed` would leave every compiled
object writable by its customer.

Two things are free to change today and not free once an installation has applied
anything: the **field manager** (server-side apply records ownership under it, so
changing it orphans every field the platform owns) and the **selector labels**
(`spec.selector` is immutable, so the objects have to be recreated).

- **The compiled output is validated against real Kubernetes schemas** in CI,
  CRDs included (CloudNativePG, KEDA, cert-manager, Gateway API). A golden file
  proves the output did not change; it cannot prove the output is valid, and a
  bug recorded into a golden is locked in with the suite green. 27 of 27 objects
  validate today — the point is the twenty-eighth. Missing schemas fail the step
  rather than being skipped.
- Packagist metadata: `homepage`, and keywords covering the platform-engineering
  vocabulary the package actually belongs to.

## [0.2.1] — 2026-08-08

Packaging only. No source change, no behaviour change, no golden change.

### Fixed

- **Documentation and tests were missing from the released archive.**
  `.gitattributes` export-ignored `/docs` and `/tests`, so v0.1.0 and v0.2.0
  shipped with no documentation in the tarball — which makes a documented package
  look undocumented to anything that reads a tagged release, and leaves the
  security docs pointing at tests that were not there to check. The sibling
  cboxdk packages ship their full tree; this one does now too.
- **Two Composer `bin` entries that could not work.** `check-licenses.php` and
  `generate-sbom.php` are this repo's own tooling. Declared as `bin`, they were
  installed into consumers' `vendor/bin` pointing at a package root with no
  `composer.lock` — they exited non-zero rather than passing silently, but a tool
  that cannot work should not be published. They now run only through
  `composer license-check` and `composer sbom` here.
- **A workflow comment promised an artifact that did not exist.** The SBOM gate
  explained that exact resolved versions live in "the uploaded artifact below";
  there was no upload step. There is now — `sbom-<tag>`, on release tags, which
  without a committed lockfile is the only reproducible record of what a release
  resolved.

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

[Unreleased]: https://github.com/cboxdk/platform/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/cboxdk/platform/releases/tag/v0.2.1
[0.2.0]: https://github.com/cboxdk/platform/releases/tag/v0.2.0
[0.1.0]: https://github.com/cboxdk/platform/releases/tag/v0.1.0
