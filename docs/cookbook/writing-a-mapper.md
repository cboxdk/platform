---
title: Writing a mapper
weight: 32
description: The adapter between your own storage and platform intent — where every lookup, default and config read belongs.
---

# Writing a mapper

A mapper is the one place your storage meets this package. Everything the
compiler is not allowed to do — query, look up, read configuration, apply a
default — happens here.

```
your rows / files / CLI args
            ↓
        your mapper          ← every lookup lives here
            ↓
    Cbox\Platform specs
            ↓
        compiler             ← pure
```

```php
class ServiceSpecMapper
{
    public function forService(Service $service, string $cluster): ServiceSpec
    {
        $service->loadMissing('environment', 'bindings', 'processes');

        return new ServiceSpec(
            serviceId: $service->id,
            organizationId: $service->organization_id,
            namespace: $service->environment->namespaceName(),
            name: $service->name,
            image: $service->image,
            port: $service->port,
            replicas: $service->replicas,
            env: $service->env ?? [],
            envSecret: $service->env_secret ?? [],
            bindings: $this->bindings($service),
            registry: $this->registryFor($service->organization_id),
            domains: $this->domains($service),
            scaleToZero: $service->scale_to_zero,
            suspended: $service->desired_state->isSuspended(),
            podCidr: $this->podCidrFor($service, $cluster),
        );
    }
}
```

## Four things worth getting right

**Resolve everything, eagerly.** If the compiler could reach a relation through
a spec, it would — and the first lazy load in a deploy path is a silent extra
query in production and an outright failure anywhere lazy loading is prevented.
Load what you need, build the arrays yourself, hand over values.

**Order anything that reaches a list.** Domains, volumes, processes — sort them.
A list that comes back in a different row order compiles to different bytes,
which shows as a change in the plan for a deploy that changed nothing. That
undermines the one property the plan/diff DX rests on.

**Take context as an argument, not from configuration.** A fact about *which
cluster* a service is being deployed to belongs in the call, not in a config
lookup inside the mapper. Reading it from a global default means compiling for a
different cluster than the one being deployed to — and the failure is quiet: the
wrong pod CIDR means either refusing the gateway's forwarded address or
accepting one nobody verified.

**Absent is a real answer.** A field nobody set should compile to nothing, not
to a plausible-looking default. `RuntimeSettings` is built this way throughout:
an unset field emits no environment variable at all, so the image's own default
applies and a value the customer set by hand survives. That is what makes it
safe to add settings to services that already exist.

## Testing it

Assert on the **spec**, not on manifests. A mapper test that asserts on YAML is
a second, worse copy of the package's golden tests, and it will break for
reasons that have nothing to do with your mapper.

```php
expect($this->mapper->forService($model, 'eu1')->podCidr)->toBe('10.42.0.0/16');
```

The interesting cases are the ones the compiler cannot see: a relation that was
not loaded, a list that came back unordered, a null that should have been a
refusal.

## Building the target

Your target is a mapper too — of configuration rather than of rows. See [the
platform target](../core-concepts/platform-target.md#building-one-from-configuration).
