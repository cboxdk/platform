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
| The spec value objects | `ServiceSpec`, `ProcessSpec`, `VolumeSpec`, `RuntimeSettings`, `RegistrySpec`, `DatabaseSpec`, `BackupSpec`, `BackupStorage`, `RestoreSpec`, `RunSpec`, `EnvironmentGatewaySpec`, `BindingSpec`, `ConnectionSource` — construct them |
| `Cbox\Platform\Capability\*` | `PlatformTarget`, `HttpAutoscaler`, `BackupCatalog`, `CustomerAccess` |
| `Cbox\Platform\Manifest\*` | `Manifest`, `ManifestSet` — including `hash()`, `hashes()`, `key()`, `toYaml()` |
| `Cbox\Platform\Plan\*` | `Plan`, `PlanEntry`, `PlanAction`, `HashPlanner` |
| The enums | `DatabaseEngine`, `ConnectionField`, `BackupType`, `BackupStatus`, `LifecycleState`, `FpmProfile`, `OpcacheJit` |
| `Cbox\Platform\Testing\CompilesPlatformIntent` | the trait's method names and signatures |

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
subclassing what it ships. But subclassing a compiler puts you on the internal
surface above: prefer implementing the contract, or wrapping the compiler and
post-processing its `ManifestSet`.

## What `0.x` means in practice

- Breaking changes land in **minor** releases (`0.1 → 0.2`) and are listed in
  the changelog with what to change.
- Patch releases (`0.1.0 → 0.1.1`) never change compiled output. If output has
  to change, that is a minor, and the reason is documented.
- Pin `^0.1` and read the changelog before moving. `1.0` will be cut when a
  second consumer has been in production long enough to have found what one
  consumer could not.
