<?php

declare(strict_types=1);

use Cbox\Platform\Database\BackupStorage;
use Cbox\Platform\Database\BackupType;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Database\RestoreSpec;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Runtime\ZeropodSnapshotRuntime;

/** Resolve the compiler only AFTER the fixture has bound the cell's runtime. */
function compileDatabase(DatabaseSpec $spec): ManifestSet
{
    return test()->compileDatabase($spec);
}

function engineSpec(
    DatabaseEngine $engine = DatabaseEngine::Valkey,
    bool $scaleToZero = true,
    ?string $runtimeClass = 'zeropod',
    bool $suspended = false,
): DatabaseSpec {
    test()->compilingWithSnapshotRuntime($runtimeClass === null
        ? null
        : new ZeropodSnapshotRuntime($runtimeClass));

    return new DatabaseSpec(
        databaseId: '01J0000000000000000000DB02',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'cache',
        engine: $engine,
        version: $engine->defaultVersion(),
        instances: 1,
        storageSize: '5Gi',
        scaleToZero: $scaleToZero,
        idleTimeoutSeconds: 60,
        suspended: $suspended,
        password: $engine->needsPassword() ? 'fixed-password-for-the-golden' : null,
    );
}

/** A Percona database being brought into being from a copy. */
function restoreSpec(): DatabaseSpec
{
    test()->compilingWithSnapshotRuntime(null);

    return new DatabaseSpec(
        databaseId: '01J0000000000000000000DB03',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'restored',
        engine: DatabaseEngine::Percona,
        version: '8.0',
        instances: 1,
        storageSize: '5Gi',
        password: 'fixed-password-for-the-golden',
        restore: new RestoreSpec(
            backupId: '01J0000000000000000000BK01',
            type: BackupType::Physical,
            storageKey: 'org/db/2026-07-25T00-00-00Z',
            storage: new BackupStorage(
                bucket: 'cortex-backups',
                endpoint: 'https://s3.example.com',
                region: 'eu-central-1',
                accessKeyId: 'AKIAFIXTURE',
                secretAccessKey: 'fixture-secret',
                retainDays: 30,
            ),
        ),
    );
}

it('matches the Valkey golden manifest set byte for byte', function (): void {
    $yaml = compileDatabase(engineSpec())->toYaml();
    $golden = test()->golden('database-valkey');

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($golden)) {
        file_put_contents($golden, $yaml);
    }

    expect($yaml)->toBe(file_get_contents($golden));
});

it('matches the Percona golden manifest set byte for byte', function (): void {
    $yaml = compileDatabase(engineSpec(DatabaseEngine::Percona))->toYaml();
    $golden = test()->golden('database-percona');

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($golden)) {
        file_put_contents($golden, $yaml);
    }

    expect($yaml)->toBe(file_get_contents($golden));
});

it('routes each engine to the right shape', function (): void {
    $kinds = static fn (DatabaseEngine $e): array => array_map(
        static fn ($m): string => $m->kind,
        compileDatabase(engineSpec($e))->manifests,
    );

    // Postgres goes to the operator; the others are scheduled by Cortex.
    // The Namespace leads: a database deployed into an environment where no
    // service had ever shipped used to fail on a namespace nothing created.
    expect($kinds(DatabaseEngine::Valkey))->toBe(['Namespace', 'StatefulSet', 'Service'])
        ->and($kinds(DatabaseEngine::Percona))->toBe(['Namespace', 'Secret', 'StatefulSet', 'Service'])
        ->and($kinds(DatabaseEngine::Postgres))->toBe(['Namespace', 'Cluster']);
});

it('places a scale-to-zero engine on the snapshotting runtime', function (): void {
    $set = compileDatabase(engineSpec());
    $sts = collect($set->manifests)->firstWhere('kind', 'StatefulSet');

    /** @var array<string, mixed> $template */
    $template = $sts->body['spec']['template'];

    expect($template['spec']['runtimeClassName'])->toBe('zeropod')
        ->and($template['metadata']['annotations'])->toBe([
            'zeropod.ctrox.dev/container-names' => 'cache',
            'zeropod.ctrox.dev/ports-map' => 'cache=6379',
            'zeropod.ctrox.dev/scaledown-duration' => '60s',
        ])
        // The pod stays scheduled: the runtime checkpoints the process inside it.
        ->and($sts->body['spec']['replicas'])->toBe(1)
        ->and($sts->body['spec']['volumeClaimTemplates'][0]['spec']['resources']['requests']['storage'])->toBe('5Gi');
});

