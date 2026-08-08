<?php

declare(strict_types=1);

use Cbox\Platform\Database\BackupStorage;
use Cbox\Platform\Database\BackupType;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Database\RestoreSpec;

/**
 * Point-in-time recovery, for the engine that can actually do it.
 *
 * The refusal used to be blanket, and its stated reason — "no binlog archiver
 * ships yet" — was never true of Postgres: CloudNativePG archives WAL
 * continuously, and WAL is the replay log. It was blocking a capability that
 * existed. Every other engine is still refused, because for those the reason
 * holds.
 */
function pitrSpec(?string $at): DatabaseSpec
{
    $storage = new BackupStorage(
        bucket: 'cortex-backups', endpoint: 'https://s3.example.com', region: 'eu',
        accessKeyId: 'ak', secretAccessKey: 'sk', retainDays: 14,
    );

    return new DatabaseSpec(
        databaseId: '01J0000000000000000000DB01',
        organizationId: 'org-pitr',
        namespace: 'cx-prod-abc',
        name: 'primary-restored',
        engine: DatabaseEngine::Postgres,
        version: '16',
        instances: 1,
        storageSize: '10Gi',
        restore: new RestoreSpec(
            backupId: 'bkp-1', type: BackupType::Physical, storageKey: 'k',
            storage: $storage, pointInTime: $at, sourceName: 'primary',
        ),
    );
}

/**
 * @return array<string, mixed>
 */
function pitrCluster(?string $at): array
{
    foreach (test()->compileDatabase(pitrSpec($at))->manifests as $manifest) {
        if ($manifest->kind === 'Cluster') {
            /** @var array<string, mixed> $spec */
            $spec = $manifest->body['spec'];

            return $spec;
        }
    }

    throw new RuntimeException('No Cluster compiled.');
}

it('replays to the target time when one is asked for', function (): void {
    $recovery = pitrCluster('2026-07-31 14:05:00')['bootstrap']['recovery'];

    // Without this CNPG recovers to the END of the archive, which is a
    // different database than the one the customer asked for — and it would
    // report success while doing it.
    expect($recovery)->toHaveKey('recoveryTarget')
        ->and($recovery['recoveryTarget']['targetTime'])->toBe('2026-07-31 14:05:00');
});

it('omits the target entirely when none was asked for', function (): void {
    // An empty recoveryTarget is not the same as none: it constrains a recovery
    // that was meant to run to the latest point available.
    expect(pitrCluster(null)['bootstrap']['recovery'])->not->toHaveKey('recoveryTarget');
});

it('still names the source archive either way', function (): void {
    expect(pitrCluster('2026-07-31 14:05:00')['bootstrap']['recovery']['source'])->toBe('primary-archive')
        ->and(pitrCluster(null)['bootstrap']['recovery']['source'])->toBe('primary-archive');
});

it('only Postgres replays from a write-ahead log', function (): void {
    expect(DatabaseEngine::Postgres->replaysFromWal())->toBeTrue();

    foreach (DatabaseEngine::cases() as $engine) {
        if ($engine !== DatabaseEngine::Postgres) {
            expect($engine->replaysFromWal())->toBeFalse();
        }
    }
});
