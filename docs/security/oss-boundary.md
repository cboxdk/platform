---
title: Open-source boundary
weight: 52
description: What is in this package, what stays in the products built on it, and why the line falls where it does.
---

# Open-source boundary

## The rule

> This package owns **what an application platform resource means, and how that
> intent compiles into deterministic runtime artifacts.**
>
> A consumer owns **where intent comes from, how images are built, how compiled
> output is applied, and how the result is operated and billed.**

## In this package

- The typed application model: service, process, volume, runtime settings,
  registry, database, backup, restore, binding, route, one-off run.
- The deterministic Kubernetes compiler for those resources.
- Plan/diff primitives — content hashing, `Plan`, `PlanEntry`, `HashPlanner`.
- The capability model: `PlatformTarget` and its members.
- Product semantics that are genuinely shared: scale-to-zero intent,
  suspend/resume, autoscaling bounds, health, resource requests.

## Not in this package

Not because it is secret — some of it is simply not shared — but because none of
it is part of what an application *means*.

**Infrastructure.** Cluster provisioning, node pools, cloud-controller
integration, node bootstrap, mesh networking, addon distribution, registry host
trust. These describe a fleet, not an application.

**Commercial control plane.** Organizations, authentication, entitlements,
billing, quotas, audit, approvals, the UI.

**Provider integration.** Credentials, provider APIs, capacity, regions.

**Apply and operate.** The bridge to a cluster, server-side apply, drift
reconciliation, status and event ingestion, rollback orchestration, preview
environment lifecycle.

**Builds.** Source checkout, framework detection, Dockerfile generation,
BuildKit, registries. The runtime compiler consumes an image reference; how that
image came to exist is the consumer's. This is why `Build` is a first-class
resource in the products and not a compiler in this package.

## Why the line is here and not elsewhere

**Higher** — pulling the control plane in — would mean the package could not be
embedded in a CLI or a local development tool, which is the entire reason it
exists.

**Lower** — publishing only the value objects — would leave two products with
two compilers, and two compilers means "it worked locally" stops being evidence
about production. The compiler *is* the shared asset; a shared model with a
private compiler shares nothing that matters.

## Dependency direction

One way, and enforced by this package having no reference to any consumer and no
consumer package in its `require`:

```
Cbox Cortex  ─┐
              ├──►  cboxdk/platform
Cbox Local   ─┘
```

A change that would make this package depend on a consumer is a design error,
not a dependency to add.
