<?php

declare(strict_types=1);

namespace Cbox\Platform\Database;

/**
 * What kind of copy a backup is. These are not ranked — they solve different
 * problems, so a Backup carries its type explicitly rather than Cortex choosing
 * on the customer's behalf:
 *
 * - Physical is the whole instance, byte-level, and the base that point-in-time
 *   recovery replays binlogs onto. For Percona that is XtraBackup streamed to
 *   object storage; Valkey needs none of XtraBackup, because its own RDB
 *   persistence on the volume already is the byte-level copy.
 * - Logical is a portable dump — slower, larger, but readable by any engine of
 *   the same family and selectable down to a table. Valkey has no logical form:
 *   the RDB file is already the portable one.
 * - Snapshot is the storage backend's own CSI VolumeSnapshot. Fastest by far,
 *   but crash-consistent unless quiesced first and tied to that backend, so it
 *   never leaves the cluster and cannot be a PITR base.
 */
enum BackupType: string
{
    case Physical = 'physical';
    case Logical = 'logical';
    case Snapshot = 'snapshot';

    public function label(): string
    {
        return match ($this) {
            self::Physical => 'Physical',
            self::Logical => 'Logical',
            self::Snapshot => 'Volume snapshot',
        };
    }

    /**
     * Only a physical base backup can be replayed onto — a logical dump has no
     * LSN to resume from and a volume snapshot is crash-consistent.
     */
    public function isPitrBase(): bool
    {
        return $this === self::Physical;
    }

    /**
     * Physical and logical backups are written to object storage by a Job;
     * a volume snapshot is an object the CSI driver fulfils inside the cluster,
     * so it needs no bucket and no credentials.
     */
    public function usesObjectStorage(): bool
    {
        return $this !== self::Snapshot;
    }

    /** Whether this type compiles to a Job (and so has an observable phase). */
    public function runsAsJob(): bool
    {
        return $this->usesObjectStorage();
    }

    /**
     * Whether a *new* database can be created directly from this copy.
     *
     * A physical copy and a volume snapshot both are a datadir, so an instance
     * can start on one. A logical dump is a stream of statements: it needs a
     * running server to be replayed into, which is an import into a database
     * that already exists — not a way to bring one into being.
     */
    public function canSeedNewInstance(): bool
    {
        return $this !== self::Logical;
    }

    /** The Kubernetes kind this type compiles to. */
    public function kind(): string
    {
        return $this === self::Snapshot ? 'VolumeSnapshot' : 'Job';
    }

    /**
     * The tool that takes this kind of copy of this engine. A combination with
     * no tool is refused rather than approximated with another one.
     */
    public function tool(DatabaseEngine $engine): string
    {
        return match ([$this, $engine]) {
            [self::Physical, DatabaseEngine::Percona] => 'xtrabackup',
            [self::Logical, DatabaseEngine::Percona] => 'mysqldump',
            [self::Physical, DatabaseEngine::Valkey] => 'rdb-copy',
            [self::Snapshot, DatabaseEngine::Percona],
            [self::Snapshot, DatabaseEngine::Valkey] => 'csi-snapshot',
            default => throw new \InvalidArgumentException(
                "A {$this->value} backup of {$engine->value} has no tool: {$this->unsupportedReason($engine)}",
            ),
        };
    }

    public function supports(DatabaseEngine $engine): bool
    {
        if (! $engine->isCortexScheduled()) {
            return false;
        }

        return ! ($engine === DatabaseEngine::Valkey && $this === self::Logical);
    }

    /** The file extension of the object this backup writes, for its storage key. */
    public function extension(DatabaseEngine $engine): string
    {
        return match ($this) {
            // xbcloud writes a directory of numbered parts under the key.
            self::Physical => $engine === DatabaseEngine::Percona ? 'xbstream' : 'rdb',
            self::Logical => 'sql.gz',
            self::Snapshot => '',
        };
    }

    private function unsupportedReason(DatabaseEngine $engine): string
    {
        if (! $engine->isCortexScheduled()) {
            return 'CloudNativePG owns Postgres backups.';
        }

        return 'Valkey persists to RDB, which is already the portable form.';
    }
}
