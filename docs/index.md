---
title: Cbox Platform
weight: 1
description: The typed application model, deterministic Kubernetes compiler, and plan/diff primitives shared by Cbox Local and Cbox Cortex.
---

# Cbox Platform

Cbox Platform is a library, not a service. It answers one question — *what does
an application resource mean, and what Kubernetes objects does that intent
compile to?* — and answers it identically every time.

```
Application intent  →  Cbox Platform  →  Compiled Kubernetes resources  →  your apply layer
```

## The mental model

Three things, in order.

**1. Intent is typed.** A service, a database, a route, a binding, a backup, a
one-off run — each is a `readonly` value object with named, typed fields.
Nothing about where it came from is encoded in it. Cbox Cortex builds these from
Postgres rows; Cbox Local will build them from a Git worktree and a Dockerfile;
your test builds one in six lines.

**2. Compilation is a pure function.** `compile(spec)` returns a `ManifestSet`
and does nothing else. No query, no cluster read, no HTTP, no clock, no
randomness, no job dispatch. Everything it needs is in the spec and the
[`PlatformTarget`](core-concepts/platform-target.md).

**3. Planning is arithmetic.** Because compilation is deterministic, the
difference between what you want and what you last applied is a comparison of
content hashes — no cluster involved. That is the whole
[plan/diff](core-concepts/plan-and-diff.md) mechanism.

Everything after that — applying, reconciling, watching status, building images,
provisioning nodes — is the consumer's, and deliberately not here.

## Sections

- **[Getting started](getting-started/_index.md)** — install it, compile
  something, test with the shipped trait.
- **[Core concepts](core-concepts/_index.md)** — architecture, the target model,
  plan/diff, and what the public API covers while the package is `0.x`.
- **[Cookbook](cookbook/_index.md)** — compiling with no framework at all, and
  writing the mapper that turns your own storage into platform intent.
- **[Extension points](extension-points/_index.md)** — the contracts a consumer
  implements or swaps.
- **[Security](security/_index.md)** — the threat model, and the boundary
  between what is open source here and what stays in the products.

## Reference consumers

- **Cbox Cortex** — hosted Kubernetes platform; maps Eloquent models to platform
  intent and applies the result to production clusters over a Go bridge.
- **Cbox Local** — local production-like development on kind.

Neither is required to use this package, and nothing here depends on either.
