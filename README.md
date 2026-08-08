# Cbox Platform

**A typed application model, a deterministic Kubernetes compiler, and plan/diff
primitives — as a library you embed, not a platform you run.**

[![Latest Version](https://img.shields.io/packagist/v/cboxdk/platform.svg)](https://packagist.org/packages/cboxdk/platform)
[![License](https://img.shields.io/packagist/l/cboxdk/platform.svg)](LICENSE)

```
                    Application intent
                            │
                      Cbox Platform
                            │
                Compiled Kubernetes resources
                            │
              your apply / reconciliation layer
```

This is **not another PaaS** and **not a Kubernetes framework**. It runs no
service, owns no cluster, applies nothing, and has no opinion about how you
deploy. It answers one question — *what does an application resource mean, and
what Kubernetes objects does that intent compile to?* — and answers it the same
way every time.

```php
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Service\ServiceSpec;

$manifests = new ServiceCompiler(new PlatformTarget)->compile(new ServiceSpec(
    serviceId: 'svc-checkout',
    organizationId: 'acme',
    namespace: 'cx-feature-checkout',
    name: 'web',
    image: 'ghcr.io/acme/checkout:2.1.0',
    port: 8080,
    replicas: 2,
    domains: ['checkout.acme.example'],
    scaleToZero: true,
));

echo $manifests->toYaml();   // hand this to kubectl, a client-go bridge, anything
```

No container, no ORM, no configuration file, no network. That is the whole point.

## Why it exists

Two products needed to agree on what a Cbox application *is*:

- **Cbox Cortex** — the hosted platform. Customer intent lives in Postgres; the
  compiled output is applied to production clusters over a Go bridge.
- **Cbox Local** — a local, production-like development platform on kind.

They differ in how intent is **discovered**, **built**, **applied** and
**operated**. They must not differ in what an application *means* or how that
intent compiles to Kubernetes — otherwise "works locally" stops being evidence
of anything. So the middle of the diagram became a package, and both products
consume it.

```
                       cboxdk/platform
                  ┌──────────────────────┐
                  │ typed runtime intent │
                  │ compiler             │
                  │ plan / diff          │
                  └──────────┬───────────┘
                             │
               ┌─────────────┴─────────────┐
               │                           │
          Cbox Local                  Cbox Cortex
      local project mapper       Eloquent intent mapper
       local BuildKit build       hosted build/registry
               │                           │
             kind                     kube-bridge
               │                           │
         Kubernetes                  Kubernetes
```

Cortex and Local are the reference consumers. Nothing about either is required
to use this package.

## Install

```bash
composer require cboxdk/platform
```

Requires PHP 8.4+. The only runtime dependency is `symfony/yaml`.

## What it gives you

**Typed intent, not arrays.** `ServiceSpec`, `DatabaseSpec`, `BackupSpec`,
`RunSpec`, `EnvironmentGatewaySpec` and friends are `readonly` value objects.
Where they come from is your problem and deliberately so — an ORM, a YAML file,
a Git repository, CLI flags, or a test fixture written by hand.

**A compiler that is a pure function.** `compile()` reads no database, no
cluster, no config, no clock, no environment, no network. Everything it needs
arrives in the spec and the target. Given the same input it emits the same
bytes — which is what makes the next two features possible at all.

**Plan/diff without touching a cluster.** Manifests are content-hashed with a
canonical encoding, so "did anything change" is a hash comparison against what
you last applied:

```php
$plan = new HashPlanner()->plan($desired, $appliedHashes);

$plan->summary();     // ['create' => 2, 'update' => 1, 'delete' => 0, 'unchanged' => 4]
$plan->hasChanges();  // false means a deploy would do nothing at all
```

Retain the previous bodies as well and the plan can say *what* changed, not
merely that something did — with secret material redacted, always:

```
update Deployment/web
  ~ spec.replicas 1 → 3
update Secret/web-env
  ~ stringData.APP_KEY ••• → •••
```

**A typed target instead of feature flags.** What a cluster can actually do —
whether its nodes can checkpoint an idle workload, where the HTTP autoscaler's
interceptor lives, which engine images to run, who signs its certificates, what
a customer's own kubectl may do — is one `PlatformTarget` object. There is no
`if ($local)` anywhere in the compiler, and there will not be: a difference
between two clusters is a value, and a value is testable.

**Product semantics, not mechanisms.** Scale-to-zero, suspend/resume,
autoscaling and health are expressed as what the customer asked for. Whether a
wake is a checkpoint restore or a cold pod start is the target's business, not
the application's.

## What it does not do

- **Apply anything.** It emits objects. Applying them — server-side apply, drift
  reconciliation, status — is yours.
- **Build images.** It consumes an image reference. Dockerfiles, BuildKit and
  registries live in the consumer.
- **Provision infrastructure.** No clusters, no node pools, no provider APIs, no
  credentials.
- **Pretend Kubernetes isn't there.** Kubernetes is the shared runtime contract,
  and the abstraction is at the application-resource level. There is no
  `GenericRuntimeCompiler` waiting for a Nomad backend that is not coming.

## Documentation

- [Overview and mental model](docs/index.md)
- [Quickstart](docs/quickstart.md)
- [Architecture](docs/core-concepts/architecture.md)
- [The platform target](docs/core-concepts/platform-target.md)
- [Labels on compiled objects](docs/core-concepts/labels.md)
- [Kubernetes API versions](docs/core-concepts/api-versions.md)
- [Plan and diff](docs/core-concepts/plan-and-diff.md)
- [Public API and what `0.x` means](docs/core-concepts/public-api.md)
- [Compiling without a framework](docs/cookbook/compile-without-a-framework.md)
- [Writing your own mapper](docs/cookbook/writing-a-mapper.md)
- [Testing: the shipped trait and fakes](docs/getting-started/testing.md)
- [Threat model](docs/security/threat-model.md)
- [The open-source boundary](docs/security/oss-boundary.md)

## Status

`0.x`. The model and compiler are extracted from a system running real customer
workloads, so the behaviour is not speculative — but the *API* is young and may
change in a minor release. See [Public API](docs/core-concepts/public-api.md)
for what is covered and what is internal.

## Contributing

[CONTRIBUTING.md](CONTRIBUTING.md). The short version: the compiler stays pure,
and golden files are never regenerated to make a test pass.

## License

MIT. See [LICENSE](LICENSE).
