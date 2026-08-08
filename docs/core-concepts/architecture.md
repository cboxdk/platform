---
title: Architecture
weight: 21
description: The four layers of the package, the purity rule that separates them, and what that rule buys.
---

# Architecture

```
   your storage / project / CLI
              │
        (your mapper)
              │
        ┌─────▼──────┐
        │   intent   │   ServiceSpec, DatabaseSpec, BackupSpec, RunSpec, …
        └─────┬──────┘
              │  +  PlatformTarget
        ┌─────▼──────┐
        │  compiler  │   pure: same input, same bytes
        └─────┬──────┘
              │
        ┌─────▼──────┐
        │ ManifestSet│   ordered Kubernetes objects, content-hashed
        └─────┬──────┘
              │
      ┌───────┴────────┐
      │                │
   HashPlanner    your apply layer
```

## Intent

`readonly` value objects with named, typed fields. They carry no behaviour
beyond small derived questions (`ServiceSpec::autoscales()`,
`RuntimeSettings::keepsContainerAwake()`) and, critically, **no factory that
knows where they came from**. There is no `fromModel()`, no `fromArray()` that
reads config, no lookup.

That is not stylistic. The moment a spec can build itself from your storage, the
compiler can reach your storage through it — which is exactly the state this
package was extracted out of.

## The compiler

One rule, and everything else follows from it:

> `compile()` reads no database, no cluster, no configuration, no filesystem, no
> clock, no environment and no network, and dispatches nothing. Everything it
> needs arrives in the spec and the target.

What that buys:

- **Golden tests are possible.** You can record the exact bytes for an input and
  detect any change to them, because there is nothing else that could vary.
- **Plan/diff is arithmetic.** See [plan and diff](plan-and-diff.md).
- **Local and hosted agree.** Two products compiling the same intent for
  equivalent targets get the same objects, so "it worked locally" is evidence
  rather than a coincidence.
- **A compile is safe to run anywhere** — in a request, in a test, in a CLI, in
  a preview — because it cannot have a side effect.

## Manifests

`Manifest` is one Kubernetes object: `apiVersion`, `kind`, `name`, `namespace`
and a nested `body` array. The array is the honest representation at exactly one
place — the serialization edge — and nowhere else in the package.

`ManifestSet` is the ordered result of one compile. Order is meaningful: a
`PersistentVolumeClaim` and a `Secret` are emitted before the `Deployment` that
mounts them, because a pod scheduled against an object that does not exist yet
sits in a failure state nobody is reading.

Two things about a manifest are frozen and will not be tidied:

- the managed label `cortex.io/managed` and field manager `cortex-sync` — the
  identity every already-applied object carries in every live cluster, and the
  key an admission policy matches on;
- `Manifest::hash()` and its canonicalisation — consumers persist those digests
  as applied state, so changing the function marks every live object as changed.

## Ordering and hashing

`hash()` canonicalises before hashing: maps are sorted by key recursively, lists
keep their order, then the whole thing is JSON-encoded and SHA-256'd. So two
semantically identical objects hash identically even if they were built in a
different key order, and a genuine change always produces a different digest.

## Where the package stops

At `ManifestSet`. Applying, reconciling, watching, retrying, reporting status,
building images, provisioning nodes and holding credentials are all the
consumer's. That boundary is what makes the package embeddable in a hosted
control plane and in a laptop CLI without either inheriting the other's
machinery.
