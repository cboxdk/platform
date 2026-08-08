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

## What is not an extension point

**The managed label and field manager.** `cortex.io/managed` and `cortex-sync`
are the identity every applied object carries and the key an admission policy
matches on. They are constants, not configuration, and changing them orphans
every object already in a cluster.

**The hash function.** `Manifest::hash()` is what applied state is keyed on
across every consumer. Changing it marks every live object as changed.
