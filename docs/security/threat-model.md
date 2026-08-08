---
title: Threat model
weight: 51
description: What a package that performs no IO can still get dangerously wrong, and what is explicitly the consumer's responsibility.
---

# Threat model

This package performs no IO. It opens no socket, reads no file, holds no
credential and authenticates nobody. That removes whole categories of risk — and
leaves a real one: **what it emits is applied to a cluster.** A mistake here
becomes a misconfiguration there, at every consumer at once.

## What this package is responsible for

**Secrets stay in Secrets.** A value passed as `envSecret` is compiled into a
`Secret` referenced by the pod, never inlined in a pod spec, a ConfigMap, a
label, an annotation or an argument list. The same holds for registry
credentials (a `dockerconfigjson` Secret) and database bindings, which compile to
a `secretKeyRef` at the Secret the engine already owns rather than to a copied
value — so the control plane never holds the password at all, and a rotation
reaches the workload on the pod's next start.

**A plan never carries secret material.** `HashPlanner::planAgainst()` redacts
every value inside a `Secret`, whatever a caller passes it — a plan is rendered
into a browser, returned by an API and written to logs, so a field diff that
printed `stringData.APP_KEY old → new` would publish the customer's key to all
three. The path survives; the value does not.

This is a guard added after the failure, not before it: the first version of
field-level plan detail printed both the old and the new value of a rotated
key. See "What is the consumer's" below — redaction is only half of the fix.

**Grants are no wider than the intent.** RBAC compiled for a customer's own
kubectl is namespace-scoped, bound to groups rather than to people, and emits
nothing at all when no identity provider is configured. The one cluster-wide
grant is read-only.

**Namespaces are respected.** A spec for one namespace does not compile a
reference reaching another.

**Determinism.** It belongs in this list. Plan/diff and drift detection are how a
consumer knows what is in a cluster; losing byte-stability makes both lie.

## What is the consumer's

**Everything about applying.** Authentication to the cluster, transport
security, who may trigger a deploy, what the field manager is allowed to touch,
and admission policy. This package cannot enforce any of it.

**Where the intent came from.** If your mapper will build a spec from
attacker-controlled input, validate there. The compiler treats a spec as a
statement of intent already authorized — it is not an authorization boundary and
must not be used as one.

**Credential storage.** The package takes credential *values* in specs, in
memory, for the duration of a compile. Encrypting them at rest, scoping them,
and rotating them are yours.

**Not retaining a `Secret`'s compiled body.** If you keep compiled bodies — to
show a field diff, to detect drift, for any reason — exclude `kind === 'Secret'`.
Redaction protects the plan; it does not protect your database, and a retained
Secret body is the customer's env values, registry password and database
credentials in whatever column you keep applied state in, which is the one that
lands in every backup and support export. `Secret` is the only kind this package
emits that can carry a credential, and `CredentialBoundaryTest` asserts it, so
filtering by kind is sufficient rather than a heuristic.

**Cluster hardening.** Pod security standards, network policy, node isolation,
runtime security — none of it is here.

## Known sharp edges

**A target that overstates its nodes.** Declaring a snapshot runtime the nodes
do not have silently disables the cold-start tier that would have worked. Not a
vulnerability; a silent-failure mode worth stating. See [snapshot
runtimes](../extension-points/snapshot-runtime.md#declaring-one-you-do-not-have).

**Applied state is what you sent, not what exists.** A plan cannot see an object
something else deleted. See [plan and diff](../core-concepts/plan-and-diff.md#what-a-plan-does-not-tell-you).

## Supply chain

Two runtime dependencies: `php` and `symfony/yaml`. That is the whole graph, and
`ArchitectureTest` asserts it, so a third one cannot arrive unnoticed.

CI runs `composer audit --no-dev`, a permissive-license gate, and regenerates a
deterministic CycloneDX SBOM. A release fails if its dependency **set** does not
match the committed `sbom.json`.

Three deliberate limits, so none of them reads as an oversight:

- **The gate compares the set, not the versions.** This is a library, so
  `composer.lock` is not committed — CI resolves fresh, which is what catches an
  upstream break the day it ships rather than the day somebody updates. The
  consequence is that CI picks up any patch published since the generator was
  last run locally, so an exact-version gate would be unwinnable, and a gate that
  cannot be satisfied is one people stop reading. **The exact versions a release
  resolved are in the `sbom-<tag>` artifact attached to that release's CI run**,
  which is the authoritative record precisely because the committed file cannot
  be one.
- **`--no-dev`.** Audit and licence checks cover what a consumer installs. A
  vulnerable or copyleft test runner is our problem to manage, not something that
  reaches anybody's cluster, and failing a release over it would train people to
  bypass the gate.
- **No bins are shipped.** `bin/check-licenses.php` and `bin/generate-sbom.php`
  are this repo's own tooling. They were briefly declared as Composer `bin`
  entries, which installed them into consumers' `vendor/bin` pointing at a
  package root with no lockfile — they failed loudly rather than silently, but
  they were a tool that could not work. Removed in 0.2.1.
