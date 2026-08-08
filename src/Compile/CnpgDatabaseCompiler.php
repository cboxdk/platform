<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Contracts\DatabaseCompiler;
use Cbox\Platform\Database\BackupSpec;
use Cbox\Platform\Database\BackupStorage;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Database\RestoreSpec;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;

/**
 * Compiles a managed database to a CloudNativePG Cluster, plus a
 * ScheduledBackup when a schedule is set. Cortex adopts CNPG — this is the
 * packaging (naming, labels, sizing, backups); streaming replication across
 * instances is the durability story, not a filesystem of our own.
 *
 * Every object carries the managed labels so the customer-cluster admission
 * layer refuses customer writes to it, exactly like compiled services.
 */
class CnpgDatabaseCompiler implements DatabaseCompiler
{
    public const MANAGED_LABEL = 'cortex.io/managed';

    public function __construct(
        private readonly PlatformTarget $target,
        private readonly BackupCompiler $backups,
    ) {}

    public function compile(DatabaseSpec $spec): ManifestSet
    {
        if ($spec->restore !== null) {
            return $this->restoreSet($spec, $spec->restore);
        }

        $storage = $spec->backupStorage;

        if ($spec->backupSchedule === null || $storage === null) {
            return new ManifestSet([$this->namespace($spec), $this->cluster($spec, null)]);
        }

        // The credential first: CNPG resolves the barmanObjectStore reference
        // as soon as the Cluster exists, and a Secret applied afterwards means
        // a first reconcile that fails on a credential about to appear.
        return new ManifestSet([
            $this->namespace($spec),
            $this->backups->credentials(BackupSpec::forOperatorBackups($spec, $storage, $this->target->backups)),
            $this->cluster($spec, $storage),
            $this->scheduledBackup($spec),
        ]);
    }

