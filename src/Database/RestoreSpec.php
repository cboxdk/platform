<?php

declare(strict_types=1);

namespace Cbox\Platform\Database;

/**
 * The copy a new database starts from — resolved from a Backup at compile time
 * so the compiler never touches Eloquent.
 *
 * A restore is always a new instance: this describes what to load *before the
 * engine accepts its first connection*, never something done to a database that
 * is already serving.
 */
readonly class RestoreSpec
{
    /**
     * @param  string|null  $pointInTime  PITR target as 'Y-m-d H:i:s' UTC; null restores the base backup as it stands
     */
    /**
     * @param  string  $sourceName  the database this copy was taken FROM
     */
    public function __construct(
        public string $backupId,
        public BackupType $type,
        public string $storageKey,
        public ?BackupStorage $storage,
        public ?string $pointInTime = null,
        public string $sourceName = '',
    ) {}
}
