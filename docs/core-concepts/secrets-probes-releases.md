---
title: Secrets by reference, probes and the release
weight: 27
description: Environment from a Secret an operator created, readiness and liveness for the web process, and a command that has to finish before the workloads roll.
---

# Secrets by reference, probes and the release

Three things a production service needs that a spec could not say before
`0.12`: a credential that never passes through whoever builds the spec, a
readiness probe that holds a rollout until the new pod can serve, and a
migration that runs once, before any of that.

## Secrets by reference

`ServiceSpec::$envSecret` carries **values**: the compiler writes them into
`<service>-env` and references that. Whoever builds the spec holds the plaintext.

`ServiceSpec::$secretRefs` carries **names**:

```php
use Cbox\Platform\Service\SecretReference;

new ServiceSpec(
    // …
    secretRefs: [
        new SecretReference(variable: 'APP_KEY', secret: 'cbox-id-app', key: 'APP_KEY'),
        new SecretReference(variable: 'MAIL_PASSWORD', secret: 'cbox-id-mail', key: 'MAIL_PASSWORD'),
    ],
);
```

Each one compiles to `valueFrom.secretKeyRef` on **every container of the
service** — the web Deployment, every process Deployment, every run Job and the
release Job — because they all take the web container and change its command.
The Secret is the operator's: nothing here creates, updates or prunes it, and its
value is never in the spec, the compiled set, a plan, a diff or a golden file.

**A missing Secret, or a missing key in it, fails the pod — on purpose.** The
reference is never `optional`, so Kubernetes holds the container in
`CreateContainerConfigError` with an event naming the Secret or the key, and the
pod does not start. That is the right failure: a process that booted without its
`APP_KEY` would be serving, and wrong. Create the Secret in the service's
namespace before the first deploy.

Refused at compile time, with a sentence rather than an API-server error:

| Refused | Why |
|---|---|
| a variable that is not `[A-Za-z_][A-Za-z0-9_]*` | the only names every shell and runtime can read |
| a Secret name that is not an RFC 1123 subdomain (≤ 253) | Kubernetes would refuse it |
| a key outside `[-._a-zA-Z0-9]`, over 253, or `.`/`..` | Kubernetes would refuse it |
| a variable also in `env`, `envSecret`, a binding, or another reference | a container takes the last entry for a name, so one source would be silently ignored |
| `<service>-env` or `<service>-registry` | those are Secrets this compiler writes; the next deploy would overwrite the operator's |

## Probes

`ServiceSpec::$health` takes a `HealthCheck`. Null compiles no probes, which is
what every service compiled before this.

```php
use Cbox\Platform\Service\HealthCheck;

health: new HealthCheck(
    readinessPath: '/health/ready',
    livenessPath: '/up',
    tokenVariable: 'HEALTH_TOKEN',   // optional
),
```

Web Deployment only. A worker listens on nothing, so a process Deployment, a run
and the release carry no probes at all.

| Probe | From | Shape |
|---|---|---|
| `startupProbe` | `livenessPath` | `httpGet`, every 2 s, 150 failures — five minutes to boot |
| `livenessProbe` | `livenessPath` | `httpGet`, every 10 s, timeout 3 s, restart after 3 misses |
| `readinessProbe` | `readinessPath` | `httpGet` — or `exec` with a token, below — every 10 s, timeout 5 s, out of rotation after 3 misses |

**Startup gates liveness.** Until it passes, neither liveness nor readiness
runs, so a slow first boot is not killed for being slow. No liveness path, no
startup probe: it exists only to hold liveness back.

**Liveness is shallow.** Point it at something that answers whenever the process
is up — Laravel's `/up`. A liveness probe on a deep check restarts every
healthy pod the moment the database is slow.

### Readiness that needs a token

An application that refuses to describe its dependencies to an anonymous caller
is right to. But a Kubernetes `httpGet` probe sends **static headers only** —
there is no `secretKeyRef` for a probe header — so the token would have to be
written into the pod spec, readable by anyone who can `kubectl get deploy -o
yaml`. That is not offered.

Instead a readiness check with a `tokenVariable` compiles to an `exec` probe that
asks the container itself, with the token from its own environment:

```sh
printf 'Authorization: Bearer %s\n' "${HEALTH_TOKEN}" \
  | curl -fsS -o /dev/null --max-time 4 -H @- 'http://127.0.0.1:80/health/ready'
```

- The value never leaves the Secret → env path: the probe names the variable,
  the container's shell reads it.
- The header goes through stdin (`-H @-`), not argv, so the token is not in the
  process list either.
- It needs `sh` and `curl` in the image. Every Cbox base image has both; an image
  without curl fails readiness visibly (`curl: not found` in the pod's events)
  rather than serving unprobed.
- The variable must be set by the spec — normally a `SecretReference` — or the
  compile is refused: an empty token is a probe that is refused forever and a
  rollout that hangs.
- Refused on the checkpointing (CRIU) scale-to-zero tier, where an idle
  container is a checkpoint and has nothing to run a command in. Use a readiness
  path that needs no token there.

The path is restricted to `[A-Za-z0-9._~%/+:@=&?,-]`, because it is part of a
shell line: a quote, a dollar sign or a space is refused rather than escaped.

The alternative — letting the readiness path answer anyone on the pod network
without a token — was rejected: it exposes which dependency is unhappy to every
pod in the cluster, which is exactly what the token is there to prevent.

## The release

`ServiceSpec::$release` is an argv list, `['php', 'artisan', 'migrate', '--force']`.
`ServiceCompiler` ignores it. `ReleaseJobCompiler` compiles it:

```php
use Cbox\Platform\Compile\ReleaseJobCompiler;

$release = new ReleaseJobCompiler($services, $target)->compile($spec);  // ManifestSet: 0 or 1 Job
```

The Job is a [run](../getting-started/installation.md): the web container with
the command on it — same image, env, Secret references, bindings and pull
credential — no probes, `restartPolicy: Never`, `backoffLimit: 0`, an hour's
deadline, kept a day after it finishes so its logs can be read.

**Its name is the release.** `<service>-release-<10 hex>`, where the hash is of
the Job's pod template. The same spec compiles to the same Job, so an applier
deploying it twice finds it already `Complete` and does not migrate twice; a new
image, variable or command is a new Job. (A Job's template is immutable, so a
fixed name could only ever run the first release.) Empty while the service is
suspended.

**It is deliberately not in the service's manifest set.** A set is applied at
once and has no order; the release must finish before the Deployments change.
The compiler keeps the two apart so the applier can sequence them:

1. Apply the service's set **without** its `Deployment`s and the scalers that
   act on them (`ScaledObject`, `HTTPScaledObject`) — the namespace, Secrets,
   Services and databases the release needs. Wait for the databases to accept
   connections.
2. If a Job of the release's name exists and has `Failed`, delete it (the same
   release, retried). Apply the release Job and wait for its `Complete` or
   `Failed` condition. Watch its pod: `CreateContainerConfigError` (a missing
   Secret) or `ImagePullBackOff` will never finish on their own, so treat them as
   a failure rather than waiting out the deadline.
3. On `Failed`, **stop**: report the Job's logs and apply nothing else. The
   running release — the old Deployments — is untouched and still serving.
4. On `Complete`, apply the full set. The workloads roll behind their readiness
   probes.

Migrations therefore run while the previous release still serves, so they must
be safe for the old code: add, never drop or rename what it still reads.
