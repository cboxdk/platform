<?php

declare(strict_types=1);

namespace Cbox\Platform\Runtime;

use Cbox\Platform\Contracts\SnapshotRuntime;

/**
 * The third-party snapshot runtime Cortex ships today: a containerd shim that
 * checkpoints an idle container's process with CRIU and restores it when a
 * connection arrives, measured at ~100ms against ~4s for a cold start.
 *
 * Everything specific to it — its RuntimeClass name and its annotation
 * vocabulary — is confined to this one class, so replacing it is one new
 * implementation of {@see SnapshotRuntime} rather than edits across every
 * compiler.
 *
 * The two environment keys are not this runtime's invention; they are what CRIU
 * needs from the workload itself. A Go process with Multipath TCP enabled opens
 * a socket CRIU cannot dump, and an OPcache JIT buffer leaves an RWX region CRIU
 * cannot inject its dump parasite through. Both are forced on regardless of what
 * the customer set, because the checkpoint fails outright without them.
 */
class ZeropodSnapshotRuntime implements SnapshotRuntime
{
    public function __construct(private readonly string $runtimeClassName) {}

    public function isAvailable(): bool
    {
        return $this->runtimeClassName !== '';
    }

    public function runtimeClassName(): ?string
    {
        return $this->isAvailable() ? $this->runtimeClassName : null;
    }

    public function annotations(string $containerName, int $port, int $idleTimeoutSeconds): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return [
            'zeropod.ctrox.dev/container-names' => $containerName,
            'zeropod.ctrox.dev/ports-map' => $containerName.'='.$port,
            'zeropod.ctrox.dev/scaledown-duration' => $idleTimeoutSeconds.'s',
        ];
    }

    public function environment(): array
    {
        return [
            'GODEBUG' => 'multipathtcp=0',
            'PHP_OPCACHE_JIT' => 'off',
        ];
    }
}
