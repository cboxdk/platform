<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Binding\BindingSpec;
use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Binding\ConnectionSource;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Contracts\Compiler;
use Cbox\Platform\Manifest\Labels;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Service\ProcessSpec;
use Cbox\Platform\Service\ServiceSpec;
use Cbox\Platform\Service\VolumeSpec;

/**
 * The reference compiler for a Service: Namespace, Deployment, Service, and —
 * when a hostname is set — an HTTPRoute carrying every one of them. Every object carries the managed
 * labels; the admission layer in customer clusters refuses customer writes to
 * anything so labeled, which is what makes kubectl-drift impossible.
 */
class ServiceCompiler implements Compiler
{
    public function __construct(private readonly PlatformTarget $target) {}

    public function compile(ServiceSpec $spec): ManifestSet
    {
        // Before anything is compiled: a service that cannot work is better
        // refused than deployed into a state only Kubernetes can explain.
        $this->guardName($spec->name);

        foreach ($spec->processes as $process) {
            $this->guardName($process->name);
        }

        $this->guardVolumeReplicas($spec);
        $this->guardAutoscaleHasCpuRequest($spec);

        $manifests = [$this->namespace($spec)];

        // Claims ahead of the Deployment that mounts them. A pod scheduled
        // against a claim that does not exist stays Pending with an event
        // nobody is reading.
        foreach ($spec->volumes as $volume) {
            $manifests[] = $this->claim($spec, $volume);
        }

        // Ahead of the Deployment that references it: a pod scheduled before
        // its Secret exists stays in CreateContainerConfigError rather than
        // retrying cleanly.
        if ($spec->envSecret !== []) {
            $manifests[] = $this->secret($spec);
        }

        if ($spec->registry !== null) {
            $manifests[] = $this->pullSecret($spec);
        }

        $manifests[] = $this->deployment($spec);
        $manifests[] = $this->service($spec);

        // Replicas survive a crash; a budget survives a DRAIN.
        //
        // Draining is not an exceptional event on this platform — it is how a
        // node is replaced when it dies, how a pool is resized, and how a
        // cluster is upgraded. Without a budget the eviction API takes every
        // replica of a customer's service at once, and the service is simply
        // down for as long as rescheduling takes, on a cluster where nothing
        // looks broken.
        if ($budget = $this->disruptionBudget($spec)) {
            $manifests[] = $budget;
        }

        // Workers and schedulers: a Deployment each, and nothing else. No
        // Service and no route, because nothing dials a worker — a serving
        // object in front of a queue consumer is a load balancer for traffic
        // that never arrives.
        foreach ($spec->processes as $process) {
            $manifests[] = $this->processDeployment($spec, $process);

            if ($budget = $this->disruptionBudget($spec, $process->name, $process->replicas)) {
                $manifests[] = $budget;
            }
        }

        if ($this->autoscaleTier($spec)) {
            $manifests[] = $this->scaledObject($spec);
        }

        if ($this->kedaTier($spec)) {
            $manifests[] = $this->httpScaledObject($spec);
        }

        if ($spec->domains !== []) {
            $manifests[] = $this->httpRoute($spec);
        }

        // What a customer's own kubectl may do in this namespace. Nothing at
        // all when identity is not configured, so no existing tenant gains an
        // object it cannot use.
        foreach (new CustomerAccessCompiler($this->target->customerAccess, $this->target->identity)->forNamespace($spec->namespace, $this->labels($spec)) as $binding) {
            $manifests[] = $binding;
        }

        foreach ($this->customResources($spec, $manifests) as $manifest) {
            $manifests[] = $manifest;
        }

        return new ManifestSet($manifests);
    }

    /**
     * The customer's own objects, made into managed ones.
     *
     * THREE THINGS ARE TAKEN AWAY FROM THE CUSTOMER, and none of them is policy:
     *
     *   - the NAMESPACE is the environment's. "Deployed with this service" is
     *     what carrying it means, and a resource that could name its own
     *     namespace is a tenancy escape wearing a feature's clothes;
     *   - the LABELS are the platform's. An object carrying the managed label
     *     would impersonate a platform object, and the tenant's admission policy
     *     keys on exactly that label to decide who may write to what;
     *   - a NAME already used by a compiled object is refused rather than one
     *     silently overwriting the other.
     *
     * What is left — the spec, the annotations, everything the object is
     * actually for — is untouched.
     *
     * @param  list<Manifest>  $compiled
     * @return list<Manifest>
     */
    private function customResources(ServiceSpec $spec, array $compiled): array
    {
        if ($spec->customResources === []) {
            return [];
        }

        $policy = $this->target->customResources;
        $taken = [];

        foreach ($compiled as $manifest) {
            $taken[$manifest->key()] = true;
        }

        $manifests = [];

        foreach ($spec->customResources as $resource) {
            if (! $policy->allows($resource)) {
                throw new \LogicException($policy->refusalFor($resource));
            }

            if (isset($taken[$resource->key()])) {
                throw new \LogicException(
                    "[{$resource->key()}] is already compiled for service [{$spec->name}]. "
                    .'A custom resource cannot take the name of an object the platform owns, '
                    .'because one of them would silently overwrite the other.'
                );
            }

            $taken[$resource->key()] = true;

            $body = $resource->body;
            $body['apiVersion'] = $resource->apiVersion;
            $body['kind'] = $resource->kind;

            $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
            $metadata['name'] = $resource->name;
            $metadata['namespace'] = $spec->namespace;
            $metadata['labels'] = $this->labels($spec, 'custom')
                + (is_array($metadata['labels'] ?? null) ? $metadata['labels'] : []);

            $body['metadata'] = $metadata;

            $manifests[] = new Manifest(
                apiVersion: $resource->apiVersion,
                kind: $resource->kind,
                name: $resource->name,
                namespace: $spec->namespace,
                body: $body,
            );
        }

        return $manifests;
    }

