---
title: Extension points
weight: 40
description: The contracts a consumer implements or swaps, and how to extend compilation without forking it.
---

# Extension points

Everything the package lets you change goes through a contract in
`Cbox\Platform\Contracts`. Depend on those, not on the concrete classes — see
[public API](../core-concepts/public-api.md).

| Contract | Swap it when |
|---|---|
| `CustomResourcePolicy` | your consumer should let customers deploy objects the platform does not model — see [custom resources](custom-resources.md) |
| `SnapshotRuntime` | your nodes checkpoint idle workloads differently — see [snapshot runtimes](snapshot-runtime.md) |
| `Compiler` | you need to wrap or post-process what a service compiles to |
| `DatabaseCompiler` | you support an engine this package does not |
| `BackupCompiler`, `GatewayCompiler`, `RunCompiler` | same, for those resources |
| `Planner` | you plan against live cluster state rather than recorded applied state |

## Wrapping rather than replacing

Usually you do not want a different compiler — you want the same one plus
something. Decorate it:

```php
class LabelledCompiler implements Compiler
{
    public function __construct(
        private readonly Compiler $inner,
        private readonly string $team,
    ) {}

    public function compile(ServiceSpec $spec): ManifestSet
    {
        $compiled = $this->inner->compile($spec);

        return new ManifestSet(array_map(
            fn (Manifest $m): Manifest => $this->withTeamLabel($m),
            $compiled->manifests,
        ));
    }
}
```

Keep the decorator pure too. It is inside the same guarantee: a decorator that
reads a database gives the whole compile path a side effect, and the golden and
determinism tests downstream of it stop meaning anything.

## An extension point with a cost

**The managed label and field manager.** `PlatformIdentity` owns both — the
label prefix (`platform.cbox.dk` by default) and the field manager
(`cbox-platform`). Set them once, before a consumer has applied anything, and
they cost nothing. They are on `PlatformIdentity` rather than fixed in the
compilers precisely so a consumer can own its objects under a domain it
controls.

Changing them **after** objects are live is a different act. They are the
identity every applied object carries, the key an admission policy matches on,
and what anything selecting those objects looks for. Moving the prefix means
moving all of it in one change:

- whatever selects on the label to find workloads — a selector left behind
  reports a running service as having no workloads at all;
- any admission policy naming it — one left behind refuses every write, which
  stops a whole cluster rather than one object;
- objects already applied, whose `spec.selector` may be immutable. A Deployment
  is: it must be deleted and recreated, not patched.

None of that is detectable from inside this package, because the things that
select are in the consumer. If you move a prefix, the test to write is one that
pins the package's value and the consumer's selectors to the same string.

## What is not an extension point

**The hash function.** `Manifest::hash()` is what applied state is keyed on
across every consumer. Changing it marks every live object as changed.
