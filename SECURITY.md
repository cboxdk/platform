# Security Policy

`cboxdk/platform` compiles application intent into Kubernetes objects. It performs
no IO of its own, but what it emits — RBAC, Secrets, network exposure, pod security
context — is applied to real clusters by its consumers, so a flaw here becomes a
misconfiguration there.

## Reporting a vulnerability

**Do not open a public issue.** Report privately via
[GitHub Private Vulnerability Reporting](https://github.com/cboxdk/platform/security/advisories/new)
(repository → **Security** → **Report a vulnerability**). This is a best-effort
open-source project with no funded security team and no response-time guarantee;
we'll respond as promptly as we can and coordinate disclosure with you. Good-faith
research under this policy is authorized (safe harbor).

The reports we most want to see:

- **Secret leakage** — a value passed as `envSecret` that ends up readable from the
  cluster (inlined in a pod spec, a ConfigMap, a label or an annotation) instead of
  compiled into a `Secret`.
- **Privilege escalation in emitted objects** — a spec that produces a container with
  more capability than the intent asked for, or an RBAC rule wider than the namespace
  it was meant for.
- **Cross-tenant reachability** — a spec for one namespace that compiles a reference
  reaching another.
- **Non-determinism** — the same spec compiling to different bytes. That is the
  property plan/diff and drift detection rest on; losing it is a correctness bug with
  security consequences.

## Scope

In scope: everything under `src/`.

Out of scope: what a consumer does with the output. This package does not apply
manifests, hold credentials, authenticate, or talk to a cluster — the apply path,
admission policy, and credential handling belong to the consumer (Cbox Cortex, Cbox
Local, or your own). A finding that requires the consumer to have already applied
attacker-controlled intent is a finding about the consumer.

## Supported versions

The package is pre-1.0. Security fixes target the **latest `0.x` release only**;
older minors are not backported.