    /**
     * Suspending a database is CNPG's declarative hibernation: the operator
     * removes the instance pods and keeps the volumes, so the data is untouched
     * and only the compute stops. The value is always emitted — never just
     * omitted on resume — so that resuming is a real diff the reconciler applies,
     * rather than a deletion it would have to infer.
     *
     * The warm (~100ms) restore tier services can use does not apply here: CNPG
     * owns the instance pod spec and its CRD exposes no runtimeClassName, so a
     * snapshotting runtime cannot be placed under it. Waking a database is a
     * PostgreSQL start.
     *
     * @return array<string, string>
     */
    private function annotations(DatabaseSpec $spec): array
    {
        return ['cnpg.io/hibernation' => $spec->suspended ? 'on' : 'off'];
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

    /**
     * The backup section CNPG needs before it will take a backup at all.
     *
     * Without it the operator refuses every Backup with "cannot proceed with
     * the backup as the cluster has no backup section" — which is what a
     * from-scratch rebuild found: Cortex compiled a ScheduledBackup, CNPG
     * accepted it, and not one backup was ever taken. The ScheduledBackup on
     * its own is a schedule with nowhere to write.
     *
     * The destination is the organization's own prefix inside its own bucket,
     * and the credential is a Secret in the tenant's namespace — the same one
     * the engine backups use, so Postgres and Percona read one credential per
     * database rather than two spellings of it.
     *
     * @return array<string, mixed>
     */
    private function backupSection(BackupStorage $storage, DatabaseSpec $spec): array
    {
        return [
            'barmanObjectStore' => [
                'destinationPath' => "s3://{$storage->bucket}/".$this->target->backups->prefix($spec->organizationId)."/{$spec->name}",
                'endpointURL' => $storage->endpoint,
                's3Credentials' => [
                    'accessKeyId' => [
                        'name' => "{$spec->name}-backup-credentials",
                        'key' => 'access-key-id',
                    ],
                    'secretAccessKey' => [
                        'name' => "{$spec->name}-backup-credentials",
                        'key' => 'secret-access-key',
                    ],
                ],
                // WAL archiving is what makes the backup a recovery point
                // rather than a nightly snapshot: without it the best possible
                // restore is to whenever the last base backup ran.
                'wal' => ['compression' => 'gzip'],
                'data' => ['compression' => 'gzip'],
            ] + $this->endpointCa($storage, $spec),
            'retentionPolicy' => "{$storage->retainDays}d",
        ];
    }

    /**
     * A database that starts from a backup: CloudNativePG's recovery bootstrap
     * against the archive the SOURCE database wrote.
     *
     * This used to throw. The refusal was right about the danger it named — an
     * ordinary cluster compiled here comes up EMPTY where data was expected —
     * but it left the product's Restore control broken for the default engine,
     * and it failed at deploy time, after the action had already created the
     * database row. A row that can never be deployed is worse than an early no.
     *
     * The shape below is the one that actually worked against the live tenant
     * (docs/restore-drill.md); three earlier shapes did not.
     */
    private function restoreSet(DatabaseSpec $spec, RestoreSpec $restore): ManifestSet
    {
        $storage = $restore->storage;

        if ($storage === null || $restore->sourceName === '') {
            // Both come from the Backup being restored, so a gap here means the
            // spec was built from something that is not a physical backup. Say
            // that, rather than compiling a cluster that quietly starts empty.
            throw new \LogicException(
                "Backup [{$restore->backupId}] carries no object store or source database name; "
                .'a Postgres restore reads the source\'s barman archive and cannot be compiled without both.',
            );
        }

        $manifests = [
            $this->namespace($spec),
            // The restored database reads the archive under its OWN credential
            // secret, named for itself — the compiler names every secret after
            // the database it belongs to, and the source's may not outlive it.
            $this->backups->credentials(BackupSpec::forOperatorBackups($spec, $storage, $this->target->backups)),
            $this->cluster($spec, $spec->backupSchedule === null ? null : $storage, $restore),
        ];

        // A restored database keeps taking backups of its own if it was asked
        // to. Its archive is its own path, so it never writes into the one it
        // was recovered from.
        if ($spec->backupSchedule !== null) {
            $manifests[] = $this->scheduledBackup($spec);
        }

        return new ManifestSet($manifests);
    }

    /**
     * The recovery section, and the reason it is not obvious.
     *
     * `serverName` is the load-bearing line. Without it CNPG uses the
     * externalCluster's NAME as the barman server name, so it looks under
     * `<destinationPath>/<externalClusterName>/` — a path nothing ever wrote —
     * and fails with a flat `no target backup found`. That message reads as
     * "your backups are missing" rather than "you asked in the wrong place",
     * which is how it cost three attempts to find.
     *
     * The image is pinned by {@see cluster()} from the version, which the
     * restore action copies from the source. That matters more than it looks:
     * a recovery cluster on a DIFFERENT image ships a different barman, and the
     * restore dies with `'BackupInfo' object has no attribute 'encryption'`
     * before it touches a data file.
     *
     * @return array<string, mixed>
     */
    private function recoverySection(DatabaseSpec $spec, RestoreSpec $restore, BackupStorage $storage): array
    {
        $source = "{$restore->sourceName}-archive";

        $recovery = ['source' => $source];

        if ($restore->pointInTime !== null) {
            // Replayed from the WAL this cluster's source archived. CNPG stops
            // at the target and promotes; without it recovery runs to the end of
            // the archive, which is a different database than the one asked for.
            $recovery['recoveryTarget'] = ['targetTime' => $restore->pointInTime];
        }

        return [
            'bootstrap' => [
                'recovery' => $recovery,
            ],
            'externalClusters' => [[
                'name' => $source,
                'barmanObjectStore' => [
                    'destinationPath' => "s3://{$storage->bucket}/"
                        .$this->target->backups->prefix($spec->organizationId)."/{$restore->sourceName}",
                    'endpointURL' => $storage->endpoint,
                    'serverName' => $restore->sourceName,
                    's3Credentials' => [
                        'accessKeyId' => [
                            'name' => "{$spec->name}-backup-credentials",
                            'key' => 'access-key-id',
                        ],
                        'secretAccessKey' => [
                            'name' => "{$spec->name}-backup-credentials",
                            'key' => 'secret-access-key',
                        ],
                    ],
                    'wal' => ['compression' => 'gzip'],
                    'data' => ['compression' => 'gzip'],
                ] + $this->endpointCa($storage, $spec),
            ]],
        ];
    }

    /**
     * The authority barman should trust for this endpoint, when it is not one
     * the system store knows.
     *
     * Empty when the endpoint uses a publicly-trusted certificate, which is the
     * common case for external object storage — emitting an empty reference
     * there would make CNPG look for a key that is not in the Secret.
     *
     * @return array<string, mixed>
     */
    private function endpointCa(BackupStorage $storage, DatabaseSpec $spec): array
    {
        if ($storage->endpointCa === '') {
            return [];
        }

        return [
            'endpointCA' => [
                'name' => "{$spec->name}-backup-credentials",
                'key' => 'ca.crt',
            ],
        ];
    }

    /**
     * The environment's namespace.
     *
     * Emitted first, and this was missing: a database deployed into an
     * environment where no service had ever shipped failed with
     * `namespaces "cx-live-…" not found`. The service compiler created it, so
     * the order a customer happened to do things in decided whether their
     * database could be deployed at all. Both compilers now declare it, and
     * server-side apply makes the second one a no-op.
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

    private function cluster(DatabaseSpec $spec, ?BackupStorage $storage, ?RestoreSpec $restore = null): Manifest
    {
        $clusterSpec = [
            'instances' => $spec->instances,
            // Pinned from the version, and a restore inherits the source's
            // version — which is what keeps a recovery cluster on the same
            // barman as the archive it is reading. See recoverySection().
            'imageName' => "ghcr.io/cloudnative-pg/postgresql:{$spec->version}",
            'storage' => ['size' => $spec->storageSize],
            'postgresql' => [
                'parameters' => ['max_connections' => '200'],
            ],
        ];

        // ONE REPLICA PER NODE, and refuse to place two together.
        //
        // CloudNativePG's default is `preferred`, which is a hint the scheduler
        // may ignore — so a customer who asked for two instances could get both
        // on one machine and lose both to one machine. That is not a degraded
        // version of what they paid for; it is none of it, and it looks
        // identical in every status field until the node dies.
        //
        // `required` instead, so a replica that cannot be placed is visibly
        // Pending rather than quietly doubled up. The cell's own datastore has
        // made exactly this choice for exactly this reason.
        //
        // Only above one instance: a single-instance database has nothing to
        // spread, and `required` there would refuse to schedule it at all on a
        // cluster with one node.
        if ($spec->instances > 1) {
            $clusterSpec['affinity'] = [
                'enablePodAntiAffinity' => true,
                'topologyKey' => 'kubernetes.io/hostname',
                'podAntiAffinityType' => 'required',
            ];
        }

        if ($storage !== null) {
            $clusterSpec['backup'] = $this->backupSection($storage, $spec);
        }

        if ($restore !== null && $restore->storage !== null) {
            $clusterSpec += $this->recoverySection($spec, $restore, $restore->storage);
        }

        return new Manifest(
            apiVersion: 'postgresql.cnpg.io/v1',
            kind: 'Cluster',
            name: $spec->name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'postgresql.cnpg.io/v1',
                'kind' => 'Cluster',
                'metadata' => [
                    'name' => $spec->name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                    'annotations' => $this->annotations($spec),
                ],
                'spec' => $clusterSpec,
            ],
        );
    }

    private function scheduledBackup(DatabaseSpec $spec): Manifest
    {
        return new Manifest(
            apiVersion: 'postgresql.cnpg.io/v1',
            kind: 'ScheduledBackup',
            name: $spec->name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'postgresql.cnpg.io/v1',
                'kind' => 'ScheduledBackup',
                'metadata' => [
                    'name' => $spec->name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'schedule' => $spec->backupSchedule,
                    'backupOwnerReference' => 'self',
                    'cluster' => ['name' => $spec->name],
                ],
            ],
        );
    }
}
