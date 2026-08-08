<?php

declare(strict_types=1);

use Cbox\Platform\Database\BackupStorage;
use Cbox\Platform\Database\BackupType;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Database\RestoreSpec;

/**
 * Restoring Postgres — the shape proven against the live tenant.
 *
 * Every assertion here corresponds to something that FAILED during the drill in
 * docs/restore-drill.md. Three of the four attempts produced a cluster that
 * looked correct and could not read a single byte, so this is asserted at the
 * level of the exact fields, not "a recovery section exists".
 */
function restoreStorage(): BackupStorage
{
    return new BackupStorage(
        bucket: 'cortex-backups',
        endpoint: 'http://minio.obj.svc:9000',
        accessKeyId: 'ak',
        secretAccessKey: 'sk',
        region: 'fsn1',
        retainDays: 14,
    );
}

function restoreSpecFor(string $sourceName = 'sched'): RestoreSpec
{
    return new RestoreSpec(
        backupId: '01J00000000000000000BKP1',
        type: BackupType::Physical,
        storageKey: 'org/db/bkp',
        storage: restoreStorage(),
        pointInTime: null,
        sourceName: $sourceName,
    );
}

function restoredDatabaseSpec(?string $schedule = null, string $version = '16'): DatabaseSpec
{
    return new DatabaseSpec(
        databaseId: '01J00000000000000000DB01',
        organizationId: 'org-restore',
        namespace: 'cx-prod-abc123',
        name: 'sched-restored',
        engine: DatabaseEngine::Postgres,
        version: $version,
        instances: 1,
        storageSize: '10Gi',
        backupSchedule: $schedule,
        restore: restoreSpecFor(),
        backupStorage: $schedule === null ? null : restoreStorage(),
    );
}

/**
 * @return array<string, mixed>
 */
function restoredClusterSpec(?string $schedule = null, string $version = '16'): array
{
    $set = test()->compileDatabase(restoredDatabaseSpec($schedule, $version));

    foreach ($set->manifests as $manifest) {
        if ($manifest->kind === 'Cluster') {
            /** @var array<string, mixed> $spec */
            $spec = $manifest->body['spec'];

            return $spec;
        }
    }

    throw new RuntimeException('No Cluster was compiled.');
}

it('compiles a restore instead of refusing it', function (): void {
    // It used to throw a LogicException here — which broke the product's Restore
    // control for the default engine, and did so at DEPLOY time, after the
    // action had already created the database row.
    $set = test()->compileDatabase(restoredDatabaseSpec());

    $kinds = array_map(fn ($m): string => $m->kind, $set->manifests);

    expect($kinds)->toContain('Cluster')
        ->and($kinds)->toContain('Namespace')
        ->and($kinds)->toContain('Secret');
});

it('sets serverName to the SOURCE database, which is the line the drill turned on', function (): void {
    $spec = restoredClusterSpec();

    /** @var array<string, mixed> $external */
    $external = $spec['externalClusters'][0];
    /** @var array<string, mixed> $store */
    $store = $external['barmanObjectStore'];

    // Asserted as a key first: without this, dropping the line makes the test
    // ERROR on a missing index rather than fail with a readable message, and a
    // regression here should say what is missing, not just go red.
    expect($store)->toHaveKey('serverName');

    // Without serverName, CNPG uses the externalCluster's NAME as the barman
    // server name and looks under a path nothing ever wrote — failing with a
    // flat "no target backup found", which reads as the backups being gone.
    expect($store['serverName'])->toBe('sched')
        ->and($store['destinationPath'])->toContain('/sched')
        ->and($store['destinationPath'])->not->toContain('sched-restored');
});

it('points the recovery bootstrap at the external cluster it declares', function (): void {
    $spec = restoredClusterSpec();

    /** @var array<string, mixed> $external */
    $external = $spec['externalClusters'][0];

    // A source naming an externalCluster that does not exist is accepted by the
    // API server and fails only at bootstrap.
    expect($spec['bootstrap']['recovery']['source'])->toBe($external['name'])
        ->and($external['name'])->toBe('sched-archive');
});

it('pins the image, because a different barman cannot read the archive', function (): void {
    // Measured: an unpinned recovery cluster inherits the operator default,
    // ships a different barman, and dies with "'BackupInfo' object has no
    // attribute 'encryption'" before touching a data file.
    expect(restoredClusterSpec()['imageName'])->toBe('ghcr.io/cloudnative-pg/postgresql:16')
        ->and(restoredClusterSpec(null, '17')['imageName'])->toBe('ghcr.io/cloudnative-pg/postgresql:17');
});

it('reads the archive under the restored database\'s own credential secret', function (): void {
    $spec = restoredClusterSpec();

    /** @var array<string, mixed> $store */
    $store = $spec['externalClusters'][0]['barmanObjectStore'];

    // Named for the new database, not the source: the compiler names every
    // secret after the database it belongs to, and the source's may not outlive
    // it. The compiled Secret above is what supplies it.
    expect($store['s3Credentials']['accessKeyId']['name'])->toBe('sched-restored-backup-credentials');
});

it('does not schedule backups for a restore that asked for none', function (): void {
    $kinds = array_map(
        fn ($m): string => $m->kind,
        test()->compileDatabase(restoredDatabaseSpec())->manifests,
    );

    expect($kinds)->not->toContain('ScheduledBackup');
});

it('keeps taking its own backups when the restore asked for a schedule', function (): void {
    $set = test()->compileDatabase(restoredDatabaseSpec('0 2 * * *'));
    $kinds = array_map(fn ($m): string => $m->kind, $set->manifests);

    expect($kinds)->toContain('ScheduledBackup');

    // And it writes to its OWN path — a restored database that archived into
    // the path it was recovered from would corrupt the source's history, which
    // is the failure `docs/stale-live-objects.md` describes from the other end.
    $spec = restoredClusterSpec('0 2 * * *');

    expect($spec['backup']['barmanObjectStore']['destinationPath'])->toContain('/sched-restored')
        ->and($spec['externalClusters'][0]['barmanObjectStore']['destinationPath'])->toContain('/sched');
});

it('refuses to compile a restore it cannot read, rather than starting empty', function (): void {
    // The original refusal was right about this danger: a cluster compiled
    // without a recovery section comes up EMPTY where data was expected. That
    // remains refused — only the blanket refusal is gone.
    $spec = new DatabaseSpec(
        databaseId: '01J00000000000000000DB01',
        organizationId: 'org-restore',
        namespace: 'cx-prod-abc123',
        name: 'sched-restored',
        engine: DatabaseEngine::Postgres,
        version: '16',
        instances: 1,
        storageSize: '10Gi',
        restore: new RestoreSpec(
            backupId: '01J00000000000000000BKP1',
            type: BackupType::Physical,
            storageKey: 'org/db/bkp',
            storage: null,
            pointInTime: null,
            sourceName: '',
        ),
    );

    expect(fn () => test()->compileDatabase($spec))
        ->toThrow(LogicException::class, 'cannot be compiled without both');
});

it('is deterministic', function (): void {
    $a = test()->compileDatabase(restoredDatabaseSpec());
    $b = test()->compileDatabase(restoredDatabaseSpec());

    expect($a->toYaml())->toBe($b->toYaml())->and($a->hashes())->toBe($b->hashes());
});
