---
title: Installation
weight: 11
description: Installing the package and wiring the compilers, with or without a service container.
---

# Installation

```bash
composer require cboxdk/platform
```

See [requirements](../requirements.md) for the versions the resolver enforces.

## Wiring by hand

There is no service provider, no auto-discovery and no bootstrap step, because
there is nothing to bootstrap. Every compiler is a plain class with explicit
dependencies:

```php
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Compile\BackupCompiler;
use Cbox\Platform\Compile\CnpgDatabaseCompiler;
use Cbox\Platform\Compile\EngineDatabaseCompiler;
use Cbox\Platform\Compile\EnvironmentGatewayCompiler;
use Cbox\Platform\Compile\RunJobCompiler;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Compile\StatefulDatabaseCompiler;

$target = new PlatformTarget;                      // see: the platform target

$services  = new ServiceCompiler($target);
$backups   = new BackupCompiler($target);
$databases = new EngineDatabaseCompiler(
    new CnpgDatabaseCompiler($target, $backups),
    new StatefulDatabaseCompiler($target, $backups),
);
$gateways  = new EnvironmentGatewayCompiler();
$runs      = new RunJobCompiler($services);
```

## Wiring in a Laravel container

The package ships no provider on purpose — it must not assume a framework — but
binding the contracts in your own is three lines:

```php
$this->app->singleton(PlatformTarget::class, fn (): PlatformTarget => new PlatformTarget(
    snapshotRuntime: $this->snapshotRuntimeFromConfig(),
    backups: new BackupCatalog(
        imageRepositories: config('platform.engine_images'),
        keyPrefix: config('platform.backup_prefix'),
    ),
));

$this->app->singleton(Compiler::class, ServiceCompiler::class);
$this->app->singleton(DatabaseCompiler::class, EngineDatabaseCompiler::class);
$this->app->singleton(Planner::class, HashPlanner::class);
```

Depend on the `Cbox\Platform\Contracts\*` interfaces rather than the concrete
classes; see [public API](../core-concepts/public-api.md) for which names are
supported.

## What you still have to build

The package stops at compiled objects. A working product needs, from you:

- a **mapper** turning your storage into specs — see
  [writing a mapper](../cookbook/writing-a-mapper.md);
- an **apply layer** that sends the manifests to a cluster and records the
  hashes it applied;
- whatever **status and reconciliation** your product promises.
