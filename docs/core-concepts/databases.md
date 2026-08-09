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

## And ordinals are not roles

The stuck pod is the loudest failure. The quieter one is that a StatefulSet's
guarantees are about **ordinals** — `db-0`, `db-1`, `db-2` — while a replicated
database's reality is about **roles**, and which member holds which role changes.
A failover makes a replica the primary; the ordinals do not move.

Nothing reconciles the two, and the rolling update is where it shows. A
StatefulSet updates in **reverse ordinal order**, always. Measured on a local
cluster, on a set with `podManagementPolicy: Parallel` — which sounds like it
should remove the ordering, and does not, because it governs creation and
deletion rather than updates:

```
db-0=Running  db-1=Running  db-2=Terminating
```

`db-2` first, every time, whether or not `db-2` is the primary.

The order a replicated database actually wants is: **replicas first, then fail
over, then the old primary** — one controlled switch, at a moment somebody chose.
A StatefulSet cannot express that, because it does not know which member is
serving writes. What it does instead is restart members in an order picked from
their names, which on a three-member cluster can mean restarting the primary in
the middle of the sequence and taking an unplanned failover with it.

And with the default `podManagementPolicy: OrderedReady` the two failures
compound: one member stuck on a dead node blocks the update of every other
member, so a cluster that is degraded cannot even be upgraded out of it.

## What the good operators do instead

**Each member is its own object, and something owns the topology.**

That is not a theory. CloudNativePG's own permissions say it: its ClusterRole
grants `pods`, `pods/exec` and `persistentvolumeclaims` — and **no
`statefulsets` at all**. It creates and deletes each PostgreSQL instance itself.

Which is what makes recovery possible. When a member is lost the operator does
not wait for its volume to come back; it provisions a **new** member elsewhere
and re-synchronises it from the primary. There is no at-most-one puzzle to solve,
because the operator knows which instance is the primary and can say so.

It is also what makes an upgrade possible in the right order. Knowing the roles,
it updates the replicas, promotes one, and only then touches the old primary —
one switch, deliberately, instead of an order read off the pods' names.

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
