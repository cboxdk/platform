---
title: Public API
weight: 24
description: What is supported, what is internal despite being a public PHP class, and what 0.x means in practice.
---

# Public API

The package is `0.x`. The model and compiler come out of a system running real
customer workloads, so the *behaviour* is not speculative — but the *API* is
young, and a minor release may change it.

PHP has no way to say "public to the world" versus "public to the package", so
the boundary is stated here instead.

## Supported

Changes to these follow semantic versioning as soon as `1.0` lands, and are
called out in the [changelog](../../CHANGELOG.md) before then.

| Surface | What is promised |
|---|---|
| `Cbox\Platform\Contracts\*` | the interfaces: `Compiler`, `DatabaseCompiler`, `BackupCompiler`, `GatewayCompiler`, `RunCompiler`, `Planner`, `SnapshotRuntime` |
| The spec value objects | `ServiceSpec`, `ProcessSpec`, `VolumeSpec`, `RuntimeSettings`, `RegistrySpec`, `DatabaseSpec`, `BackupSpec`, `BackupStorage`, `RestoreSpec`, `RunSpec`, `EnvironmentGatewaySpec`, `BindingSpec`, `ConnectionSource`, `ResourceRequirements` — construct them |
| `Cbox\Platform\Capability\*` | `PlatformTarget`, `HttpAutoscaler`, `BackupCatalog`, `CustomerAccess`, `Certificates`, `CertificateSource` |
| `Cbox\Platform\Manifest\*` | `Manifest`, `ManifestSet` — including `hash()`, `hashes()`, `key()`, `find()`, `toYaml()`, `toArray()`, `fromArray()` |
| `Cbox\Platform\Plan\*` | `Plan`, `PlanEntry`, `PlanAction`, `HashPlanner` (including `planAgainst()`), `ManifestDiff` (including `ManifestDiff::REDACTED`), `FieldChange`, `FieldChangeKind` |
| The enums | `DatabaseEngine`, `ConnectionField`, `BackupType`, `BackupStatus`, `LifecycleState`, `FpmProfile`, `OpcacheJit` |
| `Cbox\Platform\Testing\*` | `CompilesPlatformIntent`, `FakeCompiler`, `FakePlanner`, `FakeSnapshotRuntime`, `SpecFactory` |

**The compiled output is part of the API too** — arguably the most important
part, since it is what reaches a cluster. A change to what an object contains is
a breaking change even when no PHP signature moved, and it is recorded in the
golden files and the changelog.

## Internal

Public PHP classes, but not a supported surface. Depend on the contract instead.

- The concrete compiler classes' **internals** — every private method, every
  constant, and the shape of their constructor arguments. `new
  ServiceCompiler($target)` is fine; assuming it will always take exactly one
  argument is not.
- `Cbox\Platform\Runtime\*` implementations. `CortexSnapshotRuntime` and
  `ZeropodSnapshotRuntime` describe two specific runtimes and will change with
  them; `SnapshotRuntime` is the stable thing.
- Anything under `tests/`.

## Extending

Nothing is `final`, on purpose — a library should not stop you decorating or
subclassing what it ships. But be clear about what that buys, because "not
final" promises more than it delivers here:

- **The compilers have a two-method public surface and everything else is
  private.** A subclass compiles, and has nothing meaningful to override.
  **Decoration is the supported path** — implement the contract, wrap the real
  compiler, post-process its `ManifestSet`. There is a worked example in
  [extension points](../extension-points/_index.md) and a test in the suite that
  keeps it working.
- **The specs are `readonly` classes, and that constrains subclasses.** A
  subclass must also be `readonly`, and a readonly property cannot have a
  default — so an extra field is threaded through your constructor and passed to
  `parent::__construct()`, not declared with a value. That is the price of specs
  being immutable, and it is worth knowing before you plan around it.

A decorator must stay pure like everything else on the compile path. One that
reads a database gives the whole path a side effect, and every golden and
determinism test downstream of it stops meaning anything.

## What `0.x` means in practice

- Breaking changes land in **minor** releases (`0.1 → 0.2`) and are listed in
  the changelog with what to change.
- Patch releases (`0.1.0 → 0.1.1`) never change compiled output. If output has
  to change, that is a minor, and the reason is documented.
- Pin `^0.1` and read the changelog before moving. `1.0` will be cut when a
  second consumer has been in production long enough to have found what one
  consumer could not.
