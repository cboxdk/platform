<?php

declare(strict_types=1);

namespace Cbox\Platform\Contracts;

/**
 * The node-side runtime that checkpoints an idle workload and restores it on the
 * next connection — the warm tier of scale-to-zero.
 *
 * Compilers depend on this contract rather than on any one runtime's vocabulary.
 * The PRD keeps the mechanism deliberately swappable: the customer-facing
 * primitive is running/suspended/resuming, and a cell offering no snapshot
 * runtime simply falls back to a cold start. Each runtime shapes the pod
 * differently — one wants a RuntimeClass and annotations, another cooperates
 * with our own init process through its environment — so the contract covers all
 * three and lets an implementation return nothing for the parts it does not use.
 */
interface SnapshotRuntime
{
    /** Whether this cell can snapshot at all. */
    public function isAvailable(): bool;

    /**
     * The RuntimeClass to schedule the pod under, or null when the runtime needs
     * no separate class (for example one that cooperates with our own PID 1).
     */
    public function runtimeClassName(): ?string;

    /**
     * Pod annotations describing what to watch and when to checkpoint.
     *
     * @param  string  $containerName  the container whose process is snapshotted
     * @param  int  $port  the port whose first connection triggers a restore
     * @param  int  $idleTimeoutSeconds  how long it may idle before checkpointing
     * @return array<string, string>
     */
    public function annotations(string $containerName, int $port, int $idleTimeoutSeconds): array;

    /**
     * Environment the workload itself needs to be snapshot-safe. Kept separate
     * from annotations because these are read by the process, not the runtime —
     * and they must win over anything the customer set, or the checkpoint fails.
     *
     * @return array<string, string>
     */
    public function environment(): array;
}
