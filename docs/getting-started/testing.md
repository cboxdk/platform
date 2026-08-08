---
title: Testing
weight: 12
description: The shipped CompilesPlatformIntent trait, golden files, and asserting determinism in your own suite.
---

# Testing

## The shipped trait

`Cbox\Platform\Testing\CompilesPlatformIntent` wires the compilers for you. The
package's own suite uses it — including its golden tests — which is what keeps
the portability claim honest: if compiling needed a framework, this trait could
not exist.

```php
use Cbox\Platform\Testing\CompilesPlatformIntent;
use PHPUnit\Framework\TestCase;

class DeployTest extends TestCase
{
    use CompilesPlatformIntent;

    public function test_it_routes_the_hostname(): void
    {
        $set = $this->compileService($spec);

        // …
    }
}
```

| Method | Does |
|---|---|
| `compilingFor(PlatformTarget)` | compile everything after this against that target |
| `compilingWithSnapshotRuntime(?SnapshotRuntime)` | shorthand for the snapshot tier; `null` is the cold-start tier |
| `target()` | the current target |
| `compileService(ServiceSpec)` | |
| `compileDatabase(DatabaseSpec)` | routes by engine |
| `compileBackup(BackupSpec)` | |
| `compileGateway(EnvironmentGatewaySpec)` | |
| `compileRun(RunSpec)` | |

## Fakes, for testing the layer above the compiler

Your apply path, deploy orchestration and status handling all take a
`ManifestSet` and should be testable without asserting on real Kubernetes
objects — those already have golden tests here, and a second copy of them in
your suite breaks for reasons that have nothing to do with your code.

| | |
|---|---|
| `FakeCompiler` | records every `ServiceSpec` it was given (`->compiled`, `->lastSpec()`), returns a placeholder set or `->returning($yours)` |
| `FakePlanner` | records, and falls through to real arithmetic unless told to lie; `FakePlanner::unchanged()` reaches the no-op branch |
| `FakeSnapshotRuntime` | points either way, so both scale-to-zero tiers are reachable; `::unavailable()` is the cold-start one |
| `SpecFactory` | minimal valid intent — `service()`, `database()`, `binding()`, `gateway()` — with overrides for the one field a test is about |

```php
$compiler = new FakeCompiler;

$this->deployService($model);          // your code

expect($compiler->lastSpec()->replicas)->toBe(3);
```

`SpecFactory` is deliberately **not** random. A fixture that varies between runs
makes a golden file impossible and a flake inevitable.

## Golden files

The package records its compiled output byte for byte under `tests/Golden`. A
golden test is three lines and catches what an assertion about one field never
will — a label that quietly moved, an object that stopped being emitted, a key
order that changed and would show every live object as modified:

```php
it('matches the golden manifest set byte for byte', function (): void {
    $yaml   = test()->compileService(fixtureSpec())->toYaml();
    $golden = test()->golden('service-basic');

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($golden)) {
        file_put_contents($golden, $yaml);
    }

    expect($yaml)->toBe(file_get_contents($golden));
});
```

`UPDATE_GOLDEN=1` regenerates. **Read the diff before you commit it.** A golden
regenerated to make a test pass is a golden that records a bug.

## Asserting determinism

Worth doing in your own suite too, because the property is easy to lose and its
loss is silent until every object in production shows as changed:

```php
it('is deterministic', function (): void {
    $first  = test()->compileService($spec);
    $second = test()->compileService($spec);

    expect($second->toYaml())->toBe($first->toYaml())
        ->and($second->hashes())->toBe($first->hashes());
});
```

Anything that breaks this — a timestamp, a random suffix, an unordered map, a
value read from configuration at compile time — is a bug in the compiler, not in
the test.

## Testing your mapper

The package's tests cover the compiler. What they cannot cover is *your* mapper:
that the rows in your database produce the intent you meant. Keep those tests on
your side, and assert on the **spec**, not on the manifests — a mapper test that
asserts on YAML is really a second, worse copy of the golden tests.

```php
expect($this->mapper->forService($model)->replicas)->toBe(3);
```
