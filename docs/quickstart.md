---
title: Quickstart
weight: 2
description: From composer require to compiled Kubernetes manifests and a plan, in one read.
---

# Quickstart

```bash
composer require cboxdk/platform
```

## Compile a service

```php
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Service\ServiceSpec;

$spec = new ServiceSpec(
    serviceId: 'svc-checkout',
    organizationId: 'acme',
    namespace: 'cx-feature-checkout',
    name: 'web',
    image: 'ghcr.io/acme/checkout:2.1.0',
    port: 8080,
    replicas: 2,
    env: ['APP_ENV' => 'production'],
    envSecret: ['APP_KEY' => 'base64:…'],
    domains: ['checkout.acme.example'],
);

$compiled = new ServiceCompiler(new PlatformTarget)->compile($spec);

echo $compiled->toYaml();
```

You get a `Namespace`, a `Secret` (the value from `envSecret` is never inlined
in the pod spec), a `Deployment`, a `Service`, an `HTTPRoute`, and a
`PodDisruptionBudget` — ordered so that a dependency is always emitted before
what needs it.

## Plan against what you last applied

```php
use Cbox\Platform\Plan\HashPlanner;

$plan = new HashPlanner()->plan($compiled, $appliedHashes);

$plan->hasChanges();  // false = a deploy would genuinely do nothing
$plan->summary();     // ['create' => …, 'update' => …, 'delete' => …, 'unchanged' => …]

foreach ($plan->entries as $entry) {
    echo $entry->action->value.' '.$entry->key.PHP_EOL;
}
```

`$appliedHashes` is `array<string, string>` — exactly what `$compiled->hashes()`
returned the last time you applied. Persist it however you like; the package
neither stores nor reads it.

## Apply it

That part is yours. `toYaml()` gives you a multi-document stream for `kubectl
apply -f -`; `$compiled->manifests` gives you each object as a `Manifest` with
its `apiVersion`, `kind`, `name`, `namespace` and `body` array if you are
driving a client library.

Two labels are worth knowing about, because they are how a consumer tells its
own objects apart from a customer's: everything carries
`platform.cbox.dk/managed: "true"` and
`app.kubernetes.io/managed-by: cbox-platform`.

Both halves come from `PlatformIdentity` and both can be changed — see
[labels](core-concepts/labels.md) for what that costs once objects are live.

## Add a database

```php
use Cbox\Platform\Compile\BackupCompiler;
use Cbox\Platform\Compile\CnpgDatabaseCompiler;
use Cbox\Platform\Compile\EngineDatabaseCompiler;
use Cbox\Platform\Compile\StatefulDatabaseCompiler;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;

$target  = new PlatformTarget;
$backups = new BackupCompiler($target);

$databases = new EngineDatabaseCompiler(
    new CnpgDatabaseCompiler($target, $backups),
    new StatefulDatabaseCompiler($target, $backups),
);

$databases->compile(new DatabaseSpec(
    databaseId: 'db-orders',
    organizationId: 'acme',
    namespace: 'cx-feature-checkout',
    name: 'orders',
    engine: DatabaseEngine::Postgres,
    version: '16',
    instances: 1,
    storageSize: '10Gi',
));
```

Postgres compiles to a CloudNativePG `Cluster`; Valkey and Percona compile to a
StatefulSet the platform schedules itself. An engine with no registered compiler
is refused rather than compiled as something else.

## Next

- [Architecture](core-concepts/architecture.md) — why the compiler is pure and
  what that buys.
- [The platform target](core-concepts/platform-target.md) — declaring what your
  cluster can do.
- [Writing your own mapper](cookbook/writing-a-mapper.md) — turning your storage
  into platform intent.
