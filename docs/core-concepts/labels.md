---
title: Labels on compiled objects
weight: 25
description: What every emitted object is labelled with, why there are two vendor prefixes, and which keys are frozen.
---

# Labels on compiled objects

Every object the compiler emits carries a standard set, built in one place
(`Cbox\Platform\Manifest\Labels`) rather than by each compiler separately —
which is how they drifted before: one emitted `app.kubernetes.io/component`, four
did not, and none emitted `instance`, `version` or `part-of`.

## The set

| Label | Value | On |
|---|---|---|
| `app.kubernetes.io/name` | the service or database name | everything |
| `app.kubernetes.io/instance` | the resource id — unique per instance | everything |
| `app.kubernetes.io/component` | `web`, the process name, `backup`, `gateway` | where it has a meaning |
| `app.kubernetes.io/version` | the image tag | **the workload only** |
| `app.kubernetes.io/part-of` | the project, from `ServiceSpec::$partOf` | everything, when set |
| `app.kubernetes.io/managed-by` | `cbox-platform`, the field manager | everything |
| `<vendor>/managed` | `true` | everything |
| `<vendor>/organization`, `/service`, `/database`, … | the identity | everything |

**`version` is on the workload and its pods, not on every object.** It is derived
from the image tag, so putting it everywhere would mark the Service, the
HTTPRoute and the Namespace as changed every time a tag moves — a plan full of
objects the deploy did not meaningfully touch. A Service does not have a version;
the thing running the image does.

Anything absent or invalid is **omitted rather than emitted empty**. A label
Kubernetes refuses fails the whole apply, and one carrying a placeholder is a
fact nobody stated — `version: unknown` reads as a version. A digest-pinned image
therefore gets no version label: `sha256:…` is 71 characters with a colon in it,
which is not a legal label value and is not a version either.

## The vendor prefix must be a domain you own

A label prefix is a DNS subdomain, and the convention exists so two vendors
cannot collide inside one object. The default is `platform.cbox.dk` — a domain
Cbox controls, namespaced to this package so `id.cbox.dk/` and friends stay free.

It was `cortex.io`, which **Cbox does not own and a real company does**. That is
a bug even while nothing breaks, and it is why the prefix is now a value on
`PlatformIdentity` rather than a literal in five compilers:

```php
new PlatformTarget(identity: new PlatformIdentity(
    labelPrefix: 'platform.example.com',
    fieldManager: 'example-sync',
    resourcePrefix: 'ex',            // ex-gateway, ex-acme, ex:cluster-reader
));
```

Nothing in the compiled output spells a product name any more. A second consumer
names itself and gets objects that are unmistakably its own.

## What is frozen

Not frozen in the package — nothing is released — but frozen **per installation
once it has applied anything**, and both for reasons that bite quietly:

**The field manager.** Server-side apply records field ownership under it, so
changing it on a cluster that already has objects orphans every field the
platform owns: the old manager keeps them, and the new one cannot take them back
without a forced conflict. Choose it once and leave it.

**Selector labels.** `spec.selector` on a Deployment is immutable. The service
and process labels are selectors, so changing the prefix after objects exist does
not rename anything — it refuses to update every workload that already exists.
The objects have to be recreated.

**And what selects on them, wherever that lives.** This is the one the two
warnings above do not cover, because it is outside the package entirely. The
prefix move from `cortex.io` to `platform.cbox.dk` was made with nothing
released and still cost a live cluster four separate failures, none of which any
test in either codebase could see:

- a running service reported as having no workloads, by a consumer whose status
  query still selected the old prefix;
- process control — restart, scale, exec — matching zero pods, the same way;
- a namespace lookup answering "not found" for a namespace directly in front of
  it;
- an admission policy naming the old label refusing **every** write on the
  cluster, not just the objects it was about.

Each component was correct about its own half. If the thing that selects is in
another repository, nothing in either build can see the drift — so the test to
write is one that pins the package's value and the consumer's selectors to the
same string, in whichever of them can read both.

## Migration cost

Adding labels to a pod template changes the template, so **the first deploy of
each service after upgrading performs one rolling restart** it would not
otherwise have done. Rolling, not downtime — except for a service pinned to one
replica by a volume, which uses `Recreate`. Nothing restarts spontaneously.
