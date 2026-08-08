<?php

declare(strict_types=1);

use Cbox\Platform\Database\BackupStorage;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;

function dbSpec(?string $schedule = '0 2 * * *'): DatabaseSpec
{
    return new DatabaseSpec(
        databaseId: '01J0000000000000000000DB01',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        name: 'primary',
        engine: DatabaseEngine::Postgres,
        version: '16',
        instances: 3,
        storageSize: '20Gi',
        backupSchedule: $schedule,
        // Resolved by DatabaseSpec::fromModel in production; supplied here so
        // the compiler stays what its golden test assumes — a pure function of
        // its input that never reaches for Eloquent or config.
        backupStorage: $schedule === null ? null : new BackupStorage(
            bucket: 'cortex-backups',
            endpoint: 'https://fsn1.your-objectstorage.com',
            region: 'fsn1',
            accessKeyId: 'FIXTURE-ACCESS-KEY',
            secretAccessKey: 'FIXTURE-SECRET-KEY',
            retainDays: 30,
        ),
    );
}

it('matches the golden CNPG manifest set byte for byte', function (): void {
    $yaml = test()->compileDatabase(dbSpec())->toYaml();
    $golden = test()->golden('database-cnpg');

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($golden)) {
        file_put_contents($golden, $yaml);
    }

    expect($yaml)->toBe(file_get_contents($golden));
});

it('is deterministic', function (): void {
    $a = test()->compileDatabase(dbSpec());
    $b = test()->compileDatabase(dbSpec());

    expect($a->toYaml())->toBe($b->toYaml())->and($a->hashes())->toBe($b->hashes());
});

it('emits a Cluster always, and a ScheduledBackup only with a schedule', function (): void {
    $withBackup = test()->compileDatabase(dbSpec());
    $noBackup = test()->compileDatabase(dbSpec(schedule: null));

    $kinds = fn ($set): array => array_map(fn ($m): string => $m->kind, $set->manifests);

    // The Secret comes first, and it is the object whose absence made every
    // scheduled Postgres backup a no-op: CNPG resolves the barmanObjectStore
    // credential as soon as the Cluster lands.
    // The Namespace leads both: a database deployed into an environment where
    // no service had ever shipped used to fail on a namespace nothing created.
    expect($kinds($withBackup))->toBe(['Namespace', 'Secret', 'Cluster', 'ScheduledBackup'])
        ->and($kinds($noBackup))->toBe(['Namespace', 'Cluster']);
});

it('gives CNPG somewhere to write, or it will not back up at all', function (): void {
    $set = test()->compileDatabase(dbSpec());

    /** @var array<string, mixed> $cluster */
    $cluster = collect($set->manifests)->firstWhere('kind', 'Cluster')->body['spec'];

    // Cortex compiled a ScheduledBackup and no backup section. CNPG accepted
    // the schedule and refused every run with "cannot proceed with the backup
    // as the cluster has no backup section" — so a database that reported a
    // backup schedule in the UI had never once been backed up.
    expect($cluster)->toHaveKey('backup');

    /** @var array<string, mixed> $store */
    $store = $cluster['backup']['barmanObjectStore'];

    expect($store['destinationPath'])->toStartWith('s3://cortex-backups/')
        ->and($store['endpointURL'])->toBe('https://fsn1.your-objectstorage.com')
        ->and($store['s3Credentials']['accessKeyId']['name'])->toBe('primary-backup-credentials')
        ->and($store['s3Credentials']['secretAccessKey']['key'])->toBe('secret-access-key')
        // WAL archiving is the difference between a recovery point and a
        // nightly snapshot; without it the best restore is the last base backup.
        ->and($store['wal']['compression'])->toBe('gzip')
        ->and($cluster['backup']['retentionPolicy'])->toBe('30d');

    // And the credential the reference points at is really in the set, in the
    // database's own namespace — a dangling secretKeyRef fails the same way
    // as no backup section, just later.
    $secret = collect($set->manifests)->firstWhere('kind', 'Secret');

    expect($secret->name)->toBe('primary-backup-credentials')
        ->and($secret->namespace)->toBe('cx-production-db9k2');
});

it('does not compile a backup section for a database with no schedule', function (): void {
    $set = test()->compileDatabase(dbSpec(schedule: null));

    // No schedule means no bucket is required, and demanding one would refuse
    // to deploy a database that never intended to write a backup.
    expect(collect($set->manifests)->firstWhere('kind', 'Cluster')->body['spec'])
        ->not->toHaveKey('backup');
});

