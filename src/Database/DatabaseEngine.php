<?php

declare(strict_types=1);

namespace Cbox\Platform\Database;

/**
 * The database engines Cortex offers. The distinction that matters to the
 * platform is not the query language — it is who schedules the pods:
 *
 * - Postgres runs under CloudNativePG. The operator owns the instance pod spec,
 *   so Cortex cannot place a snapshotting runtime under it; suspending is the
 *   operator's hibernation and waking is a PostgreSQL start.
 * - Valkey and Percona Server are scheduled by Cortex itself, so they can carry
 *   a snapshotting runtime class and wake from a checkpoint in ~100ms.
 *
 * Percona Server rather than MySQL or MariaDB: it is a true drop-in for MySQL
 * where MariaDB has diverged, and its backup tool (XtraBackup) is the original
 * rather than a fork. Measured checkpointing cleanly with data intact.
 */
enum DatabaseEngine: string
{
    case Postgres = 'postgres';
    case Valkey = 'valkey';
    case Percona = 'percona';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Postgres;
    }

    /**
     * True when Cortex schedules the workload itself and therefore controls the
     * pod spec — the precondition for the warm-restore tier.
     */

    /**
     * Whether this engine replays from a write-ahead log the platform already
     * archives — which is what makes point-in-time recovery answerable.
     *
     * True only for Postgres: CloudNativePG archives WAL continuously through
     * barman, and that archive is the replay log. The others would need the
     * binlog archiver that does not ship, so a target time is refused for them
     * rather than accepted and quietly ignored.
     */
    public function replaysFromWal(): bool
    {
        return $this === self::Postgres;
    }

    public function isCortexScheduled(): bool
    {
        return $this !== self::Postgres;
    }

    /** Whether the engine needs a generated password to be usable. */
    public function needsPassword(): bool
    {
        return $this === self::Percona;
    }

    public function defaultVersion(): string
    {
        return match ($this) {
            self::Postgres => '16',
            self::Valkey => '8',
            self::Percona => '8.0',
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::Postgres => 5432,
            self::Valkey => 6379,
            self::Percona => 3306,
        };
    }
}
