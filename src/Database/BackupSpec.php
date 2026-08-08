<?php

declare(strict_types=1);

namespace Cbox\Platform\Database;

use Cbox\Platform\Capability\BackupCatalog;
use LogicException;

/**
 * The compiler's input for one backup — a resolved, immutable snapshot. Customer
 * intent (which database, what kind of copy) plus the cell facts the compiler
 * needs (bucket, endpoint, image), so the compiler itself reads no config.
 *
 * The same value object describes a scheduled backup: {@see self::scheduleFor()}
 * builds the recurring physical backup a database's `backup_schedule` means, and
 * the compiler emits a CronJob instead of a Job for it.
 */
readonly class BackupSpec
{
    /**
     * @param  string  $storageKey  where this copy lands — a bucket key, or a VolumeSnapshot name
     * @param  string|null  $schedule  cron expression when this is the recurring backup, null for a one-off
     */
    public function __construct(
        public string $backupId,
        public string $organizationId,
        public string $databaseId,
        public string $databaseName,
        public string $namespace,
        public DatabaseEngine $engine,
        public BackupType $type,
        public string $storageKey,
        public string $image,
        public ?BackupStorage $storage = null,
        public ?string $schedule = null,
        public ?string $snapshotClass = null,
    ) {}

    /**
     * The object-storage facts for a database whose backups are taken by an
     * OPERATOR rather than by a Cortex-built engine image.
     *
     * Postgres is the case: CloudNativePG runs barman inside its own instance
     * pods, so there is no Cortex image to name — `image()` rightly refuses to
     * invent one, since a Postgres engine image does not exist. What the CNPG
     * compiler still needs is the bucket, the endpoint and the credential
     * Secret, which are exactly the same ones the engine backups use, so the
     * two paths cannot drift into two spellings of one credential.
     *
     * `image` is deliberately empty rather than a placeholder: nothing in this
     * path reads it, and a plausible-looking value would be a lie that some
     * later caller could act on.
     */
    public static function forOperatorBackups(DatabaseSpec $database, BackupStorage $storage, BackupCatalog $catalog): self
    {
        return new self(
            backupId: 'operator',
            organizationId: $database->organizationId,
            databaseId: $database->databaseId,
            databaseName: $database->name,
            namespace: $database->namespace,
            engine: $database->engine,
            type: BackupType::Physical,
            storageKey: self::scheduledKeyPrefix($database, $catalog),
            image: '',
            storage: $storage,
            schedule: $database->backupSchedule,
        );
    }

    /**
     * The recurring physical backup a database's schedule means. Only physical:
     * a schedule exists to keep a restorable base current, and that is the copy
     * point-in-time recovery replays onto.
     */
    public static function scheduleFor(DatabaseSpec $database, BackupCatalog $catalog): self
    {
        if ($database->backupSchedule === null) {
            throw new LogicException("Database [{$database->databaseId}] carries no backup schedule.");
        }

        return new self(
            backupId: 'scheduled',
            organizationId: $database->organizationId,
            databaseId: $database->databaseId,
            databaseName: $database->name,
            namespace: $database->namespace,
            engine: $database->engine,
            type: BackupType::Physical,
            storageKey: self::scheduledKeyPrefix($database, $catalog),
            image: $catalog->image($database->engine, $database->version),
            // The spec's own storage, not a fresh lookup. Resolving the
            // organization's credential again here is what made a compiler
            // reach for Eloquent; the value is the same one the spec was built
            // with, and a schedule cannot exist without it.
            storage: $database->backupStorage ?? throw new LogicException(
                "Database [{$database->databaseId}] carries a backup schedule but no storage."
            ),
            schedule: $database->backupSchedule,
        );
    }

    /**
     * The restore's view of the same facts, so the database compiler can emit
     * the one Secret its init container reads without knowing a bucket exists.
     */
    public static function forRestore(DatabaseSpec $database, RestoreSpec $restore, BackupCatalog $catalog): self
    {
        return new self(
            backupId: $restore->backupId,
            organizationId: $database->organizationId,
            databaseId: $database->databaseId,
            databaseName: $database->name,
            namespace: $database->namespace,
            engine: $database->engine,
            type: $restore->type,
            storageKey: $restore->storageKey,
            image: $catalog->image($database->engine, $database->version),
            storage: $restore->storage,
        );
    }

    /**
     * The key prefix a scheduled run writes under. Every run needs its own key,
     * and the only clock the CronJob can trust is its own, so the timestamp is
     * expanded in the container at run time rather than baked in at compile time.
     */
    public static function scheduledKeyPrefix(DatabaseSpec $database, BackupCatalog $catalog): string
    {
        // Under the organization's own prefix — the same scope the tenant's
        // access key is restricted to, so a compiled key a tenant credential
        // could not write to is impossible to produce.
        return $catalog->prefix($database->organizationId).'/'.$database->databaseId;
    }
}
