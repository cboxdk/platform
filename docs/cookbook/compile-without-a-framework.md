---
title: Compile without a framework
weight: 31
description: A complete script — intent to manifests to a plan — with no application boot, no container, no ORM and no network.
---

# Compile without a framework

This is the whole package in one file. No application boot, no service
container, no ORM, no configuration, no network.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Plan\HashPlanner;
use Cbox\Platform\Service\ServiceSpec;

$target = new PlatformTarget;

$spec = new ServiceSpec(
    serviceId: 'svc-checkout',
    organizationId: 'acme',
    namespace: 'cx-feature-checkout',
    name: 'web',
    image: 'ghcr.io/acme/checkout:2.1.0',
    port: 8080,
    replicas: 2,
    env: ['APP_ENV' => 'production'],
    envSecret: ['APP_KEY' => getenv('APP_KEY') ?: ''],
    domains: ['checkout.acme.example'],
);

$compiled = new ServiceCompiler($target)->compile($spec);

file_put_contents('manifests.yaml', $compiled->toYaml());
file_put_contents('applied.json', json_encode($compiled->hashes()));
```

Apply it however you like:

```bash
kubectl apply -f manifests.yaml
```

Next time, plan before you apply:

```php
$applied = json_decode(file_get_contents('applied.json'), true);
$plan    = new HashPlanner()->plan($compiled, $applied);

if (! $plan->hasChanges()) {
    exit("nothing to do\n");
}

foreach ($plan->entries as $entry) {
    echo match ($entry->action->value) {
        'create'    => '+ ',
        'update'    => '~ ',
        'delete'    => '- ',
        'unchanged' => '  ',
    }, $entry->key, PHP_EOL;
}
```

```
+ Namespace/cx-feature-checkout
~ Deployment/web
  Service/web
- HTTPRoute/web
```

## Why this file matters

The package's own suite contains a version of it — `tests/PortabilityTest.php` —
and it is not a demo. It asserts that intent compiles to valid, deterministic
Kubernetes objects with no framework anywhere, which is the claim the whole
extraction rests on. If that test ever needs a container or a database to pass,
the boundary has moved and the package has quietly become one product's
internals with a second name.
