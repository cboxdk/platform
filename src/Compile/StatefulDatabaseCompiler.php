<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Contracts\BackupCompiler;
use Cbox\Platform\Contracts\DatabaseCompiler;
use Cbox\Platform\Database\BackupSpec;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Manifest\Labels;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;

/**
 * Compiles the engines Cortex schedules itself — Valkey and Percona Server — to a
 * StatefulSet with a volume claim template and a headless-addressable Service.
 *
 * Because Cortex owns the pod spec here (unlike CloudNativePG, which owns its
 * own), these engines can carry a snapshotting runtime class: an idle database
 * is checkpointed to disk and restored on the next connection in ~100ms instead
 * of going through a full engine start. Suspending still means no pod at all.
 *
 * Every object carries the managed labels, so the customer-cluster admission
 * layer refuses customer writes to it, exactly like every other compiled
 * resource.
 */
class StatefulDatabaseCompiler implements DatabaseCompiler
{
    public function __construct(
        private readonly PlatformTarget $target,
        private readonly BackupCompiler $backups,
    ) {}

    private const DATA_PATH = [
        'valkey' => '/data',
        'percona' => '/var/lib/mysql',
    ];

    /**
     * Where an engine keeps its data on disk.
     *
     * Postgres has no entry and never will: CloudNativePG owns that data path
     * and this compiler refuses Postgres outright elsewhere. The invariant was
     * previously unstated and the lookup could silently miss — this makes the
     * refusal explicit rather than leaving a mount with an empty path.
     */
    private static function dataPath(DatabaseEngine $engine): string
    {
        return self::DATA_PATH[$engine->value] ?? throw new \LogicException(
            "Engine [{$engine->value}] has no data path here; Postgres belongs to CloudNativePG."
        );
    }

    public function compile(DatabaseSpec $spec): ManifestSet
    {
        $this->guardSingleInstance($spec);

        // The environment's namespace, first. Without it a database deployed
        // into an environment where no service had ever shipped failed with
        // `namespaces "…" not found` — the service compiler created it, so the
        // order a customer happened to do things in decided whether their
        // database could be deployed at all.
        $manifests = [$this->namespace($spec)];

        if ($spec->password !== null) {
            $manifests[] = $this->credentials($spec);
        }

        $manifests[] = $this->statefulSet($spec);
        $manifests[] = $this->service($spec);

        return new ManifestSet([...$manifests, ...$this->backupManifests($spec)]);
    }

    /**
     * A schedule on a database is a recurring base backup, so it compiles here
     * the same way CloudNativePG's ScheduledBackup does for Postgres — the
     * customer expresses "back this up nightly" once, on the database.
     *
     * A restore needs the same Secret without the schedule, because its init
     * container reads the copy out of the same bucket.
     *
     * @return list<Manifest>
     */
    private function backupManifests(DatabaseSpec $spec): array
    {
        if ($spec->backupSchedule !== null) {
            return $this->backups->compileSchedule(BackupSpec::scheduleFor($spec, $this->target->backups))->manifests;
        }

        $restore = $spec->restore;

        if ($restore !== null && $restore->storage !== null) {
            return [$this->backups->credentials(BackupSpec::forRestore($spec, $restore, $this->target->backups))];
        }

        return [];
    }

    /**
     * The warm tier applies only to engines Cortex schedules itself, when the
     * customer opted in, the cell offers a snapshot runtime, and the database is
     * not suspended — a suspend means no pod to snapshot.
     */
    private function snapshots(DatabaseSpec $spec): bool
    {
        return $spec->engine->isCortexScheduled()
            && $spec->scaleToZero
            && ! $spec->suspended
            && $this->target->snapshotRuntime->isAvailable();
    }

    /**
     * @return array<string, string>
     */
    /**
     * @return array<string, string>
     */
    private function labels(DatabaseSpec $spec): array
    {
        return $this->target->identity->labels(
            name: $spec->name,
            identity: [
                'organization' => $spec->organizationId,
                'database' => $spec->databaseId,
            ],
            instance: $spec->name,
        );
    }

