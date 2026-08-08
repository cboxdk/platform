---
title: The platform target
weight: 22
description: Declaring what a cluster can actually do as one typed object, instead of feature flags or product branches.
---

# The platform target

`PlatformTarget` is what the cluster being compiled for can actually do. It is
the *only* way a difference between two clusters reaches the compiler.

```php
use Cbox\Platform\Capability\BackupCatalog;
use Cbox\Platform\Capability\Certificates;
use Cbox\Platform\Capability\CustomerAccess;
use Cbox\Platform\Capability\HttpAutoscaler;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Runtime\ZeropodSnapshotRuntime;

$target = new PlatformTarget(
    snapshotRuntime: new ZeropodSnapshotRuntime('zeropod'),
    httpAutoscaler: new HttpAutoscaler(namespace: 'keda'),
    backups: new BackupCatalog(keyPrefix: 'acme'),
    customerAccess: new CustomerAccess(roles: ['cluster-admin' => 'admin']),
    certificates: Certificates::selfSigned(),
);
```

`new PlatformTarget` with no arguments is a complete, working target and the one
the golden files are compiled against.

## Two rules

**No product branches.** There is no `if ($local)` or `if ($cortex)` in the
compiler and there will not be one. Two consumers that branch on their own
identity stop meaning the same thing by "a Cbox application", which is precisely
the failure this package exists to prevent. Where two clusters genuinely differ,
the difference is a value here — and a value is testable in isolation.

**Only what is real.** Every member corresponds to something the compiler
already reads or branches on. A capability is added when a second target
actually differs, not in anticipation of one. A flag nothing reads is a switch
that reports itself on and does nothing, which is worse than no flag at all.

## The members

### `snapshotRuntime`

The node-side runtime that checkpoints an idle workload and restores it on the
next connection — the warm tier of scale-to-zero.

Its absence is not a degraded target, it is a **different compiled shape**:

| | with a snapshot runtime | without |
|---|---|---|
| what idles | the process, pod stays scheduled | the pod, replicas go to zero |
| what wakes it | a TCP connection | a request through the interceptor |
| emitted | pod annotations, possibly a `RuntimeClass` | an `HTTPScaledObject`, route pointed at the interceptor |
| needs a domain | no | yes — the wake is request-triggered |

The two tiers are mutually exclusive on purpose: a scaler that deletes the pod
would remove the very thing a checkpoint needs.

See [extension points](../extension-points/snapshot-runtime.md) to supply your
own.

### `httpAutoscaler`

Where the interceptor proxy lives — the thing that buffers the first request
while a cold workload starts. Defaults to the KEDA HTTP add-on's release name,
namespace and port. Change it if you install the add-on somewhere else.

### `backups`

The engine image repositories and the object-storage key prefix. These were read
from configuration inside the value objects before the extraction, which meant a
compiler that looked pure was resolving two settings through a global at the
moment it emitted a manifest. Passing them makes the compiler honest — and makes
it possible to compile a backup Job with nothing booted.

### `certificates`

Who signs the hostnames the target serves — and the first capability two real
targets cannot agree on.

| | reaches the hostname from outside? | use |
|---|---|---|
| `Certificates::acme($server, $email)` | **required** | hosted clusters; the default |
| `Certificates::selfSigned()` | no | development; the traffic is encrypted, nothing trusts the signer |
| `Certificates::certificateAuthority($secret)` | no | a local development CA the host already trusts, or an internal PKI |

ACME's HTTP-01 challenge needs the authority to reach the hostname from the
public internet. A local kind cluster cannot offer that at any price, so a local
target that inherited the default would compile an `Issuer` whose orders never
validate — every hostname without TLS, and nothing reporting an error.

`Certificates::needsInboundReachability()` is the one question to answer before
pointing a target at a cluster that is not publicly reachable.

The compiled `Certificate` objects are identical whichever source is chosen —
same hostnames, same Secrets, same Gateway. Only the `Issuer` differs, which is
exactly the shape a capability should have.

### `customerAccess`

The roles a customer's identity provider grants, and the group prefix the API
server applies. Empty — the default — means no bindings are compiled at all,
which is what keeps the feature off for a cluster with no identity provider
rather than adding objects that grant nothing.

## Building one from configuration

Reading config is the consumer's job, which is the point. A mapper that turns
your settings into a target belongs next to the mapper that turns your storage
into intent:

```php
public function target(): PlatformTarget
{
    return new PlatformTarget(
        snapshotRuntime: match (config('platform.snapshot_runtime')) {
            'zeropod' => new ZeropodSnapshotRuntime(config('platform.runtime_class')),
            default   => new NoSnapshotRuntime,
        },
        backups: new BackupCatalog(
            imageRepositories: config('platform.engine_images'),
            keyPrefix: config('platform.backup_prefix'),
        ),
    );
}
```

Validate there, not in the compiler — a target that declares a capability its
nodes do not have is a real failure mode, and it is silent: the warm tier is
selected, the cold tier is switched off, and the workload runs one replica for
ever while the product reports scale-to-zero as on.
