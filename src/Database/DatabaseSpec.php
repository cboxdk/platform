<?php

declare(strict_types=1);

namespace Cbox\Platform\Database;

/**
 * The compiler's input for a managed database — a resolved, immutable snapshot
 * of intent. Pure function in, manifests out.
 */
readonly class DatabaseSpec
{
    /**
     * @param  bool  $suspended  the customer stopped it: compute off, storage kept
     * @param  bool  $scaleToZero  may idle down and wake on connect (Cortex-scheduled engines)
     * @param  string|null  $password  for engines with no operator to mint credentials
     * @param  RestoreSpec|null  $restore  the copy this instance starts from — a restore is always a new database
     */
    public function __construct(
        public string $databaseId,
        public string $organizationId,
        public string $namespace,
        public string $name,
        public DatabaseEngine $engine,
        public string $version,
        public int $instances,
        public string $storageSize,
        public ?string $backupSchedule = null,
        public bool $suspended = false,
        public bool $scaleToZero = false,
        public int $idleTimeoutSeconds = 300,
        public ?string $password = null,
        public ?RestoreSpec $restore = null,
        /**
         * Where this database's backups go, resolved here rather than in the
         * compiler. A compiler that looks up an organization's credential is
         * no longer a pure function of its intent — it hits Eloquent, and its
         * golden test needs a database. Same reason `restore` is resolved here
         * and `ClusterSpec` carries the provider token it was handed.
         */
        public ?BackupStorage $backupStorage = null,
    ) {}
}