    private function image(DatabaseSpec $spec): string
    {
        if ($spec->engine === DatabaseEngine::Postgres) {
            throw new \LogicException('Postgres compiles to CloudNativePG, not a StatefulSet.');
        }

        // Our own image, not the upstream one. It carries cbox-init as PID 1 —
        // which is what makes the engine checkpointable and lets it be tuned to
        // the container — plus the backup tools the Job and the restore init
        // container call. It is also the same build those use, so a restore can
        // never prepare a backup with a different engine than wrote it.
        return $this->target->backups->image($spec->engine, $spec->version);
    }

    /**
     * The engine's credentials, as a Secret in the customer's own cluster. The
     * value comes from the spec — the compiler stays a pure function and never
     * mints secrets of its own.
     */
    private function namespace(DatabaseSpec $spec): Manifest
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

    private function credentials(DatabaseSpec $spec): Manifest
    {
        return new Manifest(
            apiVersion: 'v1',
            kind: 'Secret',
            name: $spec->name.'-credentials',
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => [
                    'name' => $spec->name.'-credentials',
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'type' => 'Opaque',
                'stringData' => ['password' => $spec->password],
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function env(DatabaseSpec $spec): array
    {
        $env = [];

        // The supervisor sizes the engine to the container, and a database that
        // sleeps must be tuned differently from one that does not: a checkpoint
        // captures the process's memory, so the engine's cache is what a wake has
        // to read back. Only said when true — an engine never told it may sleep
        // must not be tuned as though it does.
        if ($this->snapshots($spec)) {
            $env[] = ['name' => 'CBOX_WAKE_MODE', 'value' => 'warm'];
        }

        if ($spec->engine !== DatabaseEngine::Percona) {
            return $env;
        }

        return [...$env, [
            'name' => 'MYSQL_ROOT_PASSWORD',
            'valueFrom' => [
                'secretKeyRef' => ['name' => $spec->name.'-credentials', 'key' => 'password'],
            ],
        ]];
    }

    /**
     * ONE INSTANCE, OR NOTHING, on this path — and the alternative was not a
     * degraded database, it was several.
     *
     * This compiler schedules the engine itself: a StatefulSet, a volume claim
     * template, one Service. Nothing in it configures replication, because
     * neither Valkey nor MySQL replicates by being started more than once. So
     * `instances: 3` compiled to three independent servers, each with its own
     * disk, behind one Service that load-balances across them — and a write
     * landed on whichever pod it reached. Three databases that each believe
     * they are the database, diverging silently, with every status reporting
     * healthy.
     *
     * Refused rather than clamped to one. Clamping would give somebody who
     * asked for three a working database and a false belief about it, and the
     * belief is the dangerous half: they would plan a failover that cannot
     * happen. CloudNativePG is on the other path precisely because it does this
     * properly — an operator that elects a primary, streams to replicas and
     * fails over. Until MySQL has one here, more than one instance is a promise
     * this compiler cannot keep.
     *
     * The same stance the engine router takes one level up: an engine with no
     * registered compiler is refused rather than silently compiled as something
     * else.
     *
     * AND A STATEFULSET IS THE WRONG SHAPE FOR A REPLICATED DATABASE ANYWAY,
     * which is the deeper reason this cap is not a temporary inconvenience.
     * When a node DIES rather than draining, its pod stays `Terminating`
     * forever: a StatefulSet promises at most one pod per ordinal, and a silent
     * node is indistinguishable from a partitioned one still writing to its
     * volume — so Kubernetes chooses stuck over corrupt, and the database is
     * down until a human force-deletes the pod.
     *
     * CloudNativePG does not use StatefulSets for this reason. Its own
     * ClusterRole grants `pods`, `pods/exec` and `persistentvolumeclaims` and
     * no `statefulsets` at all: it manages each instance itself, so a lost
     * member is replaced and re-synchronised from the primary rather than
     * waited for. See docs/core-concepts/databases.md.
     */
    private function guardSingleInstance(DatabaseSpec $spec): void
    {
        if ($spec->instances > 1) {
            throw new \LogicException(
                "[{$spec->name}] asks for {$spec->instances} instances of {$spec->engine->value}, and "
                .'this compiler schedules the engine itself without configuring replication — so that '
                .'would be that many independent servers behind one Service, each with its own disk, '
                .'diverging on the first write. Use one instance, or an engine whose operator '
                .'replicates.'
            );
        }
    }

    /**
     * A suspended database has no pod at all. Otherwise the customer's instance
     * count stands — with a snapshotting runtime the "zero" is a checkpointed
     * process, not a missing pod, so the replica count must not be zeroed.
     */
    private function replicas(DatabaseSpec $spec): int
    {
        return $spec->suspended ? 0 : $spec->instances;
    }

    /**
     * @return array<string, mixed>
     */
    private function podTemplateMetadata(DatabaseSpec $spec): array
    {
        $metadata = ['labels' => $this->labels($spec)];

        if ($this->snapshots($spec)) {
            $annotations = $this->target->snapshotRuntime->annotations(
                $spec->name,
                $spec->engine->defaultPort(),
                $spec->idleTimeoutSeconds,
            );

            if ($annotations !== []) {
                $metadata['annotations'] = $annotations;
            }
        }

        return $metadata;
    }

    private function statefulSet(DatabaseSpec $spec): Manifest
    {
        $port = $spec->engine->defaultPort();

        $container = [
            'name' => $spec->name,
            'image' => $this->image($spec),
            'ports' => [['containerPort' => $port, 'name' => $spec->engine->value]],
            'volumeMounts' => [['name' => 'data', 'mountPath' => self::dataPath($spec->engine)]],
            'resources' => ['requests' => ['cpu' => '100m', 'memory' => '256Mi']],
        ];

        $env = $this->env($spec);

        if ($env !== []) {
            $container['env'] = $env;
        }

        $podSpec = ['containers' => [$container]];

        // A restore loads the copy into the volume before the engine ever
        // starts, so the instance's first state is the restored one — never a
        // fresh database that is overwritten afterwards.
        $restoreContainer = $spec->restore === null
            ? null
            : $this->backups->restoreInitContainer($spec->restore, $spec->engine, $spec->version, $spec->name);

        if ($restoreContainer !== null) {
            $podSpec = ['initContainers' => [$restoreContainer]] + $podSpec;
        }

        $runtimeClass = $this->snapshots($spec) ? $this->target->snapshotRuntime->runtimeClassName() : null;

        if ($runtimeClass !== null) {
            $podSpec = ['runtimeClassName' => $runtimeClass] + $podSpec;
        }

        return new Manifest(
            apiVersion: 'apps/v1',
            kind: 'StatefulSet',
            name: $spec->name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'apps/v1',
                'kind' => 'StatefulSet',
                'metadata' => [
                    'name' => $spec->name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'serviceName' => $spec->name,
                    'replicas' => $this->replicas($spec),
                    'selector' => ['matchLabels' => [$this->target->identity->label('database') => $spec->databaseId]],
                    'template' => [
                        'metadata' => $this->podTemplateMetadata($spec),
                        'spec' => $podSpec,
                    ],
                    // The claim is the durable half: suspending removes the pod,
                    // never this.
                    'volumeClaimTemplates' => [[
                        'metadata' => ['name' => 'data'],
                        'spec' => $this->claimSpec($spec),
                    ]],
                ],
            ],
        );
    }

    /**
     * Restoring from a volume snapshot is the storage backend's own job: the
     * claim names the snapshot as its data source and the CSI driver fills it,
     * so nothing has to be copied by a container at all.
     *
     * @return array<string, mixed>
     */
    private function claimSpec(DatabaseSpec $spec): array
    {
        $claim = [
            'accessModes' => ['ReadWriteOnce'],
            'resources' => ['requests' => ['storage' => $spec->storageSize]],
        ];

        $source = $spec->restore === null ? null : $this->backups->restoreVolumeSource($spec->restore);

        if ($source !== null) {
            $claim['dataSource'] = $source;
        }

        return $claim;
    }

    private function service(DatabaseSpec $spec): Manifest
    {
        $port = $spec->engine->defaultPort();

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
                    'selector' => [$this->target->identity->label('database') => $spec->databaseId],
                    'ports' => [[
                        'name' => $spec->engine->value,
                        'port' => $port,
                        'targetPort' => $port,
                    ]],
                ],
            ],
        );
    }
}
