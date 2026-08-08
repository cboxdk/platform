---
title: Custom resources
weight: 42
description: Deploying objects the platform does not model — what the consumer decides, and what it cannot.
---

# Custom resources

A `SealedSecret`, a Crossplane claim, a `NetworkPolicy`, some operator's CR. The
platform has no opinion about what it means — it carries it, labels it, plans it,
and prunes it with the service it belongs to.

```php
new ServiceSpec(
    // …
    customResources: [
        new CustomResource(
            apiVersion: 'bitnami.com/v1alpha1',
            kind: 'SealedSecret',
            name: 'db-password',
            body: ['spec' => ['encryptedData' => ['password' => 'AgB…']]],
        ),
    ],
);
```

## Why carry it, rather than let somebody `kubectl apply` it

So that it **participates**. An object applied by hand is invisible to plan/diff,
is never pruned when the service goes, and is refused by the tenant's admission
policy the moment it collides with something managed. Carried here it is part of
the same desired set as everything else — it shows up in a plan, and it is
removed when the service is.

## The policy is the consumer's

A hosted multi-tenant control plane has to be strict about what lands in a shared
cluster. A developer running kind on their own laptop owns the whole thing and
should not have to ask a library for permission. Baking either answer in would
make this one product's package.

```php
CustomResourcePolicy::forbidden();                        // the default
CustomResourcePolicy::allowingGroups(['bitnami.com']);    // trust one operator
CustomResourcePolicy::unrestricted();                     // a laptop, a sandbox
```

**Deny by default**, because a library that shipped an open door would put every
consumer one forgotten setting away from arbitrary objects in a shared cluster.

**By group rather than by kind**, because a group is the unit an operator is
installed as. Allowing `bitnami.com` is a decision about trusting sealed secrets;
listing kinds one at a time drifts the moment that operator ships another.

## What the policy cannot grant

Three things are taken from the resource whatever the policy says, because they
are what make it a *managed object* rather than one that merely looks like it:

| | |
|---|---|
| **Namespace** | always the environment's. A resource that could name its own namespace is a tenancy escape wearing a feature's clothes |
| **Platform labels** | always the platform's. An object carrying a forged `managed` label would impersonate a platform object, and the tenant's admission policy keys on exactly that label to decide who may write |
| **Name** | one already used by a compiled object is refused, rather than one of them silently overwriting the other — and which one wins would depend on emission order |

Everything else — the `spec`, the annotations, its own labels — is untouched.

## And the apply layer checks anyway

None of the above helps if the thing holding cluster credentials writes whatever
it is handed. In Cbox Cortex the bridge carries a scope with every apply and
refuses objects outside it, including anything cluster-scoped, before they reach
the cluster. Two guards facing opposite directions: the in-cluster admission
policy stops the *customer* touching platform objects, and the bridge stops the
*platform* touching what it should not. Neither substitutes for the other, and a
consumer of this package should have both.
