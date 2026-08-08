<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Contracts\BackupCompiler as BackupCompilerContract;
use Cbox\Platform\Database\BackupSpec;
use Cbox\Platform\Database\BackupStorage;
use Cbox\Platform\Database\BackupType;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\RestoreSpec;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;

/**
 * Compiles backups for the engines Cortex schedules itself — Percona Server and
 * Valkey. Postgres never reaches here: CloudNativePG compiles its own
 * ScheduledBackup and owns that data path, so this refuses it loudly rather than
 * producing a second, competing backup mechanism for the same database.
 *
 * A base backup is a Job, not a sidecar, and that is a measured decision rather
 * than a stylistic one: a checkpoint only happens when no TCP connection is
 * open, so a sidecar polling the database would keep it awake permanently and
 * cost the whole warm tier. Continuous binlog archiving — which a Job cannot
 * do — belongs to cbox-init, which reads files and never opens a connection.
 *
 * Credentials are never inline. The compiler emits one Secret per database and
 * every container reads it through secretKeyRef, so no key ever appears in a pod
 * spec, an argument list, or the applied-state we persist.
 */
class BackupCompiler implements BackupCompilerContract
{
    public const MANAGED_LABEL = 'cortex.io/managed';

    public function __construct(private readonly PlatformTarget $target) {}

    /** Where each engine keeps its data, and so what a physical copy reads. */
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

    /**
     * XtraBackup writes working files even when the copy is streamed, and it
     * writes them into `--target-dir`. Left unset it defaults to a path under
     * the process's working directory, which in a backup pod is `/` — not
     * writable by the engine's unprivileged user, so the backup dies with
     * `cannot mkdir: 13 /xtrabackup_backupfiles/` before it reads a byte. The
     * scratch path is the container's own filesystem, never the data volume:
     * that is mounted read-only, exactly so a backup can never write to the
     * data it is protecting.
     */
    private const XTRABACKUP_SCRATCH = '/tmp/xtrabackup';

    /**
     * XtraBackup's version check downloads a Perl script from Percona and runs
     * it on every invocation. In a customer's cluster that is an unannounced
     * egress call on the path of every backup and every restore — it fails
     * outright on a node without that egress, and the image has no Perl
     * `English` module for it to succeed with anyway. A backup must not depend
     * on the internet being reachable.
     */
    private const NO_VERSION_CHECK = '--no-version-check';

    public function compile(BackupSpec $spec): ManifestSet
    {
        $this->guardEngine($spec->engine, $spec->type);

        if ($spec->type === BackupType::Snapshot) {
            return new ManifestSet([$this->volumeSnapshot($spec)]);
        }

        return new ManifestSet([
            $this->credentials($spec),
            $this->job($spec),
        ]);
    }

    public function compileSchedule(BackupSpec $spec): ManifestSet
    {
        $this->guardEngine($spec->engine, $spec->type);

        if ($spec->schedule === null) {
            throw new \LogicException('compileSchedule() needs a spec that carries a schedule.');
        }

        if ($spec->type !== BackupType::Physical) {
            throw new \LogicException('Only a physical backup can be scheduled — it is the base PITR replays onto.');
        }

        return new ManifestSet([
            $this->credentials($spec),
            $this->cronJob($spec),
        ]);
    }