it('labels every object cortex-managed and sizes the cluster from intent', function (): void {
    $set = test()->compileDatabase(dbSpec());
    // By kind, not by position: the Secret now leads the set, and an index
    // here would silently start asserting about the wrong object.
    $cluster = collect($set->manifests)->firstWhere('kind', 'Cluster');

    /** @var array<string, mixed> $metadata */
    $metadata = $cluster->body['metadata'];
    /** @var array<string, string> $labels */
    $labels = $metadata['labels'];
    /** @var array<string, mixed> $spec */
    $spec = $cluster->body['spec'];

    expect($labels['cortex.io/managed'])->toBe('true')
        ->and($labels['cortex.io/database'])->toBe('01J0000000000000000000DB01')
        ->and($spec['instances'])->toBe(3)
        ->and($spec['storage'])->toBe(['size' => '20Gi'])
        ->and($spec['imageName'])->toContain(':16');
});

it('hibernates a suspended database and keeps the annotation explicit when running', function (): void {
    $running = test()->compileDatabase(dbSpec());
    $suspended = test()->compileDatabase(new DatabaseSpec(
        databaseId: '01J0000000000000000000DB01',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'primary',
        engine: DatabaseEngine::Postgres,
        version: '16',
        instances: 2,
        storageSize: '20Gi',
        backupSchedule: '0 2 * * *',
        suspended: true,
    ));

    /** @var array<string, mixed> $runningMeta */
    $runningMeta = collect($running->manifests)->firstWhere('kind', 'Cluster')->body['metadata'];
    /** @var array<string, mixed> $suspendedMeta */
    $suspendedMeta = collect($suspended->manifests)->firstWhere('kind', 'Cluster')->body['metadata'];

    // Emitted either way, so resuming is a real diff the reconciler applies
    // rather than a deletion it has to infer.
    expect($runningMeta['annotations'])->toBe(['cnpg.io/hibernation' => 'off'])
        ->and($suspendedMeta['annotations'])->toBe(['cnpg.io/hibernation' => 'on']);
});

it('leaves storage and instance count untouched while suspended — only compute stops', function (): void {
    $suspended = test()->compileDatabase(new DatabaseSpec(
        databaseId: '01J0000000000000000000DB01',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'primary',
        engine: DatabaseEngine::Postgres,
        version: '16',
        instances: 2,
        storageSize: '20Gi',
        suspended: true,
    ));

    /** @var array<string, mixed> $spec */
    $spec = collect($suspended->manifests)->firstWhere('kind', 'Cluster')->body['spec'];

    expect($spec['instances'])->toBe(2)
        ->and($spec['storage']['size'])->toBe('20Gi');
});

it('refuses to place two instances of one database on the same node', function (): void {
    // CloudNativePG's default is `preferred`, which is a hint the scheduler may
    // ignore — so a customer who asked for two instances could get both on one
    // machine and lose both to one machine. That is not a degraded version of
    // what they paid for, it is none of it, and every status field says three
    // of three ready right up until the node dies.
    //
    // The cell's own datastore made this exact choice for this exact reason; a
    // customer's database had not.
    /** @var array<string, mixed> $spec */
    $spec = collect(test()->compileDatabase(dbSpec())->manifests)
        ->firstWhere('kind', 'Cluster')->body['spec'];

    expect($spec['affinity'] ?? null)->not->toBeNull()
        ->and($spec['affinity']['enablePodAntiAffinity'])->toBeTrue()
        ->and($spec['affinity']['podAntiAffinityType'])->toBe('required')
        ->and($spec['affinity']['topologyKey'])->toBe('kubernetes.io/hostname');
});

it('leaves a single-instance database schedulable', function (): void {
    // `required` on one instance has nothing to spread and would refuse to
    // schedule the database at all on a one-node cluster — turning a rule about
    // redundancy into an outage for the customers who bought none.
    $one = new DatabaseSpec(
        databaseId: '01J0000000000000000000DB02',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        name: 'solo',
        engine: DatabaseEngine::Postgres,
        version: '16',
        instances: 1,
        storageSize: '20Gi',
    );

    /** @var array<string, mixed> $spec */
    $spec = collect(test()->compileDatabase($one)->manifests)
        ->firstWhere('kind', 'Cluster')->body['spec'];

    expect($spec['affinity'] ?? null)->toBeNull();
});
