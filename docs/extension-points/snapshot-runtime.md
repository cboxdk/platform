---
title: Snapshot runtimes
weight: 41
description: The contract behind the warm tier of scale-to-zero, and how to supply your own without leaking the mechanism into the product.
---

# Snapshot runtimes

A snapshot runtime checkpoints an idle workload and restores it on the next
connection. It is the *warm* tier of scale-to-zero; without one, a wake is a
real pod start.

```php
interface SnapshotRuntime
{
    public function isAvailable(): bool;
    public function runtimeClassName(): ?string;
    public function annotations(string $containerName, int $port, int $idleTimeoutSeconds): array;
    public function environment(): array;
}
```

Four methods because the runtimes genuinely differ in shape: one wants a
`RuntimeClass` and annotations, another cooperates with the container's own init
process through its environment. An implementation returns nothing for the parts
it does not use.

## Shipped

| | `isAvailable()` | `runtimeClassName()` |
|---|---|---|
| `NoSnapshotRuntime` | `false` — the cold-start tier | `null` |
| `ZeropodSnapshotRuntime` | `true` | the configured class |
| `CortexSnapshotRuntime` | `true` | `null` — cooperates with the container's init process |

These describe two specific runtimes and will change with them; the contract is
the stable part.

## Why it is a capability and not a product feature

The customer-facing primitive is `running` / `suspended` / `resuming`. Whether a
resume is a checkpoint restore in ~100 ms or a cold pod start in a few seconds
is the cluster's business — it changes the latency, not the meaning.

Keeping the mechanism out of the product model is what lets a cluster with no
snapshot runtime fall back to a cold start without the product having to
describe two different features. It is also what lets a new mechanism be
developed and tested locally against the same intent that runs in production.

## Declaring one you do not have

The most expensive mistake available here, and it is silent.

The two tiers are mutually exclusive: with a snapshot runtime available, the
compiler keeps the replica count and annotates the pod, and emits **no**
`HTTPScaledObject`. So a target that claims a runtime its nodes do not actually
have does not merely skip the warm wake — it switches off the cold-start tier
that would have worked. The workload runs one replica for ever, nothing errors,
and the customer is billed for a service that was meant to sleep.

The asymmetry decides the default: wrong towards "none" is a cold start that
could have been warm. Wrong towards "available" is a feature that is off and
reports itself on. `NoSnapshotRuntime` is the default for that reason, and a
consumer should verify the claim before overriding it.
