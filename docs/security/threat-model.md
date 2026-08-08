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

Two runtime dependencies (`php`, `symfony/yaml`). CI runs `composer audit
--no-dev`, a permissive-license gate over the whole lock, and regenerates a
deterministic CycloneDX SBOM — a release fails if its dependency set does not
match the committed one.
