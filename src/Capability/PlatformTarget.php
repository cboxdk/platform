<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

use Cbox\Platform\Contracts\SnapshotRuntime;
use Cbox\Platform\Runtime\NoSnapshotRuntime;

/**
 * What the cluster being compiled for can actually do.
 *
 * ONE TYPED OBJECT, NOT A BRANCH. The compiler must never ask which product it
 * is running inside — `if ($local)` is how two consumers stop meaning the same
 * thing by "a Cbox application". Where two targets genuinely differ, the
 * difference is a value here, and it is as testable as any other input.
 *
 * ONLY WHAT IS REAL. Every member below corresponds to something the compiler
 * already branches on or reads. Capabilities are added when a second target
 * actually differs, not in anticipation of one — a flag nothing reads is a
 * switch that reports itself on and does nothing.
 *
 * The defaults reproduce Cbox Cortex's production behaviour exactly, which is
 * what the golden manifests are compiled against.
 */
readonly class PlatformTarget
{
    public function __construct(
        /**
         * The node-side runtime that checkpoints an idle workload and restores
         * it on the next connection. Its absence is not an error: a target with
         * no snapshot runtime falls back to a cold start, which is a different
         * compiled shape rather than a degraded one.
         */
        public SnapshotRuntime $snapshotRuntime = new NoSnapshotRuntime,
        public HttpAutoscaler $httpAutoscaler = new HttpAutoscaler,
        public BackupCatalog $backups = new BackupCatalog,
        public CustomerAccess $customerAccess = new CustomerAccess,
    ) {}
}
