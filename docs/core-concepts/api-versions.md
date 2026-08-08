---
title: Kubernetes API versions
weight: 26
description: Which APIs the compiler writes against, which of them are promises, and how an installation moves an alpha group without waiting for a release.
---

# Kubernetes API versions

## What is written against

Ten API groups, and the split that matters is between the ones that promise
something and the ones that do not.

| Group | Stability | What for |
|---|---|---|
| `v1`, `apps/v1`, `batch/v1`, `policy/v1`, `rbac.authorization.k8s.io/v1` | stable | core objects |
| `gateway.networking.k8s.io/v1` | stable since Gateway API 1.0 | Gateway, HTTPRoute |
| `cert-manager.io/v1` | stable | Issuer, Certificate |
| `postgresql.cnpg.io/v1` | stable | CloudNativePG Cluster, ScheduledBackup |
| `snapshot.storage.k8s.io/v1` | stable | VolumeSnapshot |
| **`keda.sh/v1alpha1`** | **alpha** | ScaledObject |
| **`http.keda.sh/v1alpha1`** | **alpha** | HTTPScaledObject |
| **`gateway.envoyproxy.io/v1alpha1`** | **alpha** | ClientTrafficPolicy |

**A stable group will not change shape or disappear. An alpha group promises
nothing at all** — it can change between two minor releases of the add-on that
owns it, and a cluster running the newer one refuses an object written against
the older. KEDA has been on `v1alpha1` for years.

## Three unstable groups, each owned by a capability

The exposure is exactly three objects, and all three come from add-ons the target
already models. So **the capability that decides whether an add-on is installed
also decides which version of it is spoken**:

```php
new PlatformTarget(
    httpAutoscaler: new HttpAutoscaler(
        scaledObjectApiVersion: 'keda.sh/v1',
        httpScaledObjectApiVersion: 'http.keda.sh/v1beta1',
    ),
    gateway: GatewayImplementation::envoyGateway(
        clientTrafficPolicyApiVersion: 'gateway.envoyproxy.io/v1',
    ),
);
```

An installation that upgrades KEDA changes one value. It does not wait for a
release of this package, and it does not fork it.

## The surface is pinned by a test

`tests/ApiVersionTest.php` asserts the complete list, and separately that the
unstable part of it is exactly those three. Adding a fourth is fine; adding one
**without noticing** is what the test exists to prevent — an API dependency you
did not know you had is one you discover on the day a cluster upgrade breaks.

Writing that test found two objects the first sweep missed, because both are
conditional: a `PodDisruptionBudget` only appears above one replica, and a
`ScaledObject` only when CPU autoscaling is set. That is exactly how an API
dependency goes unnoticed.

## A target can need none of them

With a snapshot runtime the KEDA tier is never reached, and a non-Envoy gateway
has no `ClientTrafficPolicy`:

```php
new PlatformTarget(
    snapshotRuntime: $criu,
    gateway: GatewayImplementation::conformant('nginx'),
);
```

Everything left is stable. That is the honest answer to "what does this package
need from my cluster", and there is a test asserting it stays true.

## Schemas cached from real clusters

CI validates the compiled output against schemas **taken from clusters that
actually run this platform**, cached under `tests/Schemas`:

```bash
php bin/fetch-schemas.php cortex-eu1-cell1 kind-cortex-cell-dev
```

Read-only — it lists CustomResourceDefinitions and writes to nothing.

**Why a fixture rather than a public catalogue.** A catalogue answers "is this
valid for *some* version of that CRD". The fixture answers the question that
matters: "is this valid for the version *we* run". It also removes a network
fetch from a gate, and it makes an add-on upgrade behave like everything else
here — re-run the script after upgrading KEDA, and the schema change lands in
git as a diff, next to the golden files it might invalidate.

Only the ten kinds this package emits are cached, not the groups they live in:
CloudNativePG alone ships eleven CRDs and its `Cluster` schema is most of a
megabyte. A fixture nobody can read in a diff is a fixture nobody reads.

The public catalogue stays as the fallback for anything no reachable cluster
had, and `-ignore-missing-schemas` is still not used: a schema that resolves
nowhere fails the step.

**The script names what it could not find.** Run against the management cell and
the local dev cell, five of the ten were absent — the KEDA HTTP add-on, both
Gateway API kinds, the Envoy policy and VolumeSnapshot. Those are tenant-side
add-ons and those two clusters are not tenants, so this is not evidence they are
missing where they matter. It is evidence that the fixture is partial, said out
loud rather than quietly filled in from the internet.

## No core-version capability, deliberately

There is no `KubernetesVersion` on the target, because nothing branches on one.
Every core group used here has been stable since 1.21 or earlier, so a version
number would be a value the compiler reads and never acts on — a flag that
reports itself on and does nothing. It gets added when a real difference appears,
not before.
