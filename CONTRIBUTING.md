# Contributing

Thanks for looking. This package is small on purpose, and the rules below are what
keep it that way.

## The two invariants

**1. The compiler is pure.** `compile()` must not read a database, a cluster, a
config file, the filesystem, the clock, the environment, or the network, and must
not dispatch anything. Everything it needs arrives in the spec and the
`PlatformTarget`. A pull request that adds IO to a compile path will be declined
regardless of how convenient it is — determinism is the property plan/diff, golden
tests and drift detection all rest on.

**2. The package has no consumer-specific knowledge.** It does not know what
kube-bridge, kind, Cortex or Local are. It compiles; someone else applies. No
`if ($local)`, no `if ($cortex)` — a difference between targets is a typed value on
`PlatformTarget`, or it is not a difference the compiler is allowed to see.

## Changing compiled output

Golden files under `tests/Golden` are the record of what this package emits. They
are **intentional**: a diff in one is either a bug you just introduced or a change
you meant to make and must explain.

```bash
UPDATE_GOLDEN=1 vendor/bin/pest        # regenerate, then read the diff
```

Never regenerate a golden to make a test pass. Read the diff first; if you cannot
explain every line of it, the change is wrong. A pull request that touches a golden
file must say in its body what changed in the emitted objects and why.

Two things are frozen and are not tidy-up material:

- the managed label `cortex.io/managed` and field manager `cortex-sync`. They are
  the identity every already-applied object in every live cluster carries, and the
  key an admission policy matches on. Renaming them orphans real workloads.
- `Manifest::hash()` and its canonicalisation. Consumers persist those digests as
  applied state; changing the function marks every live object as changed.

## Working on it

```bash
composer install
composer qa        # pint --test, phpstan max, pest, license-check, audit
```

The full gate must be green before a commit. PHPStan runs at **level max** with no
baseline and no `@phpstan-ignore` — fix the cause, not the report.

House style, briefly: PHP 8.4, `declare(strict_types=1)`, `readonly` value objects
with promoted constructor properties, enums for fixed sets, no loose
`array<string, mixed>` in the domain (arrays only at the YAML/JSON edge), and no
`final` on classes — consumers are allowed to extend what we ship.

## Commits

Conventional-commit subjects (`feat(compile): …`, `fix(service): …`) with a body
explaining the reasoning when it is not obvious. One coherent change per commit.

## Reporting security issues

Not here — see [SECURITY.md](SECURITY.md).
