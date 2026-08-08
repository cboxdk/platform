<?php

declare(strict_types=1);

use Cbox\Platform\Binding\ConnectionSource;
use Cbox\Platform\Database\BackupType;
use Cbox\Platform\Database\DatabaseEngine;

/**
 * Which copy of which engine is taken by what, and which combinations are
 * refused.
 *
 * All decision logic on a shipped enum, and coverage found it at 21% — the
 * refusals in particular had never been exercised, and a refusal that turns out
 * to be reachable by accident is how a backup gets taken with the wrong tool.
 */
it('names the tool for every combination that has one', function (): void {
    expect(BackupType::Physical->tool(DatabaseEngine::Percona))->toBe('xtrabackup')
        ->and(BackupType::Logical->tool(DatabaseEngine::Percona))->toBe('mysqldump')
        ->and(BackupType::Physical->tool(DatabaseEngine::Valkey))->toBe('rdb-copy')
        ->and(BackupType::Snapshot->tool(DatabaseEngine::Percona))->toBe('csi-snapshot')
        ->and(BackupType::Snapshot->tool(DatabaseEngine::Valkey))->toBe('csi-snapshot');
});

it('refuses a combination rather than approximating it with another tool', function (): void {
    // A logical dump of Valkey has no tool. Silently taking a physical copy
    // instead would produce a backup labelled as something it is not.
    expect(fn () => BackupType::Logical->tool(DatabaseEngine::Valkey))
        ->toThrow(InvalidArgumentException::class, 'has no tool');

    // And Postgres never reaches here at all: CloudNativePG owns that data path,
    // so a second mechanism for the same database is refused by name.
    foreach (BackupType::cases() as $type) {
        expect(fn () => $type->tool(DatabaseEngine::Postgres))
            ->toThrow(InvalidArgumentException::class, 'CloudNativePG owns Postgres backups');
    }
});

it('says which engines it supports, and Postgres is not one of them', function (): void {
    expect(BackupType::Physical->supports(DatabaseEngine::Percona))->toBeTrue()
        ->and(BackupType::Logical->supports(DatabaseEngine::Percona))->toBeTrue()
        ->and(BackupType::Snapshot->supports(DatabaseEngine::Valkey))->toBeTrue()
        ->and(BackupType::Logical->supports(DatabaseEngine::Valkey))->toBeFalse()
        ->and(BackupType::Physical->supports(DatabaseEngine::Postgres))->toBeFalse();

    // supports() and tool() must agree, or one of them is lying: anything
    // supported has a tool, and anything unsupported refuses.
    foreach (BackupType::cases() as $type) {
        foreach (DatabaseEngine::cases() as $engine) {
            if ($type->supports($engine)) {
                expect($type->tool($engine))->not->toBe('');
            } else {
                expect(fn () => $type->tool($engine))->toThrow(InvalidArgumentException::class);
            }
        }
    }
});

it('knows which copies a new database can start from', function (): void {
    // A physical copy and a snapshot are a datadir; a logical dump is a stream
    // of statements that needs a running server, which is an import into a
    // database that already exists rather than a way to create one.
    expect(BackupType::Physical->canSeedNewInstance())->toBeTrue()
        ->and(BackupType::Snapshot->canSeedNewInstance())->toBeTrue()
        ->and(BackupType::Logical->canSeedNewInstance())->toBeFalse();
});

it('knows which copy a point-in-time restore can replay onto', function (): void {
    // Only a physical base has an LSN to resume from.
    expect(BackupType::Physical->isPitrBase())->toBeTrue()
        ->and(BackupType::Logical->isPitrBase())->toBeFalse()
        ->and(BackupType::Snapshot->isPitrBase())->toBeFalse();
});

it('needs a bucket for everything except a snapshot', function (): void {
    // A volume snapshot is fulfilled by the CSI driver inside the cluster, so it
    // needs no credentials — which is why storage is optional on a BackupSpec.
    expect(BackupType::Physical->usesObjectStorage())->toBeTrue()
        ->and(BackupType::Logical->usesObjectStorage())->toBeTrue()
        ->and(BackupType::Snapshot->usesObjectStorage())->toBeFalse();

    // Running as a Job and needing a bucket are the same question today, and
    // the kind follows from it.
    foreach (BackupType::cases() as $type) {
        expect($type->runsAsJob())->toBe($type->usesObjectStorage())
            ->and($type->kind())->toBe($type->runsAsJob() ? 'Job' : 'VolumeSnapshot');
    }
});

it('gives the object it writes an extension that matches the tool', function (): void {
    expect(BackupType::Physical->extension(DatabaseEngine::Percona))->toBe('xbstream')
        ->and(BackupType::Physical->extension(DatabaseEngine::Valkey))->toBe('rdb')
        ->and(BackupType::Logical->extension(DatabaseEngine::Percona))->toBe('sql.gz')
        // A snapshot writes no object, so it has no extension to give.
        ->and(BackupType::Snapshot->extension(DatabaseEngine::Percona))->toBe('');
});

it('labels every type for a person', function (): void {
    foreach (BackupType::cases() as $type) {
        expect($type->label())->not->toBe('');
    }

    expect(BackupType::Snapshot->label())->toBe('Volume snapshot');
});

it('knows the scheme a connection URL for each engine starts with', function (): void {
    expect(ConnectionSource::scheme('postgres'))->toBe('postgresql')
        ->and(ConnectionSource::scheme('valkey'))->toBe('redis')
        ->and(ConnectionSource::scheme('percona'))->toBe('mysql');
});