it('stays off the snapshotting runtime when the cell offers none, or the customer did not opt in', function (): void {
    $noRuntime = compileDatabase(engineSpec(runtimeClass: null));
    $notOptedIn = compileDatabase(engineSpec(scaleToZero: false));

    foreach ([$noRuntime, $notOptedIn] as $set) {
        $template = collect($set->manifests)->firstWhere('kind', 'StatefulSet')->body['spec']['template'];

        expect($template['spec'])->not->toHaveKey('runtimeClassName')
            ->and($template)->not->toHaveKey('annotations');
    }
});

it('suspends to no pod at all, keeping the volume claim', function (): void {
    $sts = collect(compileDatabase(engineSpec(suspended: true))->manifests)
        ->firstWhere('kind', 'StatefulSet');

    expect($sts->body['spec']['replicas'])->toBe(0)
        // A suspend must never take the storage with it.
        ->and($sts->body['spec']['volumeClaimTemplates'][0]['spec']['resources']['requests']['storage'])->toBe('5Gi')
        // Suspended means stopped, so no idle-checkpoint contract either.
        ->and($sts->body['spec']['template']['spec'])->not->toHaveKey('runtimeClassName');
});

it('gives Percona its password through a Secret, never inline in the pod spec', function (): void {
    $set = compileDatabase(engineSpec(DatabaseEngine::Percona));

    $secret = collect($set->manifests)->firstWhere('kind', 'Secret');
    $sts = collect($set->manifests)->firstWhere('kind', 'StatefulSet');
    /** @var array<int, array<string, mixed>> $env */
    $env = $sts->body['spec']['template']['spec']['containers'][0]['env'];
    // Found by name, not position: other env may legitimately precede it.
    $password = collect($env)->firstWhere('name', 'MYSQL_ROOT_PASSWORD');

    expect($secret->body['stringData']['password'])->toBe('fixed-password-for-the-golden')
        ->and($password)->not->toBeNull()
        ->and($password['valueFrom']['secretKeyRef'])->toBe(['name' => 'cache-credentials', 'key' => 'password'])
        ->and($password)->not->toHaveKey('value');
});

it('tells a sleeping engine that it sleeps, so the supervisor tunes it to wake fast', function (): void {
    $warm = compileDatabase(engineSpec());
    $resident = compileDatabase(engineSpec(scaleToZero: false));

    $envOf = static fn ($set): array => collect(
        collect($set->manifests)->firstWhere('kind', 'StatefulSet')
            ->body['spec']['template']['spec']['containers'][0]['env'] ?? []
    )->pluck('value', 'name')->all();

    // The buffer pool is what a checkpoint writes and a wake reads back, so the
    // engine has to know which it is.
    expect($envOf($warm)['CBOX_WAKE_MODE'])->toBe('warm')
        ->and($envOf($resident))->not->toHaveKey('CBOX_WAKE_MODE');
});

it('matches the restored-database golden manifest set byte for byte', function (): void {
    // A restore is a database creation carrying the copy to start from, so the
    // init container that loads it is part of the database's own compiled shape
    // — and the thing most worth pinning, because it runs once, before anyone
    // can look at the result.
    $yaml = compileDatabase(restoreSpec())->toYaml();
    $golden = test()->golden('database-percona-restore');

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($golden)) {
        file_put_contents($golden, $yaml);
    }

    expect($yaml)->toBe(file_get_contents($golden));
});

it('loads the copy before the engine starts, never after', function (): void {
    $statefulSet = collect(compileDatabase(restoreSpec())->manifests)->firstWhere('kind', 'StatefulSet');
    $podSpec = $statefulSet->body['spec']['template']['spec'];

    // An init container is the whole point: the instance's first state is the
    // restored one, rather than a fresh database written over afterwards.
    expect($podSpec['initContainers'][0]['name'])->toBe('restore')
        ->and($podSpec['initContainers'][0]['volumeMounts'][0]['mountPath'])->toBe('/var/lib/mysql');
});
