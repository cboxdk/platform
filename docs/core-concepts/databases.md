---
title: Databases and the node that dies
weight: 26
description: Why Postgres and the other engines compile down different paths, what a StatefulSet does when a node fails, and what has to exist before an engine can have replicas.
---

# Databases and the node that dies

Two paths, and the split is not about which engine anybody prefers.

| engine | compiles to | replicas |
|---|---|---|
| Postgres | a CloudNativePG `Cluster` | yes — the operator replicates |
| Valkey, Percona | a `StatefulSet` this package writes | **one, refused above that** |

## What a StatefulSet does when a node dies

Not gracefully drained — **dies**. The kubelet stops reporting, the node goes
`NotReady`, and the pod on it enters `Terminating` and stays there.

Kubernetes will not reschedule it, and that is deliberate. A StatefulSet promises
**at most one** pod per ordinal, and from the outside a silent node is
indistinguishable from a partitioned one that is still running and still writing
to its volume. Starting a second copy against the same data is how a database is
corrupted, so Kubernetes chooses stuck over corrupt.

The database is therefore down until somebody force-deletes the pod and the
volume detaches. On a laptop that is an annoyance. On a cluster at three in the
morning it is an outage waiting for a human who has to know that trick.

## What the good operators do instead

**Each member is its own object, and something owns the topology.**

That is not a theory. CloudNativePG's own permissions say it: its ClusterRole
grants `pods`, `pods/exec` and `persistentvolumeclaims` — and **no
`statefulsets` at all**. It creates and deletes each PostgreSQL instance itself.

Which is what makes recovery possible. When a member is lost the operator does
not wait for its volume to come back; it provisions a **new** member elsewhere
and re-synchronises it from the primary. There is no at-most-one puzzle to solve,
because the operator knows which instance is the primary and can say so.

So the answer to "a StatefulSet is bad when a node dies" is not to use
StatefulSets more carefully. It is that a replicated database needs something
that understands replication — and once you have that, StatefulSets are not what
it reaches for.

## Why the other engines are capped at one

Because this package schedules them itself, and it does not understand their
replication — neither Valkey nor MySQL replicates by being started more than
once.

Passing `instances: 3` through to a replica count produced three independent
servers, each with its own volume, behind one Service that load-balanced across
them. A write landed on whichever pod it reached. Three databases each believing
they were the database, diverging from the first write, with every status
reporting healthy.

It is **refused rather than clamped to one**. Clamping hands somebody who asked
for three a working database and a false belief about it, and the belief is the
more dangerous half: they would plan a failover that cannot happen.

## What has to exist before that cap can lift

An operator for the engine, doing for it what CloudNativePG does for Postgres:
elect a primary, stream to the others, and replace a lost member rather than
waiting for it. Percona and Vitess both ship one for MySQL; Valkey has options.

Adopting one is a smaller and far more likely-to-be-correct piece of work than
writing per-member orchestration here — failover, resynchronisation and split
brain are where that work actually lives, and none of it is where this package's
value is.

Until then a single instance is what these engines honestly offer, and a single
instance is also all a development machine has room for.