    /**
     * A budget for anything running more than one replica.
     *
     * NOT at one replica, and that is deliberate rather than an omission. A
     * budget over a single pod cannot keep anything available — there is
     * nothing to keep — and it makes the node UNDRAINABLE: a customer running
     * one replica would block the node replacement that a dead machine, a
     * resize or an upgrade depends on, and the operator draining it would have
     * no way to tell a stuck drain from a broken one.
     *
     * A service that wants to survive a drain runs two. That is the honest
     * trade, and it is the same one the tenant control plane makes.
     *
     * maxUnavailable rather than minAvailable, so it reads the same at two
     * replicas and at twenty: one at a time, whatever the customer chose.
     */
    private function disruptionBudget(ServiceSpec $spec, ?string $process = null, ?int $replicas = null): ?Manifest
    {
        $replicas ??= $spec->replicas;

        // Not for a workload that is deliberately not running.
        //
        // A SUSPENDED service is pinned to zero replicas, and a scale-to-zero
        // one has a count that belongs to the autoscaler and is legitimately
        // zero. A budget over either would be measured against a number nobody
        // set, and it would block every drain on the node — for a service the
        // customer has stopped.
        if ($replicas < 2 || $spec->suspended || $this->scaleToZeroActive($spec)) {
            return null;
        }

        $name = $process === null ? $spec->name : $spec->name.'-'.$process;

        // The SAME selector the Deployment uses, never an approximation of it.
        // A budget that selects pods the Deployment does not own protects the
        // wrong set, and one that selects fewer protects nothing while looking
        // like it does.
        $selector = $process === null
            ? [$this->target->identity->label('service') => $spec->serviceId]
            : ['app.kubernetes.io/name' => $spec->name, $this->target->identity->label('process') => $process];

        return new Manifest(
            apiVersion: 'policy/v1',
            kind: 'PodDisruptionBudget',
            name: $name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'policy/v1',
                'kind' => 'PodDisruptionBudget',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'maxUnavailable' => 1,
                    'selector' => ['matchLabels' => $selector],
                ],
            ],
        );
    }

    /**
     * Scale-to-zero is active when the customer opted in and has not pinned the
     * service down. The two tiers below are mutually exclusive — which one runs
     * depends only on whether the cell offers a snapshotting runtime class.
     */
    private function scaleToZeroActive(ServiceSpec $spec): bool
    {
        return $spec->scaleToZero && ! $spec->suspended;
    }

    /**
     * The CRIU/zeropod tier: the runtime snapshots the *process* of a pod that
     * stays scheduled, and restores it on the next connection — measured at
     * ~100ms against ~4s for a cold start. Because the pod must exist for the
     * runtime to snapshot it, this tier keeps the customer's replica count and
     * must NOT be paired with the KEDA scaler, which would delete the pod.
     */
    private function criuTier(ServiceSpec $spec): bool
    {
        return $this->scaleToZeroActive($spec) && $this->target->snapshotRuntime->isAvailable();
    }

    /**
     * The KEDA cold-start tier: no snapshotting runtime, so the wake is a real
     * pod start and a request has to be buffered while it happens. Needs a
     * domain — the wake is triggered by an inbound request routed through the
     * HTTP interceptor.
     */
    private function kedaTier(ServiceSpec $spec): bool
    {
        return $this->scaleToZeroActive($spec) && ! $this->target->snapshotRuntime->isAvailable() && $spec->domains !== [];
    }

    /**
     * @return array<string, string>
     */
    /**
     * The workload's labels: identity, plus the version it is running.
     *
     * @return array<string, string>
     */
    private function workloadLabels(ServiceSpec $spec, string $component): array
    {
        $version = Labels::versionFrom($spec->image);

        return $this->labels($spec, $component)
            + ($version !== null ? ['app.kubernetes.io/version' => $version] : []);
    }

    /**
     * @return array<string, string>
     */
    private function labels(ServiceSpec $spec, ?string $component = null): array
    {
        return $this->target->identity->labels(
            name: $spec->name,
            identity: [
                'organization' => $spec->organizationId,
                'service' => $spec->serviceId,
            ],
            component: $component,
            // NO version here. It is derived from the image tag, and putting it
            // on every object means bumping a tag marks the Service, the
            // HTTPRoute and the Namespace as changed too — a plan full of
            // objects the deploy did not meaningfully touch. It goes on the
            // workload, which is the thing that actually has a version.
            instance: $spec->serviceId,
            partOf: $spec->partOf !== '' ? $spec->partOf : null,
        );
    }

    private function namespace(ServiceSpec $spec): Manifest
    {
        return new Manifest(
            apiVersion: 'v1',
            kind: 'Namespace',
            name: $spec->namespace,
            namespace: '',
            body: [
                'apiVersion' => 'v1',
                'kind' => 'Namespace',
                'metadata' => [
                    'name' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
            ],
        );
    }

    /**
     * One binding, as container env.
     *
     * Nothing here carries a credential. A host and a port are literals
     * because they are not secrets and pretending otherwise buys nothing; a
     * user and a password are `secretKeyRef` entries pointing at the Secret
     * the DATABASE already owns — the one Cortex compiled for Valkey and
     * Percona, or the one CloudNativePG generated for Postgres.
     *
     * The consequence worth stating: the control plane never holds the
     * password, so it cannot leak it, and a rotated password reaches this
     * workload on the pod's next start without a deploy and without Cortex
     * being told.
     *
     * A URL is composed IN THE POD with Kubernetes' `$(VAR)` expansion, which
     * is why the parts it needs are emitted alongside it under
     * binding-private names. Building the string here would mean holding the
     * password to interpolate it — exactly what this design avoids.
     *
     * @return list<array<string, mixed>>
     */
    private function bindingEnv(BindingSpec $binding): array
    {
        $env = [];
        $wanted = [];

        foreach ($binding->map as $entry) {
            $wanted[$entry['field']->value] = $entry['name'];
        }

        // A URL needs the parts whether or not the customer mapped them, so
        // they are emitted under names private to this binding. Least
        // privilege is unaffected: they are the same fields, and the customer
        // asked for the URL that contains them.
        $needsUrl = isset($wanted[ConnectionField::Url->value]);
        $prefix = '_CX_'.strtoupper(str_replace('-', '_', $binding->databaseName));

        foreach ([ConnectionField::Host, ConnectionField::Port, ConnectionField::Database, ConnectionField::User, ConnectionField::Password] as $field) {
            $exported = $wanted[$field->value] ?? null;
            $internal = $needsUrl && $exported === null ? $prefix.'_'.strtoupper($field->value) : null;

            foreach (array_filter([$exported, $internal]) as $name) {
                $entry = $this->connectionEnv($binding, $field, (string) $name);

                if ($entry !== null) {
                    $env[] = $entry;
                }
            }
        }

        if ($needsUrl) {
            $ref = fn (ConnectionField $f): string => '$('.($wanted[$f->value] ?? $prefix.'_'.strtoupper($f->value)).')';

            $env[] = [
                'name' => $wanted[ConnectionField::Url->value],
                'value' => ConnectionSource::scheme($binding->engine).'://'
                    .$ref(ConnectionField::User).':'.$ref(ConnectionField::Password)
                    .'@'.$ref(ConnectionField::Host).':'.$ref(ConnectionField::Port)
                    .'/'.$ref(ConnectionField::Database),
            ];
        }

        return $env;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function connectionEnv(BindingSpec $binding, ConnectionField $field, string $name): ?array
    {
        $key = $binding->source->secretKeys[$field->value] ?? null;

        if ($key !== null) {
            return [
                'name' => $name,
                'valueFrom' => ['secretKeyRef' => [
                    'name' => $binding->source->secretName,
                    'key' => $key,
                ]],
            ];
        }

        $literal = $binding->source->plain[$field->value] ?? null;

        // A field this engine simply does not have injects nothing, rather
        // than an empty string the application would treat as configured.
        return $literal === null ? null : ['name' => $name, 'value' => $literal];
    }

    /**
     * A non-serving process, as its own Deployment.
     *
     * The container is the application's — same image, environment, secrets,
     * bindings and pull credential — with a command on it and the serving
     * parts removed. Sharing rather than restating is the point: a worker
     * needs the same DATABASE_URL as the web process, and a second copy of the
     * binding is how the two drift.
     *
     * Scale-to-zero does not reach here. Both tiers wake on an inbound
     * request, and a queue worker has none — idling one to zero means it stops
     * consuming and never starts again.
     */
    private function processDeployment(ServiceSpec $spec, ProcessSpec $process): Manifest
    {
        $name = $spec->name.'-'.$process->name;

        $deployment = $this->deployment($spec);
        $base = $deployment->body;

        $pod = $deployment->map('spec', 'template', 'spec');
        $container = $deployment->listItem(0, 'spec', 'template', 'spec', 'containers');

        $container['name'] = $process->name;
        $container['command'] = $process->command;
        unset($container['ports'], $container['readinessProbe'], $container['livenessProbe']);

        $mounted = $this->mountsFor($spec, $process->name);

        if ($mounted['mounts'] !== []) {
            $container['volumeMounts'] = $mounted['mounts'];
        } else {
            unset($container['volumeMounts']);
        }

        $pod['containers'] = [$container];
        $spread = $this->spread($spec, $process->name);

        if ($spread === []) {
            unset($pod['topologySpreadConstraints']);
        } else {
            $pod['topologySpreadConstraints'] = $spread;
        }

        if ($mounted['volumes'] !== []) {
            $pod['volumes'] = $mounted['volumes'];
        } else {
            unset($pod['volumes']);
        }

        $labels = $this->labels($spec) + [$this->target->identity->label('process') => $process->name];

        return new Manifest(
            apiVersion: 'apps/v1',
            kind: 'Deployment',
            name: $name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $spec->namespace,
                    'labels' => $labels,
                ],
                'spec' => ($mounted['volumes'] !== [] ? ['strategy' => ['type' => 'Recreate']] : []) + [
                    // Suspending the application suspends its workers too:
                    // leaving them consuming while the web process is down is
                    // how a "stopped" application keeps mutating data.
                    'replicas' => $spec->suspended ? 0 : $process->replicas,
                    'selector' => ['matchLabels' => [
                        'app.kubernetes.io/name' => $spec->name,
                        $this->target->identity->label('process') => $process->name,
                    ]],
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => $pod,
                    ],
                ],
            ],
        );
    }

    /**
     * Spread a workload's replicas across nodes.
     *
     * There was no placement of any kind, so three replicas could all land on
     * one node — and one node dying took the whole service with it. That is an
     * availability hole in a single datacentre, before any multi-region
     * question arises.
     *
     * `ScheduleAnyway`, deliberately, not `DoNotSchedule`. A hard constraint
     * on a cluster with fewer nodes than replicas leaves pods Pending forever,
     * and a customer who asked for three replicas on a two-node pool should
     * get three running pods unevenly spread rather than two and a permanent
     * failure. The scheduler still prefers to spread; it just does not refuse.
     *
     * @return list<array<string, mixed>>
     */
    private function spread(ServiceSpec $spec, string $process = 'web'): array
    {
        return $this->target->placement->constraints([
            'app.kubernetes.io/name' => $spec->name,
            $this->target->identity->label('process') => $process,
        ]);
    }

    private function pullSecretName(ServiceSpec $spec): string
    {
        return $spec->name.'-registry';
    }

    /**
     * The customer's registry credential, as the kubelet expects to find it.
     *
     * Per service rather than per namespace, so removing a service removes its
     * credential with it — `TeardownResource` reconciles the whole compiled set
     * away, and a namespace-wide Secret would survive as an orphan nothing
     * owns.
     */
    private function pullSecret(ServiceSpec $spec): Manifest
    {
        $name = $this->pullSecretName($spec);

        return new Manifest(
            apiVersion: 'v1',
            kind: 'Secret',
            name: $name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'type' => 'kubernetes.io/dockerconfigjson',
                'stringData' => ['.dockerconfigjson' => $spec->registry?->dockerConfigJson() ?? '{}'],
            ],
        );
    }

    private function secretName(ServiceSpec $spec): string
    {
        return $spec->name.'-env';
    }

    /**
     * The service's secret env, as a Kubernetes Secret.
     *
     * A Secret is not encryption — etcd holds it base64-encoded and a cluster
     * admin can read it. What it buys is that the values are no longer in the
     * Deployment spec, so they do not appear in `kubectl get deploy -o yaml`,
     * in `kubectl describe`, in a GitOps diff, or in the event stream when the
     * pod template changes. The control plane's own copy is encrypted at rest;
     * this is the cluster-side half of the same promise.
     */
    private function secret(ServiceSpec $spec): Manifest
    {
        $name = $this->secretName($spec);

        return new Manifest(
            apiVersion: 'v1',
            kind: 'Secret',
            name: $name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'type' => 'Opaque',
                'stringData' => $spec->envSecret,
            ],
        );
    }

    /**
     * The PersistentVolumeClaim behind one volume.
     *
     * ReadWriteOnce because that is what block storage is: the Hetzner CSI
     * driver offers nothing else, and every volume already in the live tenant
     * is RWO. It is not a default that could be relaxed later — it is the
     * property that forces the two rules below.
     */
    private function claim(ServiceSpec $spec, VolumeSpec $volume): Manifest
    {
        $name = $spec->name.'-'.$volume->name;

        return new Manifest(
            apiVersion: 'v1',
            kind: 'PersistentVolumeClaim',
            name: $name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'v1',
                'kind' => 'PersistentVolumeClaim',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec) + [$this->target->identity->label('volume') => $volume->name],
                ],
                'spec' => [
                    'accessModes' => ['ReadWriteOnce'],
                    'resources' => ['requests' => ['storage' => $volume->size]],
                ],
            ],
        );
    }

    /**
     * The volumes and mounts for one process's pod.
     *
     * @return array{volumes: list<array<string, mixed>>, mounts: list<array<string, mixed>>}
     */
    private function mountsFor(ServiceSpec $spec, string $process): array
    {
        $volumes = [];
        $mounts = [];

        // THE APPLICATION ITSELF, when the build was layered.
        //
        // An OCI image volume: the kubelet pulls the image and mounts its
        // filesystem read-only, without running it. So the container is a
        // Cortex base image carrying the runtime and cbox-init, and the
        // customer's application arrives as a mount.
        //
        // readOnly is not a precaution — it is what an image volume IS. A
        // process that writes to its own code directory is one whose changes
        // vanish on reschedule, and pretending otherwise would hide that until
        // a pod moved.
        //
        // FIRST in the list, so an ordinary volume can be mounted INSIDE the
        // application path — a Laravel storage/ directory is exactly that, and
        // a later mount has to win over the earlier one for it to work.
        if ($spec->baseImage !== '' && $spec->image !== '') {
            $volumes[] = [
                'name' => $this->target->identity->name('app'),
                'image' => ['reference' => $spec->image, 'pullPolicy' => 'IfNotPresent'],
            ];

            $mounts[] = [
                'name' => $this->target->identity->name('app'),
                'mountPath' => $spec->appMountPath,
                'readOnly' => true,
            ];
        }

        foreach ($spec->volumes as $volume) {
            if (! $volume->mountedBy($process)) {
                continue;
            }

            $volumes[] = [
                'name' => $volume->name,
                'persistentVolumeClaim' => ['claimName' => $spec->name.'-'.$volume->name],
            ];
            $mounts[] = ['name' => $volume->name, 'mountPath' => $volume->mountPath];
        }

        return ['volumes' => $volumes, 'mounts' => $mounts];
    }

    /**
     * How a Deployment holding a volume must be rolled.
     *
     * `Recreate`, and this is the one that bites hardest. A RollingUpdate
     * starts the replacement pod before stopping the old one, and the old one
     * still holds the ReadWriteOnce volume — so the new pod cannot attach, the
     * old one is never told to stop because the new one never becomes ready,
     * and the deploy hangs until somebody deletes a pod by hand. It looks like
     * a slow deploy for about ten minutes and then like an outage.
     *
     * @return array<string, mixed>
     */
    private function strategyFor(ServiceSpec $spec): array
    {
        return $spec->volumes === []
            ? []
            : ['strategy' => ['type' => 'Recreate']];
    }

    /**
     * A service holding a volume runs one replica.
     *
     * Not a preference. A ReadWriteOnce volume attaches to one node, so the
     * second replica sits in ContainerCreating forever with a
     * `Multi-Attach error` nobody outside Kubernetes can read. Refusing at
     * compile time turns a permanently broken deploy into a sentence.
     */
    /**
     * An autoscaler targeting CPU *utilization* measures a percentage OF THE
     * REQUEST. With no request there is no denominator: the metric reports as
     * unknown, the HPA never acts, and the service reports autoscaling as on
     * while running a fixed replica count for ever.
     *
     * Refused rather than defaulted. A request invented here would be a number
     * nobody chose deciding both the scheduling and the scaling behaviour of a
     * workload whose owner has explicitly sized everything else about it.
     */
    private function guardAutoscaleHasCpuRequest(ServiceSpec $spec): void
    {
        if (! $spec->autoscales() || $spec->resources()->hasCpuRequest()) {
            return;
        }

        throw new \LogicException(
            "Service [{$spec->name}] autoscales on CPU but sets no CPU request. "
            .'CPU utilization is measured against the request, so without one the metric is '
            .'unavailable and the service would never scale. Set a CPU request, or turn off '
            .'CPU autoscaling.'
        );
    }

    /**
     * A name Kubernetes will actually accept.
     *
     * Refused here rather than by the API server, because the two failures read
     * completely differently: one is "a service name may not contain an
     * uppercase letter", the other is a rejected apply somewhere inside a
     * deploy, phrased in a vocabulary the customer never opted into.
     *
     * The RFC 1123 LABEL grammar, which is the strict one — a Service and the
     * DNS record it gets are limited to it, and every object this compiler
     * derives (`web`, `web-worker`, the PVCs) inherits from the same name. The
     * looser subdomain grammar that most objects allow would let a name through
     * that the Service alone would then reject.
     *
     * Cbox Cortex already enforces exactly this at its API and web layers. It
     * lives here too so that a second consumer does not have to rediscover it,
     * which is the whole reason this package exists.
     */
    private function guardName(string $name): void
    {
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name) !== 1 || strlen($name) > 63) {
            throw new \LogicException(
                "[{$name}] cannot be a Kubernetes object name. Use lower-case letters, digits "
                .'and hyphens, start and end with a letter or digit, and keep it to 63 characters.'
            );
        }
    }

    private function guardVolumeReplicas(ServiceSpec $spec): void
    {
        if ($spec->volumes === []) {
            return;
        }

        if ($spec->replicas > 1) {
            throw new \LogicException(
                "Service [{$spec->name}] has {$spec->replicas} replicas and a persistent volume. "
                .'Block storage attaches to one node at a time, so the extra replicas would never start. '
                .'Run one replica, or move the shared state into a database.'
            );
        }

        foreach ($spec->processes as $process) {
            $mounted = false;

            foreach ($spec->volumes as $volume) {
                if ($volume->mountedBy($process->name)) {
                    $mounted = true;
                    break;
                }
            }

            if ($mounted && $process->replicas > 1) {
                throw new \LogicException(
                    "Process [{$process->name}] has {$process->replicas} replicas and mounts a persistent "
                    .'volume. Block storage attaches to one node at a time, so the extra replicas would '
                    .'never start.'
                );
            }
        }
    }

    private function deployment(ServiceSpec $spec): Manifest
    {
        $env = [];

        foreach ($this->effectiveEnv($spec) as $key => $value) {
            $env[] = ['name' => $key, 'value' => $value];
        }

        foreach ($spec->bindings as $binding) {
            foreach ($this->bindingEnv($binding) as $entry) {
                $env[] = $entry;
            }
        }

        // Secrets by REFERENCE, never by value. Inlined here they are readable
        // by anyone who can `kubectl get deployment -o yaml` in the customer's
        // own cluster — which, for a cluster the customer hands to their whole
        // team, is everyone.
        foreach (array_keys($spec->envSecret) as $key) {
            $env[] = [
                'name' => $key,
                'valueFrom' => ['secretKeyRef' => [
                    'name' => $this->secretName($spec),
                    'key' => $key,
                ]],
            ];
        }

        $container = [
            'name' => $spec->name,
            // THE BASE IMAGE RUNS, and the application is mounted onto it.
            //
            // For a layered build the two swap roles: the container is a Cortex
            // base image carrying the runtime and cbox-init, and $spec->image
            // holds only the application, mounted as an OCI image volume below.
            //
            // That is the whole point of the strategy. A CVE in PHP, OpenSSL or
            // the base OS is fixed by moving this tag — no rebuild, no customer
            // action, and every service on that base moves at once, instead of
            // a fleet-wide rebuild that can only go as fast as the slowest
            // customer's build.
            //
            // Empty base image means a Dockerfile build or a prebuilt image,
            // where $spec->image is the whole story and nothing is mounted.
            'image' => $spec->baseImage !== '' ? $spec->baseImage : $spec->image,
            'ports' => [['containerPort' => $spec->port, 'name' => 'http']],
            'resources' => $spec->resources()->toArray(),
        ];

        if ($env !== []) {
            $container['env'] = $env;
        }

        $mounted = $this->mountsFor($spec, 'web');

        if ($mounted['mounts'] !== []) {
            $container['volumeMounts'] = $mounted['mounts'];
        }

        $podSpec = [
            'containers' => [$container],
            ...$this->target->placement->podFields([
                'app.kubernetes.io/name' => $spec->name,
                $this->target->identity->label('process') => 'web',
            ]),
        ];

        if ($mounted['volumes'] !== []) {
            $podSpec['volumes'] = $mounted['volumes'];
        }

        if ($spec->registry !== null) {
            $podSpec['imagePullSecrets'] = [['name' => $this->pullSecretName($spec)]];
        }

        // The CRIU/zeropod tier runs the pod under a snapshotting runtime class.
        $runtimeClass = $this->criuTier($spec) ? $this->target->snapshotRuntime->runtimeClassName() : null;

        if ($runtimeClass !== null) {
            $podSpec = ['runtimeClassName' => $runtimeClass] + $podSpec;
        }

        return new Manifest(
            apiVersion: 'apps/v1',
            kind: 'Deployment',
            name: $spec->name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => [
                    'name' => $spec->name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->workloadLabels($spec, 'web'),
                ],
                'spec' => $this->strategyFor($spec) + [
                    'replicas' => $this->desiredReplicas($spec),
                    'selector' => [
                        'matchLabels' => [$this->target->identity->label('service') => $spec->serviceId],
                    ],
                    'template' => [
                        'metadata' => $this->podTemplateMetadata($spec),
                        'spec' => $podSpec,
                    ],
                ],
            ],
        );
    }

    /**
     * A suspended service is pinned to zero — that is the whole point of the
     * explicit suspend. On the KEDA tier the scaler owns the count from zero
     * upward, so the Deployment seeds zero and hands over. On the CRIU tier the
     * pod must stay scheduled for the runtime to snapshot and restore it, so the
     * customer's replica count stands and the "zero" is the checkpointed
     * process, not a missing pod.
     */
    private function desiredReplicas(ServiceSpec $spec): int
    {
        if ($spec->suspended) {
            return 0;
        }

        if ($this->kedaTier($spec)) {
            return 0;
        }

        // On the autoscaling tier the ScaledObject owns the count. Seeding the
        // customer's fixed number would have the Deployment and the scaler
        // write `replicas` at each other on every reconcile.
        return $this->autoscaleTier($spec) ? max(1, $spec->autoscaleMin ?? 1) : $spec->replicas;
    }

    /**
     * The pod template's metadata. On the CRIU tier it also carries the
     * snapshotting runtime's own contract: which container and port to watch,
     * and how long the process may idle before it is checkpointed.
     *
     * @return array<string, mixed>
     */
    private function podTemplateMetadata(ServiceSpec $spec): array
    {
        // The process label names the serving process, so the spread
        // constraint below has something to select on. Without it the
        // constraint matches nothing and is a silent no-op — the pods schedule
        // wherever, and the object looks configured.
        $metadata = ['labels' => $this->workloadLabels($spec, 'web')
            + [$this->target->identity->label('process') => 'web']];

        if ($this->criuTier($spec)) {
            $annotations = $this->target->snapshotRuntime->annotations($spec->name, $spec->port, $spec->idleTimeoutSeconds);

            if ($annotations !== []) {
                $metadata['annotations'] = $annotations;
            }
        }

        return $metadata;
    }

    /**
     * The customer's env, with snapshot-readiness keys forced on for the CRIU
     * tier (they must win over any customer value for the checkpoint to work).
     *
     * @return array<string, string>
     */
    private function effectiveEnv(ServiceSpec $spec): array
    {
        $env = $spec->env;

        // WHAT THE BASE IMAGE IS ASKED TO DO, first — so a customer who wrote a
        // variable by hand still wins. The typed settings emit nothing for a
        // field nobody chose, so this changes no existing service until one
        // does.
        //
        // Only on a Cortex base image. cbox-init is what reads any of it, and
        // setting LARAVEL_SCHEDULER on an image with nothing listening is a
        // switch that reports itself on and does nothing.
        if ($spec->baseImage !== '' && $spec->runtime !== null) {
            $env = array_merge($spec->runtime->environment(), $env);
        }

        if ($spec->baseImage !== '' && $spec->podCidr !== '') {
            // THE LAST HOP OF THE CLIENT'S ADDRESS.
            //
            // Envoy now receives the real client address over PROXY protocol
            // and forwards it in X-Forwarded-For — and the application still
            // saw Envoy's pod IP in REMOTE_ADDR, because nginx trusts nobody by
            // default. Every layer was correct and the answer was still wrong
            // at the only place a customer's code can read it.
            //
            // The tenant's POD range, not a wildcard: the only thing that
            // legitimately proxies to this container is the gateway beside it.
            $env = array_merge(['NGINX_TRUSTED_PROXIES' => $spec->podCidr], $env);
        }

        if ($this->criuTier($spec)) {
            return array_merge($env, $this->target->snapshotRuntime->environment());
        }

        return $env;
    }

    private function service(ServiceSpec $spec): Manifest
    {
        return new Manifest(
            apiVersion: 'v1',
            kind: 'Service',
            name: $spec->name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => [
                    'name' => $spec->name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'selector' => [$this->target->identity->label('service') => $spec->serviceId],
                    'ports' => [[
                        'name' => 'http',
                        'port' => 80,
                        'targetPort' => $spec->port,
                    ]],
                ],
            ],
        );
    }

    private function httpRoute(ServiceSpec $spec): Manifest
    {
        return new Manifest(
            apiVersion: 'gateway.networking.k8s.io/v1',
            kind: 'HTTPRoute',
            name: $spec->name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'gateway.networking.k8s.io/v1',
                'kind' => 'HTTPRoute',
                'metadata' => [
                    'name' => $spec->name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'hostnames' => $spec->domains,
                    // The environment's own Gateway, in the environment's own
                    // namespace. It used to point at a single tenant-wide
                    // Gateway installed from a static manifest — which is why
                    // no application could have a certificate: nothing
                    // compiled that object, so nothing could add a listener
                    // for a hostname.
                    // ACROSS THE NAMESPACE BOUNDARY when the gateway is shared,
                    // and inside this one when the environment owns it. Both
                    // name the namespace explicitly: a parentRef without one
                    // means "this namespace", which is right in exactly one of
                    // the two cases and silently wrong in the other.
                    'parentRefs' => [[
                        'name' => $this->target->gatewayOwnership->shared
                            ? $this->target->gatewayOwnership->name
                            : $this->target->identity->name('gateway'),
                        'namespace' => $this->target->gatewayOwnership->shared
                            ? $this->target->gatewayOwnership->namespace
                            : $spec->namespace,
                    ]],
                    'rules' => [[
                        'backendRefs' => [$this->routeBackend($spec)],
                    ]],
                ],
            ],
        );
    }

    /**
     * The HTTPRoute backend. On the KEDA tier it is the interceptor, which
     * buffers the request while the pod starts. Everywhere else — including the
     * CRIU tier, where the runtime restores the process on TCP connect and needs
     * no proxy in front — it is the workload Service itself.
     *
     * @return array<string, mixed>
     */
    private function routeBackend(ServiceSpec $spec): array
    {
        if ($this->kedaTier($spec)) {
            return [
                // The interceptor proxy, which buffers the request while the
                // pod starts. Where it lives is a property of the target's
                // installed stack, not of the application.
                'name' => $this->target->httpAutoscaler->name,
                'namespace' => $this->target->httpAutoscaler->namespace,
                'port' => $this->target->httpAutoscaler->port,
            ];
        }

        return ['name' => $spec->name, 'port' => 80];
    }

    /**
     * The KEDA HTTP add-on object: buffer requests for `hosts`, scale the
     * Deployment between 0 and the customer's replica count, and idle it back to
     * zero after `scaledownPeriod` seconds without traffic.
     */
    /**
     * Does this service scale on CPU?
     *
     * Mutually exclusive with both scale-to-zero tiers, and that is enforced
     * rather than documented: two autoscalers pointed at one Deployment fight
     * over `spec.replicas`, each overwriting the other every few seconds, and
     * the pod count oscillates for reasons no single object explains. The
     * scale-to-zero tiers already own the replica count from zero upward.
     */
    private function autoscaleTier(ServiceSpec $spec): bool
    {
        return $spec->autoscales()
            && ! $spec->suspended
            && ! $this->kedaTier($spec)
            && ! $this->criuTier($spec);
    }

    /**
     * A KEDA ScaledObject with a CPU trigger.
     *
     * `type: Utilization` is a percentage of the pod's REQUEST, not of the
     * node — so a service requesting 100m and using 80m is at 80%, whatever
     * size the machine is. That makes the number mean the same thing on every
     * node type, which a value measured against the node would not.
     *
     * This reads the metrics API, which nothing installed until now: without
     * metrics-server the object is accepted, reports an error in its status,
     * and never scales. The addon Job installs it.
     */
    private function scaledObject(ServiceSpec $spec): Manifest
    {
        return new Manifest(
            apiVersion: $this->target->httpAutoscaler->scaledObjectApiVersion,
            kind: 'ScaledObject',
            name: $spec->name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => $this->target->httpAutoscaler->scaledObjectApiVersion,
                'kind' => 'ScaledObject',
                'metadata' => [
                    'name' => $spec->name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'scaleTargetRef' => ['name' => $spec->name, 'kind' => 'Deployment'],
                    // Never zero on this tier. Scaling to zero on CPU cannot
                    // work: a pod using no CPU is the signal to remove it, and
                    // once it is gone there is no CPU to read and nothing that
                    // would ever bring it back. That is what the HTTP add-on
                    // tier is for, and why the two are exclusive.
                    'minReplicaCount' => max(1, $spec->autoscaleMin ?? 1),
                    'maxReplicaCount' => $spec->autoscaleMax,
                    'triggers' => [[
                        'type' => 'cpu',
                        'metricType' => 'Utilization',
                        'metadata' => ['value' => (string) $spec->autoscaleCpuPercent],
                    ]],
                ],
            ],
        );
    }

    private function httpScaledObject(ServiceSpec $spec): Manifest
    {
        return new Manifest(
            apiVersion: $this->target->httpAutoscaler->httpScaledObjectApiVersion,
            kind: 'HTTPScaledObject',
            name: $spec->name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => $this->target->httpAutoscaler->httpScaledObjectApiVersion,
                'kind' => 'HTTPScaledObject',
                'metadata' => [
                    'name' => $spec->name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'hosts' => $spec->domains,
                    'scaleTargetRef' => [
                        'name' => $spec->name,
                        'kind' => 'Deployment',
                        'apiVersion' => 'apps/v1',
                        'service' => $spec->name,
                        'port' => 80,
                    ],
                    'replicas' => [
                        'min' => 0,
                        'max' => $spec->replicas,
                    ],
                    'scaledownPeriod' => $spec->idleTimeoutSeconds,
                ],
            ],
        );
    }
}
