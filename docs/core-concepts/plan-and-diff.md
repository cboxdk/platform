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

## Saying what changed, not merely that it did

A hash comparison answers "did this object change", which is enough to decide
whether to apply — and not enough for a person to approve. For that you need the
previous body:

```php
$plan = new HashPlanner()->planAgainst($desired, $appliedHashes, $appliedBodies);

foreach ($plan->entries as $entry) {
    echo $entry->action->value.' '.$entry->key.PHP_EOL;

    foreach ($entry->changes as $change) {
        echo '  ~ '.$change->path.' '.$change->before.' → '.$change->after.PHP_EOL;
    }
}
```

```
update Deployment/web
  ~ spec.replicas 1 → 3
  ~ spec.template.spec.containers [1 items] → [2 items]
```

**Three inputs, not two.** `$appliedHashes` is authoritative and complete: it
decides every action, exactly as `plan()` would. `$appliedBodies` is best-effort
and will always hold fewer objects — so an object with no retained body simply
gets no detail, rather than a diff against an empty one that would report every
field it has as newly added.

A **separate call, not a wider contract**, and that is the point: the detail
costs the previous bodies, which a consumer must choose to retain. Hashes alone
are a few hundred bytes per object; a vendored addon bundle runs to megabytes
and nobody reads a field diff of it. `Planner::plan()` is unchanged, so a
consumer that stores only hashes loses nothing.

### Never retain a Secret, and never print one

**A plan is rendered into a browser, returned by an API and written to logs.** A
field diff of a compiled `Secret` would publish the customer's env values,
registry password and database credentials to all three — and retaining that
body to produce the diff would first write them, in the clear, into whatever
column the consumer keeps applied state in.

Two rules, and you need both:

- **The planner redacts.** `planAgainst()` renders every value in a `Secret` as
  `•••` whatever a caller passes it. The path survives, because a key name is
  not secret and `~ stringData.APP_KEY ••• → •••` is exactly what a plan should
  say: this rotated.
- **The consumer must not retain a Secret's body at all.** Redaction protects
  the plan; it does not protect your database. Filter by `kind === 'Secret'`
  when you store — the hash alone is enough to know a secret changed, and what
  it changed to is not a thing applied state may hold.

`Secret` is the only kind this package emits that carries a credential; a
`CredentialBoundaryTest` in the suite asserts it, so filtering by kind is
sufficient rather than a heuristic.

`ManifestSet::toArray()` / `::fromArray()` are the round trip for retaining a
set, and a rehydrated set hashes identically to the one it came from — reloading
must never itself look like a change.

Nested maps are walked, so you get `spec.replicas` rather than `spec`. Lists are
**not**: a container list that gained an element would report every subsequent
index as changed, which describes a shift rather than an edit. A list reports
its shape (`[1 items] → [2 items]`) and the YAML is there for the rest.

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
