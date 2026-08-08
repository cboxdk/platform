<?php

declare(strict_types=1);

use Cbox\Platform\Compile\BackupCompiler;
use Cbox\Platform\Database\BackupSpec;
use Cbox\Platform\Database\BackupStorage;
use Cbox\Platform\Database\BackupType;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\RestoreSpec;
use Cbox\Platform\Manifest\ManifestSet;

function backupSpec(
    DatabaseEngine $engine = DatabaseEngine::Percona,
    BackupType $type = BackupType::Physical,
    ?string $schedule = null,
): BackupSpec {
    return new BackupSpec(
        backupId: '01J0000000000000000000BK01',
        organizationId: '01J0000000000000000000ORG1',
        databaseId: '01J0000000000000000000DB02',
        databaseName: 'primary',
        namespace: 'cx-production-svc9k2',
        engine: $engine,
        type: $type,
        storageKey: 'org/db/2026-07-25T00-00-00Z',
        image: $engine === DatabaseEngine::Percona ? 'percona:8.0' : 'valkey/valkey:8-alpine',
        storage: new BackupStorage(
            bucket: 'cortex-backups',
            endpoint: 'https://s3.example.com',
            region: 'eu-central-1',
            accessKeyId: 'AKIAFIXTURE',
            secretAccessKey: 'fixture-secret',
            retainDays: 30,
        ),
        schedule: $schedule,
    );
}

/** The shell script the compiled Job actually runs. */
function backupScript(ManifestSet $set): string
{
    $job = collect($set->manifests)->firstWhere('kind', 'Job');

    return $job->body['spec']['template']['spec']['containers'][0]['command'][2];
}

/** The shell script the compiled restore init container actually runs. */
function restoreScriptFor(DatabaseEngine $engine): string
{
    $restore = new RestoreSpec(
        backupId: '01J0000000000000000000BK01',
        type: BackupType::Physical,
        storageKey: 'org/db/base',
        storage: backupSpec()->storage,
    );

    return new BackupCompiler(test()->target())->restoreInitContainer($restore, $engine, '8.0', 'restored')['command'][2];
}

it('matches the Percona backup golden manifest set byte for byte', function (): void {
    $yaml = test()->compileBackup(backupSpec())->toYaml();
    $golden = test()->golden('backup-percona');

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($golden)) {
        file_put_contents($golden, $yaml);
    }

    expect($yaml)->toBe(file_get_contents($golden));
});

it('compiles a one-off backup to a Job and a schedule to a CronJob', function (): void {
    $kinds = static fn ($set): array => array_map(static fn ($m): string => $m->kind, $set->manifests);

    $oneOff = test()->compileBackup(backupSpec());
    $scheduled = new BackupCompiler(test()->target())->compileSchedule(backupSpec(schedule: '0 2 * * *'));

    expect($kinds($oneOff))->toContain('Job')
        ->and($kinds($oneOff))->not->toContain('CronJob')
        ->and($kinds($scheduled))->toContain('CronJob')
        ->and($kinds($scheduled))->not->toContain('Job');
});

it('never puts storage credentials inline in a pod spec', function (): void {
    $set = test()->compileBackup(backupSpec());

    $secret = collect($set->manifests)->firstWhere('kind', 'Secret');
    expect($secret)->not->toBeNull();

    // The secret itself carries the value; nothing else may. A credential that
    // reaches a pod spec ends up in `kubectl get` output and in every audit log.
    $rendered = collect($set->manifests)
        ->reject(fn ($m): bool => $m->kind === 'Secret')
        ->map(fn ($m): string => json_encode($m->body, JSON_THROW_ON_ERROR))
        ->implode("\n");

    expect($rendered)->not->toContain('fixture-secret')
        ->and($rendered)->not->toContain('AKIAFIXTURE');
});

it('refuses to compile a backup for an engine an operator already backs up', function (): void {
    // Postgres runs under CloudNativePG, which compiles its own ScheduledBackup.
    // Producing a second, competing backup path for it would be a silent
    // duplicate rather than a feature.
    expect(fn () => test()->compileBackup(backupSpec(DatabaseEngine::Postgres)))
        ->toThrow(LogicException::class);
});

it('backs Valkey up from its own persistence rather than reaching for XtraBackup', function (): void {
    $set = test()->compileBackup(backupSpec(DatabaseEngine::Valkey));

    $job = collect($set->manifests)->firstWhere('kind', 'Job');
    $rendered = json_encode($job->body, JSON_THROW_ON_ERROR);

    expect($rendered)->not->toContain('xtrabackup');
});

