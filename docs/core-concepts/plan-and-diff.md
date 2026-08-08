---
title: Plan and diff
weight: 23
description: Why "did anything change" is a hash comparison rather than a cluster read, and what that does not tell you.
---

# Plan and diff

```php
$desired = $compiler->compile($spec);
$plan    = new HashPlanner()->plan($desired, $appliedHashes);
```

`$appliedHashes` is `array<string, string>` — object key to content hash —
exactly what `$desired->hashes()` returned the last time you applied. The
package neither stores nor reads it; where it lives is yours.

## How it works

Because compilation is deterministic and hashes are canonical, the difference
between what you want and what you last applied is arithmetic:

| condition | action |
|---|---|
| key absent from applied | `create` |
| key present, hash differs | `update` |
| key present, hash matches | `unchanged` |
| key applied before, absent now | `delete` |

That last row matters more than it looks. Without it, removing a domain from a
service leaves its `HTTPRoute` in the customer's cluster for ever — the plan
would simply not mention it.

```php
$plan->hasChanges();  // false = a deploy would genuinely do nothing
$plan->summary();     // ['create' => 2, 'update' => 1, 'delete' => 0, 'unchanged' => 4]

foreach ($plan->entries as $entry) {
    echo $entry->action->value.' '.$entry->key.PHP_EOL;
}
```

`Plan` is pure data. Rendering it — the `+ Service web` / `~ replicas 1 → 3`
output a user reads — is the consumer's, deliberately: a shared plan coupled to
one product's HTTP resources could not be shown by the other.

## What a plan does not tell you

**Applied state is what you last sent, never what is in the cluster.** Nothing
here reads a cluster, so an object something else deleted produces no plan and
no action, for ever.

This is not hypothetical. A finalizer once removed an object moments after it
was created, and every subsequent deploy reported `unchanged` for an object that
was not there — autoscaling was off and the platform said it was applied.

If your product needs to survive that, give it a **converge** mode that re-sends
the desired set even when the plan is empty, and keep it separate from a normal
deploy. "A deploy that changes nothing is a no-op" is a property worth keeping;
it is what makes a release mean something.

## Drift

Comparing against what is actually in the cluster is a different operation with
a different input: fetch the live objects, hash them the same way, and compare.
The hashing is here; the fetching is yours, because it needs a cluster
connection this package does not have and will not grow.