    /**
     * The Job that removes a copy from object storage. Deleting a backup has to
     * delete the bytes, not just the row that remembers them: the control plane
     * holds no bucket credentials by design, so the only place that can reach
     * the object is the customer's own cluster.
     *
     * A volume snapshot needs none of this — deleting the VolumeSnapshot object
     * is what deletes the copy.
     */
    public function compilePrune(BackupSpec $spec): ManifestSet
    {
        $this->guardEngine($spec->engine, $spec->type);

        $storage = $spec->storage;

        if ($storage === null || ! $spec->type->usesObjectStorage()) {
            throw new \LogicException('Only an object-storage backup is pruned by a Job.');
        }

        $name = $this->objectName($spec).'-prune';

        return new ManifestSet([
            $this->credentials($spec),
            new Manifest(
                apiVersion: 'batch/v1',
                kind: 'Job',
                name: $name,
                namespace: $spec->namespace,
                body: [
                    'apiVersion' => 'batch/v1',
                    'kind' => 'Job',
                    'metadata' => [
                        'name' => $name,
                        'namespace' => $spec->namespace,
                        'labels' => $this->labels($spec),
                    ],
                    'spec' => [
                        'backoffLimit' => 2,
                        'ttlSecondsAfterFinished' => 3600,
                        'template' => [
                            'metadata' => ['labels' => $this->labels($spec)],
                            'spec' => [
                                'restartPolicy' => 'Never',
                                'containers' => [[
                                    'name' => 'prune',
                                    'image' => $spec->image,
                                    'command' => [$this->shell($spec->engine), '-c', implode("\n", [
                                        'set -euo pipefail',
                                        'aws --endpoint-url "$S3_ENDPOINT" s3 rm --recursive'
                                            .' "s3://$S3_BUCKET/$BACKUP_KEY"',
                                    ])."\n"],
                                    'env' => $this->storageEnv(
                                        $storage,
                                        $spec->databaseName,
                                        $spec->engine,
                                        ['BACKUP_KEY' => $spec->storageKey],
                                    ),
                                    'resources' => ['requests' => ['cpu' => '50m', 'memory' => '128Mi']],
                                ]],
                            ],
                        ],
                    ],
                ],
            ),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restoreInitContainer(RestoreSpec $restore, DatabaseEngine $engine, string $version, string $databaseName): ?array
    {
        $this->guardEngine($engine, $restore->type);

        if (! $restore->type->canSeedNewInstance()) {
            throw new \LogicException(
                'A logical dump cannot bring a database into being — create the database, then import the dump into it.',
            );
        }

        // A volume snapshot is restored by the CSI driver filling the claim, so
        // by the time any container runs the data is already there.
        if ($restore->type === BackupType::Snapshot) {
            return null;
        }

        $storage = $restore->storage;

        if ($storage === null) {
            throw new \LogicException('An object-storage restore needs storage configuration.');
        }

        if ($restore->pointInTime !== null) {
            throw new \LogicException(BackupCompilerContract::PITR_UNIMPLEMENTED);
        }

        $image = $this->restoreImage($engine, $version);

        return [
            'name' => 'restore',
            'image' => $image,
            'command' => [$this->shell($engine), '-c', $this->restoreScript($engine)],
            'env' => $this->storageEnv($storage, $databaseName, $engine, ['BACKUP_KEY' => $restore->storageKey]),
            'volumeMounts' => [['name' => 'data', 'mountPath' => self::dataPath($engine)]],
        ];
    }

    /**
     * The dataSource a restored database's volume claim carries, when the copy is
     * a volume snapshot — the CSI driver clones it into the new claim.
     *
     * @return array<string, string>|null
     */
    public function restoreVolumeSource(RestoreSpec $restore): ?array
    {
        if ($restore->type !== BackupType::Snapshot) {
            return null;
        }

        return [
            'apiGroup' => 'snapshot.storage.k8s.io',
            'kind' => 'VolumeSnapshot',
            'name' => $restore->storageKey,
        ];
    }

    /**
     * The shell each engine image actually has. Percona's is a UBI9 image and
     * carries bash; Valkey's is Alpine and does not — a `/bin/bash` there is a
     * container that never starts, which is how every Valkey backup and every
     * Valkey restore would have failed. Busybox `sh` supports `set -euo
     * pipefail`, so the scripts themselves need no second dialect.
     */
    private function shell(DatabaseEngine $engine): string
    {
        return $engine === DatabaseEngine::Valkey ? '/bin/sh' : '/bin/bash';
    }

    private function guardEngine(DatabaseEngine $engine, BackupType $type): void
    {
        if (! $engine->isCortexScheduled()) {
            throw new \LogicException(
                'Postgres backups belong to CloudNativePG, which compiles its own ScheduledBackup.',
            );
        }

        if (! $type->supports($engine)) {
            throw new \LogicException("A {$type->value} backup of {$engine->value} is not supported.");
        }
    }

    /**
     * @return array<string, string>
     */
    private function labels(BackupSpec $spec): array
    {
        return [
            self::MANAGED_LABEL => 'true',
            'cortex.io/organization' => $spec->organizationId,
            'cortex.io/database' => $spec->databaseId,
            'cortex.io/backup' => $spec->backupId,
            'app.kubernetes.io/name' => $spec->databaseName,
            'app.kubernetes.io/component' => 'backup',
            'app.kubernetes.io/managed-by' => 'cortex-sync',
        ];
    }

    private function secretName(BackupSpec $spec): string
    {
        return $spec->databaseName.'-backup-credentials';
    }

    private function objectName(BackupSpec $spec): string
    {
        return $spec->schedule !== null
            ? $spec->databaseName.'-backup'
            : 'backup-'.strtolower(substr($spec->backupId, -12));
    }

    /**
     * The volume claim a StatefulSet's `data` template produced for the first
     * replica — the deterministic name Kubernetes gives it.
     */
    private function claimName(string $databaseName): string
    {
        return 'data-'.$databaseName.'-0';
    }

    /**
     * The object-storage identity, as a Secret in the customer's own cluster —
     * one per database, shared by the one-off Job, the scheduled CronJob and a
     * restore. The value comes from the spec; the compiler mints nothing.
     */
    public function credentials(BackupSpec $spec): Manifest
    {
        $storage = $spec->storage;

        if ($storage === null) {
            throw new \LogicException('An object-storage backup needs storage configuration.');
        }

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
                'stringData' => array_filter([
                    'access-key-id' => $storage->accessKeyId,
                    'secret-access-key' => $storage->secretAccessKey,
                    // Alongside the keys rather than in a Secret of its own: it
                    // is the same trust decision, it is rotated with the same
                    // credential, and one object is one thing to keep in step.
                    'ca.crt' => $storage->endpointCa,
                ], static fn (string $v): bool => $v !== ''),
            ],
        );
    }

    /**
     * Everything the backup tools need that is not a secret, plus the four
     * secretKeyRef entries that carry the ones that are. XtraBackup's xbcloud
     * reads ACCESS_KEY_ID/SECRET_ACCESS_KEY; the AWS CLI reads the AWS_* pair —
     * both point at the same Secret rather than at a literal.
     *
     * @param  array<string, string>  $extra
     * @return list<array<string, mixed>>
     */
    private function storageEnv(BackupStorage $storage, string $databaseName, DatabaseEngine $engine, array $extra = []): array
    {
        $secret = $databaseName.'-backup-credentials';

        $env = [
            ['name' => 'S3_ENDPOINT', 'value' => $storage->endpoint],
            ['name' => 'S3_BUCKET', 'value' => $storage->bucket],
            ['name' => 'S3_REGION', 'value' => $storage->region],
            ['name' => 'AWS_DEFAULT_REGION', 'value' => $storage->region],
        ];

        foreach ($extra as $name => $value) {
            $env[] = ['name' => $name, 'value' => $value];
        }

        foreach ([
            'ACCESS_KEY_ID' => 'access-key-id',
            'SECRET_ACCESS_KEY' => 'secret-access-key',
            'AWS_ACCESS_KEY_ID' => 'access-key-id',
            'AWS_SECRET_ACCESS_KEY' => 'secret-access-key',
        ] as $name => $key) {
            $env[] = [
                'name' => $name,
                'valueFrom' => ['secretKeyRef' => ['name' => $secret, 'key' => $key]],
            ];
        }

        // Percona's tools authenticate to the engine as well, with the password
        // the database compiler already put in its own Secret.
        if ($engine === DatabaseEngine::Percona) {
            $env[] = [
                'name' => 'MYSQL_ROOT_PASSWORD',
                'valueFrom' => ['secretKeyRef' => ['name' => $databaseName.'-credentials', 'key' => 'password']],
            ];
        }

        return $env;
    }

    /**
     * A physical copy reads the volume, so it must run where the volume is: a
     * ReadWriteOnce claim can only be mounted by pods on one node. Co-locating
     * with the database pod is the declarative way to say that. A logical dump
     * speaks the protocol instead, so it can run anywhere.
     *
     * @return array<string, mixed>
     */
    private function podSpec(BackupSpec $spec, string $script): array
    {
        $storage = $spec->storage;

        if ($storage === null) {
            throw new \LogicException('An object-storage backup needs storage configuration.');
        }

        $container = [
            'name' => 'backup',
            'image' => $spec->image,
            'command' => [$this->shell($spec->engine), '-c', $script],
            'env' => $this->storageEnv($storage, $spec->databaseName, $spec->engine, $spec->schedule !== null
                ? ['BACKUP_PREFIX' => $spec->storageKey, 'RETAIN_DAYS' => (string) $storage->retainDays]
                : ['BACKUP_KEY' => $spec->storageKey]),
            'resources' => ['requests' => ['cpu' => '100m', 'memory' => '256Mi']],
        ];

        $podSpec = ['restartPolicy' => 'Never'];

        if ($this->readsVolume($spec)) {
            $container['volumeMounts'] = [[
                'name' => 'data',
                'mountPath' => self::dataPath($spec->engine),
                'readOnly' => true,
            ]];

            $podSpec['affinity'] = [
                'podAffinity' => [
                    'requiredDuringSchedulingIgnoredDuringExecution' => [[
                        'labelSelector' => ['matchLabels' => ['cortex.io/database' => $spec->databaseId]],
                        'topologyKey' => 'kubernetes.io/hostname',
                    ]],
                ],
            ];
        }

        $podSpec['containers'] = [$container];

        if ($this->readsVolume($spec)) {
            $podSpec['volumes'] = [[
                'name' => 'data',
                'persistentVolumeClaim' => ['claimName' => $this->claimName($spec->databaseName)],
            ]];
        }

        return $podSpec;
    }

    private function readsVolume(BackupSpec $spec): bool
    {
        return $spec->type === BackupType::Physical;
    }

    private function job(BackupSpec $spec): Manifest
    {
        $name = $this->objectName($spec);

        return new Manifest(
            apiVersion: 'batch/v1',
            kind: 'Job',
            name: $name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'batch/v1',
                'kind' => 'Job',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    // One attempt: a half-written copy retried blindly is worse
                    // than a failure the customer can see and repeat.
                    'backoffLimit' => 0,
                    'ttlSecondsAfterFinished' => 86400,
                    'template' => [
                        'metadata' => ['labels' => $this->labels($spec)],
                        'spec' => $this->podSpec($spec, $this->backupScript($spec)),
                    ],
                ],
            ],
        );
    }

    private function cronJob(BackupSpec $spec): Manifest
    {
        $name = $this->objectName($spec);

        return new Manifest(
            apiVersion: 'batch/v1',
            kind: 'CronJob',
            name: $name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'batch/v1',
                'kind' => 'CronJob',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'schedule' => $spec->schedule,
                    // Two base backups of one database at once would fight over
                    // the same volume and the same lock, so a late run waits.
                    'concurrencyPolicy' => 'Forbid',
                    'startingDeadlineSeconds' => 3600,
                    'successfulJobsHistoryLimit' => 3,
                    'failedJobsHistoryLimit' => 3,
                    'jobTemplate' => [
                        'spec' => [
                            'backoffLimit' => 0,
                            'ttlSecondsAfterFinished' => 86400,
                            'template' => [
                                'metadata' => ['labels' => $this->labels($spec)],
                                'spec' => $this->podSpec($spec, $this->backupScript($spec)),
                            ],
                        ],
                    ],
                ],
            ],
        );
    }

    private function volumeSnapshot(BackupSpec $spec): Manifest
    {
        $body = [
            'apiVersion' => 'snapshot.storage.k8s.io/v1',
            'kind' => 'VolumeSnapshot',
            'metadata' => [
                'name' => $spec->storageKey,
                'namespace' => $spec->namespace,
                'labels' => $this->labels($spec),
            ],
            'spec' => [
                'source' => ['persistentVolumeClaimName' => $this->claimName($spec->databaseName)],
            ],
        ];

        if ($spec->snapshotClass !== null) {
            $body['spec'] = ['volumeSnapshotClassName' => $spec->snapshotClass] + $body['spec'];
        }

        return new Manifest(
            apiVersion: 'snapshot.storage.k8s.io/v1',
            kind: 'VolumeSnapshot',
            name: $spec->storageKey,
            namespace: $spec->namespace,
            body: $body,
        );
    }

    /**
     * The script that takes the copy. Written as a script rather than as args
     * because every one of these is a pipe: the copy is streamed to object
     * storage, never staged on the database's own disk — a backup that needs
     * room next to the data it protects fails exactly when the data is largest.
     */
    private function backupScript(BackupSpec $spec): string
    {
        $lines = ['set -euo pipefail'];

        if ($spec->schedule !== null) {
            // Every run needs its own key, and the only clock a CronJob can
            // trust is the one in the container it just started.
            $lines[] = 'BACKUP_KEY="${BACKUP_PREFIX}/scheduled-$(date -u +%Y%m%dT%H%M%SZ).'
                .$spec->type->extension($spec->engine).'"';
        }

        $lines[] = $this->copyCommand($spec);
        // The Job's exit status says whether it worked; the termination message
        // says how big the result is. It is the only channel a finished pod has
        // back to the control plane, so the size travels on it.
        //
        // Past this line the copy is already in the bucket, so nothing here may
        // fail the run: a backup whose bytes are safe must not be reported failed
        // because its size could not be read back or the log could not be
        // written. Both steps therefore end in `|| true` — and measuring into a
        // variable first is what makes that possible, because the size inlined
        // into `echo` could never fail loudly *or* quietly report anything.
        $lines[] = 'size=$(aws --endpoint-url "$S3_ENDPOINT" s3 ls "s3://$S3_BUCKET/$BACKUP_KEY" '
            .'--recursive --summarize | awk \'/Total Size/ {print $3}\') || true';
        // Empty rather than zero when it could not be read: the control plane
        // parses a number out of this and leaves the size unknown when there is
        // none, and "unknown" is true where "0 bytes" would not be.
        $lines[] = 'echo "size=${size:-}" > /dev/termination-log || true';

        if ($spec->schedule !== null) {
            $lines[] = $this->retentionCommand();
        }

        return implode("\n", $lines)."\n";
    }

    private function copyCommand(BackupSpec $spec): string
    {
        $port = $spec->engine->defaultPort();
        $host = $spec->databaseName;

        return match ([$spec->type, $spec->engine]) {
            // XtraBackup reads the datadir but still takes its consistency lock
            // over the protocol, so this connection is expected — a scheduled
            // base backup is a deliberate wake, not an accidental one.
            [BackupType::Physical, DatabaseEngine::Percona] => 'xtrabackup --backup --stream=xbstream'
                .' '.self::NO_VERSION_CHECK
                .' --target-dir='.self::XTRABACKUP_SCRATCH
                .' --datadir='.self::DATA_PATH['percona']
                ." --host={$host} --port={$port} --user=root --password=\"\$MYSQL_ROOT_PASSWORD\""
                .' | xbcloud put --storage=s3 --s3-endpoint="$S3_ENDPOINT" --s3-bucket="$S3_BUCKET"'
                .' --s3-region="$S3_REGION" --parallel=4 "$BACKUP_KEY"',
            [BackupType::Logical, DatabaseEngine::Percona] => 'mysqldump --single-transaction --routines --triggers'
                ." --events --all-databases --host={$host} --port={$port} --user=root"
                .' --password="$MYSQL_ROOT_PASSWORD"'
                .' | gzip -c'
                .' | aws --endpoint-url "$S3_ENDPOINT" s3 cp - "s3://$S3_BUCKET/$BACKUP_KEY"',
            // Valkey needs none of XtraBackup: its own RDB file on the volume
            // already is the byte-level copy, so a physical backup is that file.
            //
            // It has to be asked for first, though. The engine runs with the AOF
            // on — for Valkey the volume is the backup, so persistence is not
            // optional — and an append-only engine writes no RDB unless told to:
            // a fresh volume has an `appendonlydir` and no `dump.rdb` at all, so
            // copying that path blind is a backup that fails on a healthy
            // database, or worse, silently ships a stale file from days ago.
            // BGSAVE is a deliberate wake for the same reason XtraBackup's lock
            // is, and the copy waits for it rather than racing it.
            [BackupType::Physical, DatabaseEngine::Valkey] => implode("\n", [
                "valkey-cli -h {$host} -p {$port} BGSAVE",
                'for _ in $(seq 1 600); do',
                "  if [ \"\$(valkey-cli -h {$host} -p {$port} INFO persistence | tr -d '\\r'"
                    ." | awk -F: '/^rdb_bgsave_in_progress:/{print \$2}')\" = \"0\" ]; then break; fi",
                '  sleep 1',
                'done',
                "[ \"\$(valkey-cli -h {$host} -p {$port} INFO persistence | tr -d '\\r'"
                    ." | awk -F: '/^rdb_last_bgsave_status:/{print \$2}')\" = \"ok\" ]",
                'aws --endpoint-url "$S3_ENDPOINT" s3 cp '
                    .self::DATA_PATH['valkey'].'/dump.rdb "s3://$S3_BUCKET/$BACKUP_KEY"',
            ]),
            default => throw new \LogicException(
                "No backup command for a {$spec->type->value} backup of {$spec->engine->value}.",
            ),
        };
    }

    /**
     * Retention is enforced by the same component that writes, so nothing
     * accumulates silently. Only scheduled copies are pruned: a backup a person
     * asked for is deleted by that person, not by a clock.
     */
    private function retentionCommand(): string
    {
        return 'cutoff=$(date -u -d "@$(( $(date -u +%s) - ${RETAIN_DAYS} * 86400 ))" +%Y-%m-%dT%H:%M:%SZ)'."\n"
            .'aws --endpoint-url "$S3_ENDPOINT" s3api list-objects-v2 --bucket "$S3_BUCKET"'
            .' --prefix "${BACKUP_PREFIX}/scheduled-" --query "Contents[?LastModified<\'$cutoff\'].Key"'
            .' --output text | tr \'\t\' \'\n\' | while read -r stale; do'."\n"
            .'  if [ -n "$stale" ] && [ "$stale" != "None" ]; then'."\n"
            .'    aws --endpoint-url "$S3_ENDPOINT" s3api delete-object --bucket "$S3_BUCKET" --key "$stale"'."\n"
            .'  fi'."\n"
            .'done';
    }

    /**
     * The restore runs the same build as the database it restores into: a backup
     * prepared by a different engine version is not reliably readable.
     */
    private function restoreImage(DatabaseEngine $engine, string $version): string
    {
        return $this->target->backups->image($engine, $version);
    }

    /**
     * Loading a copy into a brand-new volume. Every branch begins by refusing to
     * touch a datadir that already holds data: an init container runs on every
     * restart, and a restore that fires a second time would destroy exactly the
     * database it created.
     */
    private function restoreScript(DatabaseEngine $engine): string
    {
        if ($engine === DatabaseEngine::Valkey) {
            $data = self::DATA_PATH['valkey'];
            $socket = '/tmp/restore.sock';

            return implode("\n", [
                'set -euo pipefail',
                // Either shape counts as data: a database that has been serving
                // has an AOF directory and may have no RDB at all.
                'if [ -d '.$data.'/appendonlydir ] || [ -f '.$data.'/dump.rdb ]; then',
                '  echo "data present, leaving it alone"',
                '  exit 0',
                'fi',
                'aws --endpoint-url "$S3_ENDPOINT" s3 cp "s3://$S3_BUCKET/$BACKUP_KEY" '.$data.'/dump.rdb',
                // Dropping the RDB on the volume is not enough, and the way it
                // fails is the dangerous kind: the engine runs with the AOF on,
                // an AOF-enabled Valkey with no AOF directory starts *empty*
                // rather than falling back to the RDB, and it then writes an
                // empty AOF over the top. The restore reports success and the
                // customer gets an empty database.
                //
                // So the copy is loaded by a server that has the AOF off, and
                // turning it on is what materialises the dataset into the AOF
                // the engine will actually read. Nothing here is reachable from
                // outside the pod: no TCP port, a unix socket only.
                'valkey-server --dir '.$data.' --appendonly no --port 0'
                    .' --unixsocket '.$socket.' --daemonize yes',
                'for _ in $(seq 1 60); do',
                '  if valkey-cli -s '.$socket.' PING >/dev/null 2>&1; then break; fi',
                '  sleep 1',
                'done',
                'valkey-cli -s '.$socket.' CONFIG SET appendonly yes',
                'for _ in $(seq 1 600); do',
                '  if [ "$(valkey-cli -s '.$socket.' INFO persistence | tr -d \'\r\''
                    .' | awk -F: \'/^aof_rewrite_in_progress:/{print $2}\')" = "0" ]; then break; fi',
                '  sleep 1',
                'done',
                // A rewrite that did not finish cleanly must fail the restore
                // rather than leave a half-written AOF for the engine to load.
                '[ "$(valkey-cli -s '.$socket.' INFO persistence | tr -d \'\r\''
                    .' | awk -F: \'/^aof_last_bgrewrite_status:/{print $2}\')" = "ok" ]',
                // The AOF is complete by now, so nothing needs saving on the way
                // out; the client also sees the connection close rather than a
                // reply, which is not a failure.
                'valkey-cli -s '.$socket.' SHUTDOWN NOSAVE || true',
            ])."\n";
        }

        $datadir = self::DATA_PATH['percona'];

        return implode("\n", [
            'set -euo pipefail',
            'if [ -f '.$datadir.'/ibdata1 ]; then',
            '  echo "data present, leaving it alone"',
            '  exit 0',
            'fi',
            // Extracted straight into the datadir and prepared in place. The
            // obvious shape — unpack to a staging directory and --move-back —
            // cannot run here twice over: the init container is the engine's own
            // unprivileged user, so it cannot create a directory at the
            // container root, and staging would need room for a second copy of
            // the dataset on ephemeral storage rather than on the volume the
            // customer sized for it.
            'xbcloud get --storage=s3 --s3-endpoint="$S3_ENDPOINT" --s3-bucket="$S3_BUCKET"'
                .' --s3-region="$S3_REGION" "$BACKUP_KEY" | xbstream -x -C '.$datadir,
            'xtrabackup --prepare '.self::NO_VERSION_CHECK.' --target-dir='.$datadir,
            // The restored datadir carries the SOURCE database's grants, and this
            // is a different database with credentials of its own. Without this
            // the copy answers only to a password Cortex never shows the
            // customer, and the one it does show is refused. The engine's own
            // entrypoint cannot fix it either: it initialises credentials only
            // when the datadir is empty, and by now it is not.
            //
            // Done with --init-file rather than a --skip-grant-tables window,
            // for two reasons. There is no moment where the server accepts
            // anything at all; and the reset needs FLUSH PRIVILEGES to take
            // effect, which itself ends skip-grant-tables mode — so the very
            // next command, the shutdown, is refused. The value is written to a
            // file in this container rather than onto a command line, where
            // every process on the node could read it.
            'umask 077',
            'printf \'%s\n\''
                .' "ALTER USER IF EXISTS \'root\'@\'localhost\' IDENTIFIED BY \'${MYSQL_ROOT_PASSWORD}\';"'
                .' "ALTER USER IF EXISTS \'root\'@\'%\' IDENTIFIED BY \'${MYSQL_ROOT_PASSWORD}\';"'
                .' > /tmp/restore-credentials.sql',
            // --daemonize returns only once the server is ready, and the init
            // file has run by then, so there is nothing to poll for.
            'mysqld --datadir='.$datadir.' --skip-networking --socket=/tmp/restore.sock'
                .' --pid-file=/tmp/restore.pid --init-file=/tmp/restore-credentials.sql --daemonize',
            'rm -f /tmp/restore-credentials.sql',
            'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin --socket=/tmp/restore.sock --user=root shutdown',
        ])."\n";
    }
}
