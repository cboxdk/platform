---
title: Core concepts
weight: 20
description: How the package is put together, why the compiler is pure, and what the public API promises while it is 0.x.
---

# Core concepts

- [Architecture](architecture.md) — the four layers, and the one rule that holds
  them apart.
- [The platform target](platform-target.md) — declaring what a cluster can do,
  without feature flags.
- [Labels on compiled objects](labels.md) — the standard set, the two vendor
  prefixes, and which keys can never be renamed.
- [Kubernetes API versions](api-versions.md) — what is written against, what is
  alpha, and how an installation moves an alpha group itself.
- [Plan and diff](plan-and-diff.md) — why "did anything change" never touches a
  cluster.
- [Public API](public-api.md) — what is supported, what is internal, and what
  `0.x` means for you.

- [Databases and the node that dies](databases.md) — why the engines compile down different paths, and what has to exist before one can have replicas.
