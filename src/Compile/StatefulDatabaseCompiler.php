<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Contracts\BackupCompiler;
use Cbox\Platform\Contracts\DatabaseCompiler;
use Cbox\Platform\Database\BackupSpec;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;
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
    public const MANAGED_LABEL = 'cortex.io/managed';

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
    private function labels(DatabaseSpec $spec): array
    {
        return [
            self::MANAGED_LABEL => 'true',
            'cortex.io/organization' => $spec->organizationId,
            'cortex.io/database' => $spec->databaseId,
            'app.kubernetes.io/name' => $spec->name,
            'app.kubernetes.io/managed-by' => 'cortex-sync',
        ];
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
                    'selector' => ['matchLabels' => ['cortex.io/database' => $spec->databaseId]],
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
                    'selector' => ['cortex.io/database' => $spec->databaseId],
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
