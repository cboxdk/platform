<?php

declare(strict_types=1);

namespace Cbox\Platform\Contracts;

use Cbox\Platform\Database\BackupSpec;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\RestoreSpec;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;

/**
 * Backup intent → the objects that take the copy, and back again. Pure and
 * deterministic like the workload compilers — golden-tested, byte-stable.
 *
 * Only the engines Cortex schedules itself reach here. Postgres is refused
 * rather than approximated: CloudNativePG compiles its own ScheduledBackup and
 * owns that data path end to end.
 */
interface BackupCompiler
{
    /**
     * Point-in-time recovery needs continuous binlog archiving, and that
     * archiver — step 3 of the design's order of work — is not written: nothing
     * in cbox-init ships a binlog anywhere. A restore that accepted a target
     * time would find an empty prefix, replay nothing, and hand back the base
     * backup while claiming to be the requested moment. Refusing is the honest
     * answer until the archiver exists, and it lives on the contract so every
     * layer that can be handed a target time refuses it with the same words.
     */
    /**
     * For the engines Cortex schedules itself. Postgres is NOT one of them —
     * CloudNativePG archives WAL continuously, and WAL is the replay log point-
     * in-time recovery needs, so the refusal below would be blocking a
     * capability that exists rather than one that does not.
     */
    public const PITR_UNIMPLEMENTED = 'Point-in-time recovery is not available for this engine: it needs '
        .'continuous binlog archiving and no archiver ships yet. Restore the backup as it stands.';

    /** A one-off backup: the Secret it reads credentials from, plus a Job or VolumeSnapshot. */
    public function compile(BackupSpec $spec): ManifestSet;

    /** The recurring backup a database's schedule means: the same Secret, plus a CronJob. */
    public function compileSchedule(BackupSpec $spec): ManifestSet;

    /**
     * The Job that removes an object-storage copy. Deleting a backup deletes the
     * bytes, and only the customer's cluster holds the identity that can.
     */
    public function compilePrune(BackupSpec $spec): ManifestSet;

    /**
     * The object-storage identity every backup path reads, as a Secret in the
     * customer's namespace — emitted by whichever path needs it first (a
     * schedule, a one-off run, or a database restoring from a copy).
     */
    public function credentials(BackupSpec $spec): Manifest;

    /**
     * The init container that loads a copy into a *new* database's volume before
     * the engine starts, or null when the copy is a volume snapshot (the CSI
     * driver fills the volume, so nothing needs to run).
     *
     * @return array<string, mixed>|null
     */
    public function restoreInitContainer(RestoreSpec $restore, DatabaseEngine $engine, string $version, string $databaseName): ?array;

    /**
     * The dataSource a restored database's volume claim carries when the copy is
     * a volume snapshot, or null when the copy has to be fetched instead.
     *
     * @return array<string, string>|null
     */
    public function restoreVolumeSource(RestoreSpec $restore): ?array;
}