it('gives XtraBackup a writable scratch directory and keeps it off the internet', function (): void {
    $script = backupScript(test()->compileBackup(backupSpec()));

    // Without --target-dir XtraBackup writes its working files under the
    // process's working directory — `/` in a backup pod — and dies with
    // `cannot mkdir: 13 /xtrabackup_backupfiles/` before reading a byte. The
    // scratch path must not be the data volume: that is mounted read-only.
    expect($script)->toContain('--target-dir=/tmp/xtrabackup')
        ->and($script)->not->toContain('--target-dir=/var/lib/mysql')
        // The version check fetches and runs a Perl script from Percona on every
        // invocation; a backup must not need egress to the internet.
        ->and($script)->toContain('--no-version-check');
});

it('does not fail a finished backup over the size it could not report', function (): void {
    $script = backupScript(test()->compileBackup(backupSpec()));

    // Everything after the upload is reporting. The bytes are already safe, so
    // an unwritable termination log or an unreadable listing must not turn a
    // good backup into a failed one — and an unknown size is reported as
    // unknown rather than as zero.
    expect($script)->toContain('> /dev/termination-log || true')
        ->and($script)->toContain('size=${size:-}');
});

it('loads a Percona copy into the datadir the engine will read, without staging a second copy', function (): void {
    $script = restoreScriptFor(DatabaseEngine::Percona);

    // A staging directory at the container root cannot even be created: the
    // init container is the engine's own unprivileged user.
    expect($script)->not->toContain('mkdir -p /restore')
        ->and($script)->not->toContain('--move-back')
        ->and($script)->toContain('xbstream -x -C /var/lib/mysql')
        ->and($script)->toContain('xtrabackup --prepare --no-version-check --target-dir=/var/lib/mysql');
});

it('gives a restored database its own credentials rather than the source database\'s', function (): void {
    $script = restoreScriptFor(DatabaseEngine::Percona);

    // The copy carries the SOURCE's grants. Cortex publishes a freshly minted
    // password for the new database, so without this the customer is handed a
    // password the instance has never heard of.
    expect($script)->toContain('--init-file=/tmp/restore-credentials.sql')
        ->and($script)->toContain("ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED BY")
        ->and($script)->toContain("ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY")
        // Read from the environment the Secret populates, never baked in.
        ->and($script)->toContain('${MYSQL_ROOT_PASSWORD}');
});

it('refuses a point-in-time restore instead of quietly returning the base backup', function (): void {
    $restore = new RestoreSpec(
        backupId: '01J0000000000000000000BK01',
        type: BackupType::Physical,
        storageKey: 'org/db/base',
        storage: backupSpec()->storage,
        pointInTime: '2026-07-25 14:05:00',
    );

    // The binlog archiver does not exist, so a target time would find an empty
    // prefix and replay nothing — a restore that claims a moment it never
    // reached. Refusing is the honest answer until the archiver ships.
    expect(fn () => new BackupCompiler(test()->target())->restoreInitContainer($restore, DatabaseEngine::Percona, '8.0', 'restored'))
        ->toThrow(LogicException::class, BackupCompiler::PITR_UNIMPLEMENTED);
});

it('runs each engine under the shell its own image actually has', function (): void {
    $percona = test()->compileBackup(backupSpec());
    $valkey = test()->compileBackup(backupSpec(DatabaseEngine::Valkey));

    // The Valkey image is Alpine and carries no bash — a /bin/bash there is a
    // container that never starts, so every Valkey backup would have failed.
    expect(collect($percona->manifests)->firstWhere('kind', 'Job')
        ->body['spec']['template']['spec']['containers'][0]['command'][0])->toBe('/bin/bash')
        ->and(collect($valkey->manifests)->firstWhere('kind', 'Job')
            ->body['spec']['template']['spec']['containers'][0]['command'][0])->toBe('/bin/sh');
});

it('asks Valkey to write an RDB before copying one', function (): void {
    $script = backupScript(test()->compileBackup(backupSpec(DatabaseEngine::Valkey)));

    // The engine runs with the AOF on, and an append-only engine writes no RDB
    // unless told to: a fresh volume has an appendonlydir and no dump.rdb at
    // all, so copying that path blind fails on a healthy database — or ships a
    // file from days ago.
    expect($script)->toContain('BGSAVE')
        ->and($script)->toContain('rdb_bgsave_in_progress')
        ->and($script)->toContain('rdb_last_bgsave_status')
        ->and($script)->toContain('s3 cp /data/dump.rdb');
});

it('turns a restored Valkey RDB into the AOF the engine will actually read', function (): void {
    $script = restoreScriptFor(DatabaseEngine::Valkey);

    // An AOF-enabled Valkey with no AOF directory starts EMPTY rather than
    // falling back to the RDB, and then writes an empty AOF over the top: the
    // restore reports success and the customer gets an empty database.
    expect($script)->toContain('valkey-server --dir /data --appendonly no')
        ->and($script)->toContain('CONFIG SET appendonly yes')
        ->and($script)->toContain('aof_last_bgrewrite_status')
        // A database that has been serving has an AOF and may have no RDB.
        ->and($script)->toContain('if [ -d /data/appendonlydir ] || [ -f /data/dump.rdb ]; then');
});
