---
title: Requirements
weight: 3
description: PHP and dependency versions the resolver actually enforces.
---

# Requirements

Taken from `composer.json`. Nothing below is an aspiration — it is what Composer
will refuse to install without.

## Runtime

| Requirement | Constraint | Why |
|---|---|---|
| PHP | `^8.4` | `readonly` classes, enums, `new` in initializers, property hooks in the toolchain |
| `symfony/yaml` | `^7.0 \|\| ^8.0` | `Manifest::toYaml()` — the only place the package serializes |

That is the entire runtime graph. There is **no framework dependency**: no
Laravel, no ORM, no HTTP client, no container. The compiler cannot reach a
database or a network because nothing in the tree can.

## Development

| Requirement | Constraint |
|---|---|
| `pestphp/pest` | `^4.0 \|\| ^5.0` |
| `phpstan/phpstan` | `^2.0` (level max, no baseline) |
| `laravel/pint` | `^1.27` |
| `illuminate/collections` | `^12.0 \|\| ^13.0` |

`illuminate/collections` is **development only** and deliberately so: the
package's tests were carried over from Cortex and read more clearly with
`collect()`, but a consumer installing this package gets none of it. If you are
auditing the dependency graph, `composer show --no-dev` is the honest view.

## Kubernetes

The package compiles objects; it never talks to an API server, so it has no
version handshake with one. What the emitted objects assume of the cluster is a
property of your [`PlatformTarget`](core-concepts/platform-target.md) and of the
APIs you have installed — Gateway API, cert-manager, KEDA and its HTTP add-on,
CloudNativePG — not of this package.
